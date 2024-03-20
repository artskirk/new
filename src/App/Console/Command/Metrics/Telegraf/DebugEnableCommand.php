<?php

namespace Datto\App\Console\Command\Metrics\Telegraf;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Metrics\Telegraf;
use Datto\Service\Metrics\TelegrafConfigException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Krzysztof Smialek <krzysztof.smialek@datto.com>
 */
class DebugEnableCommand extends AbstractCommand
{
    protected static $defaultName = 'metrics:telegraf:debug:enable';

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
            ->setDescription('Enable Telegraf debugging');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->telegraf->enableDebug();
            $this->telegraf->restartService();
        } catch (TelegrafConfigException $e) {
            $this->logger->error('TGC0002 Enabling Telegraf debugging failed', ['exception' => $e]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
