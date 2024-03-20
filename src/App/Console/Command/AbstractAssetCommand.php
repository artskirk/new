<?php

namespace Datto\App\Console\Command;

use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Abstract asset command with utility functions for validation and retrieving assets
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
abstract class AbstractAssetCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AssetService */
    protected $assetService;

    /** @var  CommandValidator */
    protected $commandValidator;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct();

        $this->commandValidator = $commandValidator;
        $this->assetService = $assetService;
    }

    /**
     * @param InputInterface $input
     * @return \Datto\Asset\Asset[]
     */
    protected function getAssets(InputInterface $input)
    {
        $assetName = $input->getOption('asset');
        $assetType = $input->getOption('type');
        $assetDevice = $input->getOption('device');

        if (isset($assetName)) {
            $assets = array($this->assetService->get($assetName));
        } elseif (isset($assetType)) {
            $assets = $this->assetService->getAll($assetType);
        } else {
            $assets = $this->assetService->getAll();
            if (isset($assetDevice)) {
                $assetsForDevice = [];
                foreach ($assets as $asset) {
                    $deviceId = $asset->getOriginDevice()->getDeviceId();
                    if (($assetDevice == $deviceId)) {
                        $assetsForDevice[] = $asset;
                    }
                }
                $assets = $assetsForDevice;
            }
        }

        return $assets;
    }

    /**
     * @param InputInterface $input
     */
    protected function validateAsset(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $this->hasSingleAssetOption($input),
            new Assert\IsTrue(),
            'One and only one of the affected asset options (--asset, --type, or --all) must be set.'
        );

        if ($input->hasOption('asset')) {
            $this->commandValidator->validateValue(
                $input->getOption('asset'),
                new Assert\Regex(array('pattern' => "~^[a-zA-Z0-9._-]+$~")),
                'Asset name must contain only letters, digits, and these special characters: -_.'
            );
        }

        if ($input->hasOption('type') && $input->getOption('type') !== null) {
            $this->commandValidator->validateValue(
                $this->hasRealAssetType($input),
                new Assert\IsTrue(),
                "Asset type is not valid"
            );
        }
    }

    private function hasSingleAssetOption(InputInterface $input): bool
    {
        $assetName = $input->getOption('asset');
        $asset = isset($assetName);
        $assetType = $input->getOption('type');
        $type = isset($assetType);
        $all = $input->getOption('all');

        $assetOnly = $asset && !$type && !$all;
        $typeOnly = $type && !$asset && !$all;
        $allOnly = $all && !$asset && !$type;

        return $assetOnly || $allOnly || $typeOnly;
    }

    private function hasRealAssetType(InputInterface $input)
    {
        $type = $input->getOption('type');
        try {
            AssetType::toClassName($type);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
