<?php

namespace Datto\App\Console\Command\Storage\PublicCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Feature\FeatureService;
use Datto\Service\Storage\PublicCloud\PoolExpansionState;
use Datto\Service\Storage\PublicCloud\PoolExpansionStateManager;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Console command for setting the current state of a local storage pool expansion.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudSetStateCommand extends AbstractCommand
{
    const STATE_ARG_NAME = 'state';
    protected static $defaultName = 'storage:public:state:set';

    private PoolExpansionStateManager $poolExpansionStateManager;
    private CommandValidator $commandValidator;

    public function __construct(PoolExpansionStateManager $poolExpansionStateManager, CommandValidator $commandValidator)
    {
        $this->poolExpansionStateManager = $poolExpansionStateManager;
        $this->commandValidator = $commandValidator;

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

        $this->setDescription('Command for setting the current state of a local storage pool expansion.')
            ->addArgument(
                self::STATE_ARG_NAME,
                InputArgument::REQUIRED,
                'The state to set the pool expansion to.'
            );
    }

    protected function validateArgs(InputInterface $input): void
    {
        $validPoolStates = [PoolExpansionState::FAILED, PoolExpansionState::RUNNING, PoolExpansionState::SUCCESS];
        $this->commandValidator->validateValue(
            $input->getArgument(self::STATE_ARG_NAME),
            new Assert\Choice(['choices' => $validPoolStates]),
            self::STATE_ARG_NAME . ' must be a valid pool state: ' . implode(', ', $validPoolStates)
        );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $state = $input->getArgument(self::STATE_ARG_NAME);

        switch ($state) {
            case PoolExpansionState::SUCCESS:
                $this->poolExpansionStateManager->setSuccess();
                break;
            case PoolExpansionState::RUNNING:
                $this->poolExpansionStateManager->setRunning();
                break;
            case PoolExpansionState::FAILED:
                $this->poolExpansionStateManager->setFailed();
                break;
            default:
                throw new Exception("Invalid pool expansion state: $state");
        }

        return 0;
    }
}
