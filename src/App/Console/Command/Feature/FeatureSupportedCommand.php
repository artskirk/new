<?php

namespace Datto\App\Console\Command\Feature;

use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Determines whether or not a feature is supported for the device|asset.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class FeatureSupportedCommand extends AbstractFeatureCommand
{
    const EXIT_CODE_SUCCESS = 0;
    const EXIT_CODE_ERROR = 1;

    protected static $defaultName = 'feature:supported';

    /** @var AssetService */
    private $assetService;

    public function __construct(
        AssetService $assetService,
        FeatureService $featureService
    ) {
        parent::__construct($featureService);

        $this->assetService = $assetService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Check whether a feature is supported for device/asset.')
            ->addArgument('feature', InputArgument::REQUIRED, 'The name of the feature you wish to know is supported')
            ->addOption('quiet', 'q', InputOption::VALUE_NONE, 'Quiet mode used if nothing should be printed (exit code only)')
            ->addOption('featureversion', 'fv', InputOption::VALUE_REQUIRED, 'A version defined by the requesting software')
            ->addOption('assetname', 's', InputOption::VALUE_REQUIRED, 'Name of the asset you wish to check the feature for');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $featureName = $input->getArgument('feature');
        $quiet = $input->getOption('quiet');
        $version = $input->getOption('version');
        $assetName = $input->getOption('assetname');
        $asset = $assetName ? $this->assetService->get($assetName) : null;

        $supported = $this->featureService->isSupported($featureName, $version, $asset);

        if (!$quiet) {
            $io = new SymfonyStyle($input, $output);
            if ($supported) {
                $io->success("$featureName is supported for device/asset");
            } else {
                $io->warning("$featureName is NOT supported for device/asset");
            }
        }

        return $supported ? self::EXIT_CODE_SUCCESS : self::EXIT_CODE_ERROR;
    }
}
