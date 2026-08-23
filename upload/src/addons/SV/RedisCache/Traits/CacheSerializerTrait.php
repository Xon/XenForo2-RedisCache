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
    protected $useIgBinary = false;

    /** @noinspection SpellCheckingInspection */
    protected function setupSerializer(array $options): void
    {
        $serializer = $options['serializer'] ?? '';
        if (is_callable('\igbinary_serialize') && is_callable('\igbinary_unserialize'))
        {
            $this->useIgBinary = empty($serializer) || strtolower($serializer) === 'igbinary';
        }
    }

    /**
     * @return mixed
     */
    protected function deserialize(string $encodedData)
    {
        return $this->useIgBinary ? @igbinary_unserialize($encodedData) : @unserialize($encodedData);
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