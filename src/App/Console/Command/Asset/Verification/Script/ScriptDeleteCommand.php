<?php

namespace Datto\App\Console\Command\Asset\Verification\Script;

use Datto\App\Console\Command\Asset\Verification\AbstractVerificationCommand;
use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ScriptDeleteCommand
 * Deletes a/all scripts for specified asset
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScriptDeleteCommand extends AbstractVerificationCommand
{
    protected static $defaultName = 'asset:verification:script:delete';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->addArgument('asset', InputArgument::REQUIRED, 'Name of the asset you wish to delete scripts for')
            ->addArgument('scriptKey', InputArgument::OPTIONAL, 'Key of the script you wish to delete')
            ->addOption('all', '-A', InputOption::VALUE_NONE)
            ->setDescription('Delete script(s) for an asset');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetName = $input->getArgument('asset');
        $asset = $this->assetService->get($assetName);
        $scriptSettings = $asset->getScriptSettings();
        if ($input->hasOption('all')) {
            $scriptSettings->deleteAllScripts();
        } else {
            $scriptKey = $input->getArgument('scriptKey');
            if ($scriptKey === null) {
                $output->writeln('Script key not set');
                return 1;
            }
            $scriptSettings->deleteScript($scriptKey);
        }
        return 0;
    }
}
