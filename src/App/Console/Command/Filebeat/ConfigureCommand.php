<?php

namespace Datto\App\Console\Command\Filebeat;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Log\Filebeat;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ConfigureCommand extends AbstractCommand
{
    protected static $defaultName = 'filebeat:configure';

    /** @var Filebeat */
    private $filebeat;

    /**
     * @param Filebeat $filebeat
     */
    public function __construct(Filebeat $filebeat)
    {
        parent::__construct();

        $this->filebeat = $filebeat;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_REMOTE_LOGGING];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Configure filebeat');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->filebeat->configure();
        return 0;
    }
}
