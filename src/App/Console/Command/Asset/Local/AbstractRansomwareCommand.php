<?php

namespace Datto\App\Console\Command\Asset\Local;

use Datto\App\Console\Command\AbstractAssetCommand;
use Symfony\Component\Console\Input\InputInterface;

abstract class AbstractRansomwareCommand extends AbstractAssetCommand
{
    protected function getAssets(InputInterface $input)
    {
        $assetName = $input->getOption('asset');
        $assetType = $input->getOption('type');

        if (!is_null($assetName)) {
            $assets = [$this->assetService->get($assetName)];
        } elseif (!is_null($assetType)) {
            $assets = $this->assetService->getAll($assetType);
        } else {
            $assets = $this->assetService->getAll();
        }

        return $assets;
    }
}
