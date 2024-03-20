<?php

namespace Datto\App\Console\Command\Salt;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\RemoteManagement\SaltMinion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ConfigureCommand extends AbstractCommand
{
    protected static $defaultName = 'salt:configure';

    /** @var SaltMinion */
    private $saltMinion;

    public function __construct(
        SaltMinion $saltMinion
    ) {
        parent::__construct();

        $this->saltMinion = $saltMinion;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_REMOTE_MANAGEMENT];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Configure salt minion');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->saltMinion->configure();
        return 0;
    }
}
