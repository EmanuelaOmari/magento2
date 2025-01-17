<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Asset;

use Magento\Framework\Filesystem;
use Magento\Framework\View\Asset\Bundle\Manager;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Asset\File\FallbackContext;

/**
 * Bundle model
 */
class Bundle
{
    /**
     * @var array
     */
    protected $assets = [];

    /** @var  Bundle\Config */
    protected $bundleConfig;

    /**
     * @var array
     */
    protected $bundleNames = [
        Manager::ASSET_TYPE_JS => 'jsbuild',
        Manager::ASSET_TYPE_HTML => 'text'
    ];

    /**
     * @var array
     */
    protected $content = [];

    /**
     * @var Minification
     */
    protected $minification;

    /**
     * @param Filesystem $filesystem
     * @param Bundle\ConfigInterface $bundleConfig
     * @param Minification $minification
     */
    public function __construct(
        Filesystem $filesystem,
        Bundle\ConfigInterface $bundleConfig,
        Minification $minification
    ) {
        $this->filesystem = $filesystem;
        $this->bundleConfig = $bundleConfig;
        $this->minification = $minification;
    }

    /**
     * @param LocalInterface $asset
     * @return void
     */
    public function addAsset(LocalInterface $asset)
    {
        $this->init($asset);
        $this->add($asset);
    }

    /**
     * Add asset into array
     *
     * @param LocalInterface $asset
     * @return void
     */
    protected function add(LocalInterface $asset)
    {
        $partIndex = $this->getPartIndex($asset);
        $parts = &$this->assets[$this->getContextCode($asset)][$asset->getContentType()];
        if (!isset($parts[$partIndex])) {
            $parts[$partIndex]['assets'] = [];
            $parts[$partIndex]['space'] = $this->getMaxPartSize($asset);
        }
        $parts[$partIndex]['assets'][$this->getAssetKey($asset)] = $asset;
        $parts[$partIndex]['space'] -= $this->getAssetSize($asset);
    }

    /**
     * @param LocalInterface $asset
     * @return void
     */
    protected function init(LocalInterface $asset)
    {
        $contextCode = $this->getContextCode($asset);
        $type = $asset->getContentType();

        if (!isset($this->assets[$contextCode][$type])) {
            $this->assets[$contextCode][$type] = [];
        }
    }

    /**
     * @param LocalInterface $asset
     * @return string
     */
    protected function getContextCode(LocalInterface $asset)
    {
        /** @var FallbackContext $context */
        $context = $asset->getContext();
        return $context->getAreaCode() . ':' . $context->getThemePath() . ':' . $context->getLocale();
    }

    /**
     * @param LocalInterface $asset
     * @return int
     */
    protected function getPartIndex(LocalInterface $asset)
    {
        $parts = $this->assets[$this->getContextCode($asset)][$asset->getContentType()];

        $maxPartSize = $this->getMaxPartSize($asset);
        $assetSize = $this->getAssetSize($asset);
        $minSpace = $maxPartSize + 1;
        $minIndex = -1;
        if ($maxPartSize && count($parts)) {
            foreach ($parts as $partIndex => $part) {
                $space = $part['space'] - $assetSize;
                if ($space >= 0 && $space < $minSpace) {
                    $minSpace = $space;
                    $minIndex = $partIndex;
                }
            }
        }

        return ($maxPartSize != 0) ? ($minIndex >= 0) ? $minIndex : count($parts) : 0;
    }

    /**
     * @param LocalInterface $asset
     * @return int
     */
    protected function getMaxPartSize(LocalInterface $asset)
    {
        return $this->bundleConfig->getPartSize($asset->getContext());
    }

    /**
     * @param LocalInterface $asset
     * @return int
     */
    protected function getAssetSize(LocalInterface $asset)
    {
        return mb_strlen(json_encode(utf8_encode($asset->getContent()), JSON_UNESCAPED_SLASHES), 'utf-8') / 1024;
    }

    /**
     * Build asset key
     *
     * @param LocalInterface $asset
     * @return string
     */
    protected function getAssetKey(LocalInterface $asset)
    {
        $result = (($asset->getModule() == '') ? '' : $asset->getModule() . '/') . $asset->getFilePath();
        $result = $this->minification->addMinifiedSign($result);
        return $result;
    }

    /**
     * Prepare bundle for executing in js
     *
     * @param LocalInterface[] $assets
     * @return array
     */
    protected function getPartContent($assets)
    {
        $contents = [];
        foreach ($assets as $key => $asset) {
            $contents[$key] = utf8_encode($asset->getContent());
        }

        $partType = reset($assets)->getContentType();
        $content = json_encode($contents, JSON_UNESCAPED_SLASHES);
        $content = "require.config({\n" .
            "    config: {\n" .
            "        '" . $this->bundleNames[$partType] . "':" . $content . "\n" .
            "    }\n" .
            "});\n";

        return $content;
    }

    /**
     * @return string
     */
    protected function getInitJs()
    {
        return "require.config({\n" .
                "    bundles: {\n" .
                "        'mage/requirejs/static': [\n" .
                "            'jsbuild',\n" .
                "            'buildTools',\n" .
                "            'text',\n" .
                "            'statistician'\n" .
                "        ]\n" .
                "    },\n" .
                "    deps: [\n" .
                "        'jsbuild'\n" .
                "    ]\n" .
                "});\n";
    }

    /**
     * @return void
     */
    public function flush()
    {
        foreach ($this->assets as $types) {
            $this->save($types);
        }
        $this->assets = [];
        $this->content = [];
    }

    /**
     * @param array $types
     * @return void
     */
    protected function save($types)
    {
        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);

        $bundlePath = '';
        foreach ($types as $parts) {
            /** @var FallbackContext $context */
            $assetsParts = reset($parts);
            $context = reset($assetsParts['assets'])->getContext();
            $bundlePath = empty($bundlePath) ? $context->getPath() . Manager::BUNDLE_PATH : $bundlePath;
            $this->fillContent($parts, $context);
        }

        $this->content[max(0, count($this->content) - 1)] .= $this->getInitJs();

        foreach ($this->content as $partIndex => $content) {
            $dir->writeFile($this->minification->addMinifiedSign($bundlePath . $partIndex . '.js'), $content);
        }
    }

    /**
     * @param array $parts
     * @param FallbackContext $context
     * @return void
     */
    protected function fillContent($parts, $context)
    {
        $index = count($this->content) > 0 ? count($this->content) - 1 : 0 ;
        foreach ($parts as $part) {
            if (!isset($this->content[$index])) {
                $this->content[$index] = '';
            } elseif ($this->bundleConfig->isSplit($context)) {
                ++$index;
                $this->content[$index] = '';
            }
            $this->content[$index] .= $this->getPartContent($part['assets']);
        }
    }
}
