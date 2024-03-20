<?php

namespace Datto\App\Console\Command\Iscsi;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Iscsi\IscsiTarget;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command marks the LIO kernel target configuration as restored.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiConfigRestoredCommand extends AbstractCommand
{
    protected static $defaultName = 'iscsi:config:restored';

    /** @var IscsiTarget */
    private $iscsiTarget;

    public function __construct(
        IscsiTarget $iscsiTarget
    ) {
        parent::__construct();

        $this->iscsiTarget = $iscsiTarget;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Sets a flag which indicates that the LIO kernel target configuration has been restored');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->iscsiTarget->setIscsiConfigurationRestored();
        return 0;
    }
}
