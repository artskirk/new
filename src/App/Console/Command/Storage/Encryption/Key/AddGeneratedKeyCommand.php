<?php

namespace Datto\App\Console\Command\Storage\Encryption\Key;

use Datto\App\Console\Command\Storage\Encryption\AbstractDriveCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for adding a generated key to all encrypted drives.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AddGeneratedKeyCommand extends AbstractDriveCommand
{
    protected static $defaultName = 'storage:encryption:key:add:generated';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Command for adding generated key to all encrypted drives')
            ->addArgument(
                'drive',
                InputArgument::OPTIONAL,
                'Drive path (e.g. /dev/sda)'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Add to all drives');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('drive')) {
            $encryptedDisks = [$this->getDisk($input->getArgument('drive'))];
        } elseif ($input->getOption('all')) {
            $encryptedDisks = null;
        } else {
            throw new \InvalidArgumentException('Must specify either a drive or --all');
        }

        $fixedKey = $this->askQuestion($input, $output, 'Enter fixed passphrase: ');

        $this->encryptedStorageService->addMissingGeneratedKey($fixedKey, $encryptedDisks);
        return 0;
    }
}
