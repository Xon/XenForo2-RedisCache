<?php

namespace SV\RedisCache\Traits;

use function igbinary_serialize;
use function igbinary_unserialize;
use function is_callable;
use function serialize;
use function strtolower;
use function unserialize;

trait CacheSerializerTrait
{
    protected $hasIgBinary = false;
    protected $useIgBinary = false;
    protected $serializeStringFalse = 'b:0;';
    // binary data, filled in at run-time
    protected $igBinarySerializeStringFalse = '';
    // binary data, filled in at run-time
    protected $igBinarySerializeStringNull = '';

    /** @noinspection SpellCheckingInspection */
    protected function setupSerializer(array $options): void
    {
        $serializer = $options['serializer'] ?? '';
        if (is_callable('\igbinary_serialize') && is_callable('\igbinary_unserialize'))
        {
            $this->hasIgBinary = true;
            $this->useIgBinary = empty($serializer) || strtolower($serializer) === 'igbinary';
            $this->igBinarySerializeStringFalse = igbinary_serialize(false);
            $this->igBinarySerializeStringNull = igbinary_serialize(null);
        }
    }

    /**
     * @return mixed
     */
    protected function deserialize(string $serialized)
    {
        $useIgBinary = $this->useIgBinary;
        if ($useIgBinary)
        {
            // igbinary_unserialize can return null/false on deserialization failure (exact return type depends on php version)
            // to avoid confusion, just do a string comparison upfront
            if ($serialized === $this->igBinarySerializeStringFalse)
            {
                return false;
            }
            else if ($serialized === $this->igBinarySerializeStringNull)
            {
                return null;
            }

            $deserialized = @igbinary_unserialize($serialized);
            if ($deserialized !== false && $deserialized !== null)
            {
                return $deserialized;
            }
            // deserialization failed, fall through to php unserialize()
        }

        if ($serialized === $this->serializeStringFalse)
        {
            return false;
        }

        $deserialized = @unserialize($serialized);
        // on failure, attempt to use igbinary_unserialize if possible
        if ($this->hasIgBinary && !$useIgBinary && $deserialized === false)
        {
            $deserialized = @igbinary_unserialize($serialized);
        }

        return $deserialized;
    }

    /**
     * @param mixed $data
     */
    protected function serialize($data): string
    {
        // XF stores binary data as strings which causes issues using json for serialization
        return $this->useIgBinary ? @igbinary_serialize($data) : @serialize($data);
    }
}