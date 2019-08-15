<?php


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
                'amp;s'   => 'uint',
                'amp;l'   => 'uint',
                'amp;k'   => 'str'
            ]);
            $styleId = $input['amp;s'];
            $languageId = $input['amp;l'];
            $validation = $input['amp;k'];
        }


        /** @var CssRenderer $renderer */
        $renderer = $this->renderer;
        $renderer->setIncludeCharsetInOutput(true);

        $showDebugOutput = (\XF::$debugMode && $request->get('_debug'));
        if (!$showDebugOutput && strpos($request->getServer('HTTP_ACCEPT_ENCODING', ''), 'gzip') !== false)
        {
            $renderer->setForceRawCache(true);
        }
        return parent::run($templates, $styleId, $languageId, $validation);
    }

    public function finalizeOutput($output)
    {
        if ($output instanceof ResponseStream)
        {
            return $output;
        }
        if (strpos($output,'@CHARSET') === 0)
        {
            return $output;
        }
        return parent::finalizeOutput($output);
    }

    public function getResponse($output)
    {
        $response = parent::getResponse($output);
        if ($output instanceof ResponseStream)
        {
            $response->compressIfAble(false);
            $response->header('content-encoding', 'gzip');
            $response->header('vary', 'Accept-Encoding');
        }
        return $response;
    }
}
