<?php

namespace Datto\App\Console\Command\Storage\Encryption\Key;

use Datto\App\Console\Command\Storage\Encryption\AbstractDriveCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to add an encryption key to an encrypted drive.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AddKeyCommand extends AbstractDriveCommand
{
    protected static $defaultName = 'storage:encryption:key:add';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Command for adding an encryption key to an encrypted drive.')
            ->addArgument(
                'drive',
                InputArgument::REQUIRED,
                'Drive path (e.g. /dev/sda)'
            )
            ->addArgument('slot', InputArgument::REQUIRED, 'Slot to add key to');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $disk = $this->getDisk($input->getArgument('drive'));
        $slot = $input->getArgument('slot');

        $fixedKey = $this->askQuestion($input, $output, 'Enter fixed passphrase: ');
        $passphrase = $this->askQuestion($input, $output, 'Enter passphrase to add to the drive: ');
        $passphraseConfirm = $this->askQuestion($input, $output, 'Confirm passphrase: ');

        if ($passphrase !== $passphraseConfirm) {
            $this->logger->error('DEC0013 Passwords did not match, cancelling key adding');
            return 1;
        }

        $this->encryptedStorageService->addKey($disk, $fixedKey, $passphrase, $slot);
        return 0;
    }
}
