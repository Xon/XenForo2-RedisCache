<?php


namespace SV\RedisCache\XF;


use SV\RedisCache\Redis;
use XF\Http\ResponseStream;

class CssRenderer extends XFCP_CssRenderer
{
    protected $echoUncompressedData = false;

    public function setForceRawCache($value)
    {
        $this->echoUncompressedData = $value;
    }

    /**
     * @param $output
     * @param $length
     * @return ResponseStream
     */
    protected function wrapOutput($output, $length)
    {
        return new ResponseStream($output, $length);
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

        if ($this->echoUncompressedData)
        {
            return $this->wrapOutput($output, $length);
        }

        // client doesn't support compression, so decompress before sending it
        return @\gzdecode($output);
    }

    protected function cacheFinalOutput(array $templates, $output)
    {
        $cache = $this->cache;
        if (!$this->allowCached || !($cache instanceof Redis) || !($credis = $cache->getCredis(false)))
        {
            parent::cacheFinalOutput($templates, $output);
            return;
        }

        $output = strval($output);

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $credis = $cache->getCredis(false);
        $credis->hMSet($key, [
            'o' => \gzencode($output),
            'l' => strlen($output),
        ]);
    }
}
