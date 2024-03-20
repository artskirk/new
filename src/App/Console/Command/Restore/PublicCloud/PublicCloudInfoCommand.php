<?php

namespace Datto\App\Console\Command\Restore\PublicCloud;

use Datto\App\Console\Command\CommandValidator;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Get managed disks information which is required for public cloud restore.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class PublicCloudInfoCommand extends BasePublicCloudCommand
{
    protected static $defaultName = 'restore:public:info';

    /** @var PublicCloudManager */
    private $publicCloudManager;

    public function __construct(
        CommandValidator $commandValidator,
        PublicCloudManager $publicCloudManager
    ) {
        $this->publicCloudManager = $publicCloudManager;
        parent::__construct($commandValidator);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription('Get managed disks information which is required for public cloud restore.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentKey = $input->getArgument('agent');
        $snapshot = $input->getArgument('snapshot');
        $vmGeneration = $input->getArgument('vmGeneration');

        $info = $this->publicCloudManager->getInfo(
            $agentKey,
            $snapshot,
            $vmGeneration
        );

        if (!empty($info)) {
            $this->writeTableOutput($output, $info);
        }

        return 0;
    }

    private function writeTableOutput(OutputInterface $output, array $info): void
    {
        $table = new Table($output);
        $table->setHeaders(['VHD file', 'Size', 'OS']);

        foreach ($info as $key => $row) {
            $table->addRow([$key, $row['size'], $row['osVolume']]);
        }

        $table->render();
    }
}
