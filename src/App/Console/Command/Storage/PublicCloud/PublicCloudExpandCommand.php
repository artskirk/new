<?php

namespace Datto\App\Console\Command\Storage\PublicCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Feature\FeatureService;
use Datto\Service\Storage\PublicCloud\PoolExpansionService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Throwable;

/**
 * Console command for expanding the local data pool.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class PublicCloudExpandCommand extends AbstractCommand
{
    const DISK_LUN_ARG_NAME = 'diskLun';
    const OPTION_RESIZE = 'resize';

    protected static $defaultName = 'storage:public:expand';

    private CommandValidator $commandValidator;
    private PoolExpansionService $poolExpansionService;

    public function __construct(CommandValidator $commandValidator, PoolExpansionService $poolExpansionService)
    {
        $this->commandValidator = $commandValidator;
        $this->poolExpansionService = $poolExpansionService;

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

        $this
            ->setDescription('Command for expanding the local data pool.')
            ->addArgument(
                self::DISK_LUN_ARG_NAME,
                InputArgument::REQUIRED,
                'The logical unit number of the disk used to grow the data pool.'
            )
            ->addOption(
                self::OPTION_RESIZE,
                null,
                InputOption::VALUE_NONE,
                'Flag to specify that the disk was resized'
            );
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $input->getArgument(self::DISK_LUN_ARG_NAME),
            new Assert\Regex(['pattern' => '/^\d+$/']),
            self::DISK_LUN_ARG_NAME . ' must be a valid integer'
        );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->validateArgs($input);

            $diskLun = (int)$input->getArgument(self::DISK_LUN_ARG_NAME);
            $resize = $input->getOption(self::OPTION_RESIZE);

            $this->poolExpansionService->expandPoolIntoDisk($diskLun, $resize);
        } catch (Throwable $exception) {
            $this->logger->error('SPE0000 Failed to expand data pool', ['exception' => $exception]);
            throw $exception;
        }

        return 0;
    }
}
