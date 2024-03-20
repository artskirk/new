<?php

namespace Datto\App\Console\Command\Storage\Encryption;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\System\Storage\Encrypted\EncryptedStorageService;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Base class for common dependencies and methods between drive commands.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractDriveCommand extends AbstractCommand
{
    /** @var StorageService */
    protected $storageService;

    /** @var EncryptedStorageService */
    protected $encryptedStorageService;

    public function __construct(
        StorageService $storageService,
        EncryptedStorageService $luksService
    ) {
        parent::__construct();

        $this->storageService = $storageService;
        $this->encryptedStorageService = $luksService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_STORAGE_ENCRYPTION
        ];
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $message
     * @param bool $hidden
     * @return mixed
     */
    protected function askQuestion(InputInterface $input, OutputInterface $output, string $message, bool $hidden = true)
    {
        $helper = $this->getHelper('question');
        $question = new Question($message);
        $question->setHidden($hidden);
        $question->setHiddenFallback(false);

        return $helper->ask($input, $output, $question);
    }

    /**
     * @param string $drivePath
     * @return StorageDevice
     */
    protected function getDisk(string $drivePath): StorageDevice
    {
        $disk = $this->storageService->getPhysicalDeviceByPath($drivePath);
        if ($disk === null) {
            $this->logger->error('DEC0009 Could not find specified disk', ['drivePath' => $drivePath]);
            $message = 'Could not find specified disk ' . $drivePath;
            throw new RuntimeException($message);
        }
        return $disk;
    }
}
