<?php

namespace Datto\App\Console\Command\Metrics\Telegraf;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Metrics\Telegraf;
use Datto\Service\Metrics\TelegrafConfigException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ConfigureCommand extends AbstractCommand
{
    protected static $defaultName = 'metrics:telegraf:configure';

    private Telegraf $telegraf;

    public function __construct(
        Telegraf $telegraf
    ) {
        parent::__construct();

        $this->telegraf = $telegraf;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_METRICS];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Configure telegraf');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->telegraf->configure();
            return self::SUCCESS;
        } catch (TelegrafConfigException $e) {
            $this->logger->error('TGC0001 Configuring Telegraf failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }
}
