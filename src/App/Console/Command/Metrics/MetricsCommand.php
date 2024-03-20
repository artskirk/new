<?php

namespace Datto\App\Console\Command\Metrics;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @author Geoff Amey <gamey@datto.com>
 */
abstract class MetricsCommand extends AbstractCommand
{
    protected Collector $collector;

    public function __construct(Collector $collector)
    {
        parent::__construct();
        $this->collector = $collector;
    }

    protected function configure(): void
    {
        $this->addOption(
            'tag',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Add a tag (In "key=value" format) to the metric'
        );

        // We don't want interactive users generating arbitrary metrics, so hide this command.
        $this->setHidden(true);
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_METRICS];
    }

    public function getTags(InputInterface $input): array
    {
        $explodedTags = array_map(
            static function ($contextKeyValue) {
                if (strpos($contextKeyValue, '=') === false) {
                    throw new InvalidArgumentException('Tag data must be provided in "key=value" format');
                }
                return explode('=', $contextKeyValue);
            },
            $input->getOption('tag')
        );
        return array_combine(
            array_column($explodedTags, 0),
            array_column($explodedTags, 1)
        );
    }
}
