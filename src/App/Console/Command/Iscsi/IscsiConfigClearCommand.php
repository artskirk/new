<?php

namespace Datto\App\Console\Command\Iscsi;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Iscsi\IscsiTarget;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command clears the LIO kernel target configuration.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiConfigClearCommand extends AbstractCommand
{
    protected static $defaultName = 'iscsi:config:clear';

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
        $this->setDescription('Clears the LIO kernel target configuration and clears the restored flag');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->iscsiTarget->clearConfiguration();
        return 0;
    }
}
