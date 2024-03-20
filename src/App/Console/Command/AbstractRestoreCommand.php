<?php

namespace Datto\App\Console\Command;

use Datto\Asset\AssetService;
use Datto\Restore\RestoreService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Datto\App\Security\Constraints\AssetExists;

/**
 * Abstract restore command with utility functions for validation and retrieving assets
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
abstract class AbstractRestoreCommand extends Command
{
    /** @var AssetService */
    protected $assetService;

    /** @var RestoreService */
    protected $restoreService;

    /** @var  CommandValidator */
    protected $commandValidator;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService,
        RestoreService $restoreService
    ) {
        parent::__construct();

        $this->commandValidator = $commandValidator;
        $this->assetService = $assetService;
        $this->restoreService = $restoreService;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->validateArguments($input);
    }

    /**
     * @param InputInterface $input
     */
    protected function validateArguments(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $this->hasSingleRestoreOption($input),
            new Assert\IsTrue(),
            'One and only one of the affected restore options (--asset, --type, or --device) may be set.'
        );

        if ($input->hasOption('asset') && $input->getOption('asset') !== null) {
            $this->commandValidator->validateValue(
                $input->getOption('asset'),
                new AssetExists([]),
                'Asset does not exist.'
            );
        }
    }

    private function hasSingleRestoreOption(InputInterface $input): bool
    {
        $options = 0;
        if ($input->getOption('asset') !== null) {
            $options += 1;
        }
        if ($input->getOption('type') !== null) {
            $options += 1;
        }
        if ($input->getOption('device') !== null) {
            $options += 1;
        }
        return $options <= 1;
    }

    /**
     * @param InputInterface $input
     * @return \Datto\Asset\Asset[]
     */
    protected function getAssets(InputInterface $input)
    {
        $assetName = $input->getOption('asset');
        $assetDevice = $input->getOption('device');

        if (isset($assetName)) {
            $assets = array($this->assetService->get($assetName));
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
}
