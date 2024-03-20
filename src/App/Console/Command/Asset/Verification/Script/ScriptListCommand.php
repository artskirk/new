<?php

namespace Datto\App\Console\Command\Asset\Verification\Script;

use Datto\App\Console\Command\Asset\Verification\AbstractVerificationCommand;
use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Class ScriptListCommand
 * Lists all scripts tied to an asset
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScriptListCommand extends AbstractVerificationCommand
{
    protected static $defaultName = 'asset:verification:script:list';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->addArgument('asset', InputArgument::REQUIRED, 'Name of the asset you wish to see scripts for')
            ->setDescription('List all script names and keys for an asset');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headers = array('Script Name', 'Script Key');
        $table = new Table($output);
        $rows = array();
        $assetName = $input->getArgument('asset');
        $asset = $this->assetService->get($assetName);
        $scriptSettings = $asset->getScriptSettings();
        $scripts = $scriptSettings->getScripts();
        if (count($scripts) > 0) {
            foreach ($scripts as $script) {
                $rows[] = array(
                    $script->getName(),
                    $script->getFullUniqueId()
                );
            }
            $table->setHeaders($headers)->setRows($rows)->render();
        } else {
            $output->writeln('No scripts are tied to '  . $assetName);
        }
        return 0;
    }
}
