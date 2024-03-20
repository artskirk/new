<?php

namespace Datto\App\Console\Command\Restore\PublicCloud;

use Datto\App\Console\Command\CommandValidator;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command removes a restore to the public cloud if it wasn't already
 *   cleaned up.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudRemoveCommand extends BasePublicCloudCommand
{
    protected static $defaultName = 'restore:public:remove';

    /** @var PublicCloudManager */
    private $publicCloudVirtManager;

    public function __construct(
        CommandValidator $commandValidator,
        PublicCloudManager $publicCloudVirtManager
    ) {
        $this->publicCloudVirtManager = $publicCloudVirtManager;
        parent::__construct($commandValidator);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription('Cleanup a public cloud restore.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentKey = $input->getArgument('agent');
        $snapshot = $input->getArgument('snapshot');

        $this->publicCloudVirtManager->remove(
            $agentKey,
            $snapshot
        );
        return 0;
    }
}
