<?php

namespace Datto\App\Console\Command\Feature;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Command used to write flag files used in systemd services and
 * configuration files.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FeatureFlagUpdateCommand extends AbstractFeatureCommand
{
    protected static $defaultName = 'feature:flag:update';

    protected function configure()
    {
        $this
            ->setDescription('Update the feature flag files used in systemd conditions and configs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $updated = $this->featureService->updateFlags();

        if ($updated > 0) {
            $output->writeln(sprintf(
                '%d feature(s) have been enabled/disabled. A reboot may be required for new features to function properly.',
                $updated
            ));
        } else {
            $output->writeln('No features were updated.');
        }
        return 0;
    }

    public function isHidden(): bool
    {
        return true;
    }
}
