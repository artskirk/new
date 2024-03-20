<?php

namespace Datto\App\Console\Command\Zfs\Pool;

use Datto\Log\LoggerAwareTrait;
use Datto\System\Storage\Encrypted\EncryptedStorageService;
use Datto\ZFS\ZpoolService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Command to import a specified zpool, and optionally decrypt disks or the pool itself
 *
 * @author Marcus Recck <mr@datto.com>
 */
class ImportCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'zfs:pool:import';

    /** @var EncryptedStorageService */
    private $encryptedStorageService;

    /** @var ZpoolService */
    private $zpoolService;

    public function __construct(
        EncryptedStorageService $encryptedStorageService,
        ZpoolService $zpoolService
    ) {
        parent::__construct();

        $this->encryptedStorageService = $encryptedStorageService;
        $this->zpoolService = $zpoolService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Import a zpool, and decrypt if necessary')
            ->addOption('decryption', 'd', InputOption::VALUE_OPTIONAL, 'Method to decrypt before import (zfs, luks)')
            ->addOption('pool', 'p', InputOption::VALUE_OPTIONAL, 'Zpool to import', 'homePool')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Whether or not to force import the pool')
            ->addOption('generated', null, InputOption::VALUE_NONE, 'Use generated key when importing the pool');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $decryptionMethod = $input->getOption('decryption');
        $zpool = $input->getOption('pool');
        $force = (bool) $input->getOption('force');

        if ($decryptionMethod !== null) {
            switch ($decryptionMethod) {
                case 'luks':
                    $this->unlockLuks($input, $output);
                    break;
                case 'zfs':
                default:
                    throw new \RuntimeException('Method for decryption is not supported.');
            }
        }

        $this->zpoolService->import($zpool, $force);
        return 0;
    }

    /**
     * Use service classes to decrypt a disk via LUKS
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function unlockLuks(InputInterface $input, OutputInterface $output): void
    {
        $encryptedDisks = $this->encryptedStorageService->getLockedEncryptedDisks();

        if (!empty($encryptedDisks)) {
            if ($input->getOption('generated')) {
                $this->encryptedStorageService->unlockAllDisksUsingGeneratedKey();
            } else {
                $passphrase = $this->getDecryptionPassphrase($input, $output);
                $this->encryptedStorageService->unlockAllDisks($passphrase);
            }
        }

        $this->logger->debug('ZFS0016 No disks left to unlock, importing pool ...');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     */
    private function getDecryptionPassphrase(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $question = new Question('Enter decryption passphrase: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return $helper->ask($input, $output, $question);
    }
}
