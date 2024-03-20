<?php

namespace Datto\App\Console\Command\Storage\Encryption;

use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to unlock a LUKS encrypted drive.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class UnlockCommand extends AbstractDriveCommand
{
    protected static $defaultName = 'storage:encryption:unlock';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Command for unlocking a LUKS encrypted drive.')
            ->addArgument(
                'drive',
                InputArgument::OPTIONAL,
                'Drive path (e.g. /dev/sda)'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Unlock all drives')
            ->addOption('generated', null, InputOption::VALUE_NONE, 'Use generated key to unlock the drive');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all')) {
            if ($input->getOption('generated')) {
                $this->encryptedStorageService->unlockAllDisksUsingGeneratedKey();
            } else {
                $this->encryptedStorageService->unlockAllDisks($this->getPassphrase($input, $output));
            }
        } else {
            $disk = $this->getDisk($input->getArgument('drive'));

            if ($input->getOption('generated')) {
                $this->encryptedStorageService->unlockDiskUsingGeneratedKey($disk);
            } else {
                $this->encryptedStorageService->unlockDisk($disk, $this->getPassphrase($input, $output));
            }
        }
        return 0;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string
     */
    private function getPassphrase(InputInterface $input, OutputInterface $output): string
    {
        return $this->askQuestion($input, $output, 'Enter passphrase to unlock the drive with: ');
    }
}
