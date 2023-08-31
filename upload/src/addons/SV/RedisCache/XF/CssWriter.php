<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF;

use XF\Http\ResponseStream;

class CssWriter extends XFCP_CssWriter
{
    public function run(array $templates, $styleId, $languageId, $validation = null)
    {
        $request = \XF::app()->request();
        if (!$styleId && !$languageId && $validation === '')
        {
            // work-around for buggy-bots
            $input = $request->filter([
                'amp;s' => 'uint',
                'amp;l' => 'uint',
                'amp;k' => 'str',
            ]);
            $styleId = $input['amp;s'];
            $languageId = $input['amp;l'];
            $validation = $input['amp;k'];
        }
        else if (!$styleId || !$languageId)
        {
            $tmp = $request->filter([
                's' => 'str',
                'l' => 'str',
            ]);
            if (!$styleId && !\is_numeric($tmp['s']) || !$languageId && !\is_numeric($tmp['l']))
            {
                $response = $this->getResponse('');
                $response->httpCode(404);

                return $response;
            }
        }

        /** @var CssRenderer $renderer */
        $renderer = $this->renderer;
        $renderer->setIncludeCharsetInOutput(true);
        $renderer->setInputModifiedDate($this->app->request()->filter('d','uint'));

        $showDebugOutput = (\XF::$debugMode && $request->get('_debug'));
        if (!$showDebugOutput && \strpos($request->getServer('HTTP_ACCEPT_ENCODING', ''), 'gzip') !== false)
        {
            $renderer->setForceRawCache(true);
        }

        return parent::run($templates, $styleId, $languageId, $validation);
    }

    public function finalizeOutput($output)
    {
        if ($output instanceof ResponseStream || \strlen($output) === 0)
        {
            return $output;
        }
        if (\stripos($output, CssRenderer::$charsetBits) === 0)
        {
            return $output;
        }

        return parent::finalizeOutput($output);
    }

    public function getResponse($output)
    {
        $force404Output = \strlen($output) === 0;
        if ($force404Output)
        {
            $this->renderer->setAllowCached(false);
        }
        $response = parent::getResponse($output);
        if ($output instanceof ResponseStream)
        {
            $response->compressIfAble(false);
            try
            {
                @ini_set('zlib.output_compression', 'Off');
            }
            catch (\Throwable $e) {}
            $response->header('content-encoding', 'gzip');
            $response->header('vary', 'Accept-Encoding');
        }
        if ($force404Output)
        {
            $response->httpCode(404);
        }

        return $response;
    }
}
