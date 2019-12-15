<?php


namespace SV\RedisCache\XF;

use SV\RedisCache\RawResponseText;
use SV\RedisCache\Redis;
use XF\App;
use XF\Template\Templater;
use XF\Http\ResponseStream;

class CssRenderer extends XFCP_CssRenderer
{
    const LESS_SHORT_CACHE_TIME     = 5 * 60;
    const TEMPLATE_SHORT_CACHE_TIME = 5 * 60;

    public function __construct(App $app, Templater $templater, \Doctrine\Common\Cache\CacheProvider $cache = null)
    {
        if ($cache === null)
        {
            $cache = \XF::app()->cache('css');
        }
        parent::__construct($app, $templater, $cache);
    }

    protected $echoUncompressedData = false;

    /**
     * @param bool $value
     */
    public function setForceRawCache($value)
    {
        $this->echoUncompressedData = $value;
    }

    protected $includeCharsetInOutput = false;

    /**
     * @param bool $value
     */
    public function setIncludeCharsetInOutput($value)
    {
        $this->includeCharsetInOutput = $value;
    }

    /**
     * @param $output
     * @param $length
     * @return ResponseStream
     */
    protected function wrapOutput($output, $length)
    {
        return new RawResponseText($output, $length);
    }

    protected function getFinalCachedOutput(array $templates)
    {
        $cache = $this->cache;
        if (!$this->allowCached || !($cache instanceof Redis) || !($credis = $cache->getCredis(false)))
        {
            return parent::getFinalCachedOutput($templates);
        }

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $credis = $cache->getCredis(true);
        $data = $credis->hGetAll($key);
        if (empty($data))
        {
            return false;
        }

        $output = $data['o']; // gzencoded
        $length = $data['l'];

        if (!$this->includeCharsetInOutput)
        {
            $this->echoUncompressedData = false;
        }

        if ($this->echoUncompressedData)
        {
            return $this->wrapOutput($output, $length);
        }

        // client doesn't support compression, so decompress before sending it
        $css = @\gzdecode($output);

        if (!$this->includeCharsetInOutput && strpos($css, static::$charsetBits) === 0)
        {
            // strip out the css header bits
            $css = substr($css, \strlen(static::$charsetBits));
        }

        return $css;
    }

    static $charsetBits = '@CHARSET "UTF-8";' . "\n\n";

    protected function cacheFinalOutput(array $templates, $output)
    {
        $cache = $this->cache;
        if (!$this->allowCached || !$this->allowFinalCacheUpdate || !($cache instanceof Redis) || !($credis = $cache->getCredis(false)))
        {
            parent::cacheFinalOutput($templates, $output);

            return;
        }

        $output = static::$charsetBits . strval($output);

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $credis = $cache->getCredis(false);
        $credis->hMSet($key, [
            'o' => \gzencode($output, 9),
            'l' => strlen($output),
        ]);
        $credis->expire($key, 3600);
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

        return $prefix . 'Cache_' . md5(
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

        $cache->save($key, $output, self::LESS_SHORT_CACHE_TIME);

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
        foreach($templates as $i => $template)
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
        $cache->save($key, $output, self::TEMPLATE_SHORT_CACHE_TIME);
    }
}
