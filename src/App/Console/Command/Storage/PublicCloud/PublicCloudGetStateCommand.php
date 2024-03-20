<?php

namespace Datto\App\Console\Command\Storage\PublicCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Storage\PublicCloud\PoolExpansionStateManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command for retrieving the current state of a local storage pool expansion.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class PublicCloudGetStateCommand extends AbstractCommand
{
    protected static $defaultName = 'storage:public:state:get';

    private PoolExpansionStateManager $poolExpansionStateManager;

    public function __construct(PoolExpansionStateManager $poolExpansionStateManager)
    {
        $this->poolExpansionStateManager = $poolExpansionStateManager;

        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_PUBLIC_CLOUD_POOL_EXPANSION];
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription('Command for retrieving the current state of a local storage pool expansion.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $state = $this->poolExpansionStateManager->getPoolExpansionState();
        $output->writeln(json_encode($state, JSON_PRETTY_PRINT));

        return 0;
    }
}
