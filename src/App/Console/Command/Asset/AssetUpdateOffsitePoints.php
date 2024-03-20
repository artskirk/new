<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfigFactory;
use Datto\Metrics\Offsite\OffsiteMetricsService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update the offsite points for every asset
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AssetUpdateOffsitePoints extends AbstractCommand
{
    protected static $defaultName = 'asset:update:offsite';

    /** @var SpeedSync */
    private $speedsync;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;
    
    /** @var OffsiteMetricsService */
    private $offsiteMetricsService;

    public function __construct(
        SpeedSync $speedsync,
        AgentConfigFactory $agentConfigFactory,
        OffsiteMetricsService $offsiteMetricsService
    ) {
        parent::__construct();

        $this->speedsync = $speedsync;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->offsiteMetricsService = $offsiteMetricsService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_OFFSITE];
    }

    protected function configure()
    {
        $this->setDescription('Update the offSitePoints keyfile for every asset');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeys = $this->agentConfigFactory->getAllKeyNames();
        foreach ($assetKeys as $assetKey) {
            $agentConfig = $this->agentConfigFactory->create($assetKey);
            $zfsBase = $agentConfig->getZfsBase();
            $points = $this->speedsync->getOffsitePoints("$zfsBase/$assetKey");

            $agentConfig->setRaw('offSitePoints', implode("\n", $points) . "\n");
        }

        $this->offsiteMetricsService->updateCompletedPoints();
        return 0;
    }
}
