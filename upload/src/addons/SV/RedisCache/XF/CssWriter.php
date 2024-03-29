<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF;

use XF\App;
use XF\CssRenderer;
use XF\Http\ResponseStream;
use function is_numeric;
use function is_string;
use function strlen;
use function strpos;

class CssWriter extends XFCP_CssWriter
{
    /** @var bool */
    protected $svForce404OnEmptyCss;

    public function __construct(App $app, CssRenderer $renderer)
    {
        parent::__construct($app, $renderer);

        $this->svForce404OnEmptyCss = (bool)\XF::config('svForce404OnEmptyCss');
    }

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
            if (!$styleId && !is_numeric($tmp['s']) || !$languageId && !is_numeric($tmp['l']))
            {
                $response = $this->getResponse('');
                $response->httpCode(404);

                return $response;
            }
        }

        /** @var \SV\RedisCache\XF\CssRenderer $renderer */
        $renderer = $this->renderer;
        $renderer->setInputModifiedDate($this->app->request()->filter('d','uint'));

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
        if (is_string($output) && strlen($output) === 0)
        {
            $this->renderer->setAllowCached(false);
            if ($this->svForce404OnEmptyCss)
            {
                return '';
            }
        }
        return parent::finalizeOutput($output);
    }

    public function getResponse($output)
    {
        $force404Output = $this->svForce404OnEmptyCss && strlen($output) === 0;
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
