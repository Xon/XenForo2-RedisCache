<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF;

use Doctrine\Common\Cache\CacheProvider;
use SV\RedisCache\RawResponseText;
use SV\RedisCache\Redis as RedisCache;
use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\App;
use XF\Template\Templater;
use function array_values;
use function count;
use function gzdecode;
use function gzencode;
use function is_array;
use function strlen;
use function trim;

class CssRenderer extends XFCP_CssRenderer
{
    /** @var int */
    public const LESS_SHORT_CACHE_TIME = 5 * 60;
    /** @var int */
    public const EMPTY_TEMPLATE_SHORT_CACHE_TIME = 1;
    /** @var int */
    public const TEMPLATE_SHORT_CACHE_TIME = 5 * 60;
    /** @var int */
    public const EMPTY_OUTPUT_CACHE_TIME = 1;
    /** @var int */
    public const OUTPUT_CACHE_TIME = 60 * 60;
    /** @var bool */
    protected $echoUncompressedData = false;
    /** @var int|null */
    protected $inputModifiedDate = null;

    public function __construct(App $app, Templater $templater, ?CacheProvider $cache = null)
    {
        if ($cache === null)
        {
            $cache = \XF::app()->cache('css');
        }
        parent::__construct($app, $templater, $cache);
    }

    public function setForceRawCache(bool $value)
    {
        $this->echoUncompressedData = $value;
    }

    /**
     * @return int|null
     */
    public function getInputModifiedDate()
    {
        return $this->inputModifiedDate;
    }

    public function setInputModifiedDate(?int $value = null)
    {
        $this->inputModifiedDate = $value;
    }

    protected function svWrapOutput(string $output, bool $length): RawResponseText
    {
        return new RawResponseText($output, $length);
    }

    /**
     * @param bool $allowReplica
     * @return array{0:RedisCache, 1: \Credis_Client}|null
     */
    protected function getCredis(bool $allowReplica = false): ?array
    {
        $cache = RedisRepo::get()->getRedisObj($this->cache);

        if (!$this->allowCached || $cache === null || !($credis = $cache->getCredis($allowReplica)))
        {
            return null;
        }

        return [$cache, $credis];
    }

    protected function filterValidTemplates(array $templates)
    {
        $templates = parent::filterValidTemplates($templates);

        if (count($templates) !== 0)
        {
            $date = $this->getInputModifiedDate();
            if ($date !== null)
            {
                $styleModifiedDate = $this->style->getLastModified();
                if ($date === 0 || $styleModifiedDate && $date > $styleModifiedDate)
                {
                    return [];
                }
            }
        }

        return $templates;
    }

    protected function getFinalCachedOutput(array $templates)
    {
        [$cache, $credis] = $this->getCredis(true);
        if ($credis === null)
        {
            return parent::getFinalCachedOutput($templates);
        }

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $data = $credis->hGetAll($key);
        if (!is_array($data))
        {
            return false;
        }

        $output = $data['o'] ?? null; // gz encoded data
        $length = $data['l'] ?? null;
        if ($output === null || $length === null)
        {
            return '';
        }

        if (!$length)
        {
            $this->echoUncompressedData = false;
        }

        if ($this->echoUncompressedData)
        {
            return $this->svWrapOutput($output, $length);
        }

        // client doesn't support compression, so decompress before sending it
        return strlen($output) !== 0 ? @gzdecode($output) : '';
    }

    public static $charsetBits = '@CHARSET "UTF-8";' . "\n\n";

    protected function cacheFinalOutput(array $templates, $output)
    {
        [$cache, $credis] = $this->getCredis(true);
        if ($credis === null)
        {
            parent::cacheFinalOutput($templates, $output);

            return;
        }

        $output = trim($output);
        $len = strlen($output);
        // cache a negative lookup; but do not prefix $charsetBits
        if ($len !== 0)
        {
            $output = static::$charsetBits . $output;
            $len = strlen($output);
        }

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $credis->hMSet($key, [
            'o' => $len > 0 ? gzencode($output, 9) : '',
            'l' => $len,
        ]);
        $credis->expire($key, $len === 0
            ? static::EMPTY_OUTPUT_CACHE_TIME
            : static::OUTPUT_CACHE_TIME);
    }

    /** @var null|array */
    protected $cacheElements = null;

    protected function getCacheKeyElements()
    {
        if ($this->cacheElements === null)
        {
            $this->cacheElements = parent::getCacheKeyElements();
        }

        return $this->cacheElements;
    }

    protected function getComponentCacheKey($prefix, $value)
    {
        $elements = $this->getCacheKeyElements();

        return $prefix . 'Cache_' . \md5(
                'text=' . $value
                . 'style=' . $elements['style_id']
                . 'modified=' . $elements['style_last_modified']
                . 'language=' . $elements['language_id']
                . $elements['modifier']
            );
    }

    public function parseLessColorFuncValue($value, $forceDebug = false)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            /** @noinspection PhpUndefinedMethodInspection */
            return parent::parseLessColorFuncValue($value, $forceDebug);
        }

        $key = $this->getComponentCacheKey('xfLessFunc', $value);
        $output = $cache->fetch($key);
        if ($output !== false)
        {
            return $output;
        }
        /** @noinspection PhpUndefinedMethodInspection */
        $output = parent::parseLessColorFuncValue($value, $forceDebug);

        $cache->save($key, $output, self::LESS_SHORT_CACHE_TIME);

        return $output;
    }

    public function parseLessColorValue($value)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            return parent::parseLessColorValue($value);
        }

        $key = $this->getComponentCacheKey('xfLessValue', $value);
        $output = $cache->fetch($key);
        if ($output !== false)
        {
            return $output;
        }

        $output = parent::parseLessColorValue($value);

        $cache->save($key, $output, static::LESS_SHORT_CACHE_TIME);

        return $output;
    }

    /**
     * @param array $templates
     * @return array
     */
    protected function getIndividualCachedTemplates(array $templates)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            return parent::getIndividualCachedTemplates($templates);
        }

        // individual css template cache causes a thundering herd of writes to xf_css_cache table

        $keys = [];
        foreach ($templates as $i => $template)
        {
            $keys[$i] = $this->getComponentCacheKey('xfCssTemplate', $template);
        }

        $results = [];
        $rawResults = $cache->fetchMultiple(array_values($keys));
        foreach ($templates as $i => $template)
        {
            $key = $keys[$i];
            if (isset($rawResults[$key]))
            {
                $output = $rawResults[$key];
                if ($output !== false)
                {
                    $results[$template] = $rawResults[$key];
                }
            }
        }

        return $results;
    }

    /**
     * @param string $title
     * @param string $output
     */
    public function cacheTemplate($title, $output)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            parent::cacheTemplate($title, $output);

            return;
        }

        $key = $this->getComponentCacheKey('xfCssTemplate', $title);
        $cache->save($key, $output, strlen($output) === 0
            ? static::EMPTY_TEMPLATE_SHORT_CACHE_TIME
            : static::TEMPLATE_SHORT_CACHE_TIME);
    }
}
