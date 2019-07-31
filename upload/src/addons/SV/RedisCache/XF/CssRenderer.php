<?php


namespace SV\RedisCache\XF;

use SV\RedisCache\RawResponseText;
use SV\RedisCache\Redis;
use XF\App;
use XF\Template\Templater;
use XF\Http\ResponseStream;

class CssRenderer extends XFCP_CssRenderer
{
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
        $css =  @\gzdecode($output);

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

    /**
     * @param array $templates
     * @return array
     */
    protected function getIndividualCachedTemplates(array $templates)
    {
        // individual css template cache causes a thundering herd of writes, and is cached outside the application stack

        return parent::getIndividualCachedTemplates($templates);
    }

    /**
     * @param string $title
     * @param string $output
     */
    public function cacheTemplate($title, $output)
    {


        parent::cacheTemplate($title, $output);
    }
}
