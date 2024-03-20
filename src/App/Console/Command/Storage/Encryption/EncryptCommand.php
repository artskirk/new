<?php

namespace Datto\App\Console\Command\Storage\Encryption;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to encrypt a specified drive
 *
 * @author Nick Mattis <nmattis@datto.com>
 */
class EncryptCommand extends AbstractDriveCommand
{
    protected static $defaultName = 'storage:encryption:encrypt';

    const FORCE_OPTION = 'force';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Command for luks encrypting a drive')
            ->addArgument(
                'drive',
                InputArgument::REQUIRED,
                'Drive path (e.g. /dev/sda)'
            )
            ->addOption(
                self::FORCE_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Force an encryption even if the drive is already encrypted.'
            )
            ->addOption('unlock', null, InputOption::VALUE_NONE, 'Unlock after encrypting')
            ->addOption('no-generated', null, InputOption::VALUE_NONE, 'Do not add generated key');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $addGeneratedKey = !$input->getOption('no-generated');

        $disk = $this->getDisk($input->getArgument('drive'));

        $isForcedEncrypt = $input->getOption(self::FORCE_OPTION);
        if (!$isForcedEncrypt && $this->encryptedStorageService->isDiskEncrypted($disk)) {
            $this->logger->info('DEC0002 Disk is already encrypted.', ['disk' => $disk->getName()]);
            return 0;
        }

        $passphrase = $this->askQuestion($input, $output, 'Enter fixed passphrase to encrypt the drive with: ');
        $passphraseConfirm = $this->askQuestion($input, $output, 'Confirm passphrase: ');

        if ($passphrase !== $passphraseConfirm) {
            $this->logger->error('DEC0003 Passwords did not match, cancelling encryption');
            return 1;
        }

        $msg = 'Disk: ' . $disk->getName() . ' serial: ' . $disk->getSerial() . ' is about to be encrypted. Are you sure? (Yes/No) ';
        $confirmed = $this->askQuestion($input, $output, $msg, false);
        if (strtolower($confirmed) !== 'yes') {
            $this->logger->info('DEC0004 Encryption for drive cancelled', ['disk' => $disk->getName()]);
            return 1;
        }

        $this->encryptedStorageService->encryptDisk($disk, $passphrase, $addGeneratedKey);

        if ($input->getOption('unlock')) {
            $this->encryptedStorageService->unlockDisk($disk, $passphrase);
        }
        return 0;
    }
}
