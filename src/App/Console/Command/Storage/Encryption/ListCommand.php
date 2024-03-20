<?php

namespace Datto\App\Console\Command\Storage\Encryption;

use Datto\System\Storage\StorageDevice;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list drives with encryption information.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ListCommand extends AbstractDriveCommand
{
    protected static $defaultName = 'storage:encryption:list';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Command for listing drives with encryption information');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders([
            'name',
            'encrypted',
            'unlocked',
            'unlocked devmapper',
            'usedSlots',
            'hasGeneratedKey'
        ]);

        $disks = $this->storageService->getPhysicalDevices();

        $hasGeneratedKey = $this->testGeneratedKeyMany($disks);

        foreach ($disks as $disk) {
            try {
                if ($this->encryptedStorageService->isDiskEncrypted($disk)) {
                    $row = [
                        $disk->getName(),
                        'yes',
                        $this->encryptedStorageService->hasDiskBeenUnlocked($disk) ? 'yes' : 'no',
                        $this->encryptedStorageService->getDeviceMapperPath($disk),
                        implode(',', $this->encryptedStorageService->getUsedKeySlots($disk)),
                        $hasGeneratedKey[$disk->getName()] ?? 'unknown'
                    ];
                } else {
                    $row = [
                        $disk->getName(),
                        'no',
                        '-',
                        '-',
                        '-',
                        '-'
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('DES0011 Could not check drive ', ['error' => $e->getMessage(), 'disk' => $disk->getName()]);
                $row = [
                    $disk->getName(),
                    '-',
                    '-',
                    '-',
                    '-',
                    '-'
                ];
            }

            $table->addRow($row);
        }

        $table->render();
        return 0;
    }

    /**
     * @param StorageDevice[] $disks
     * @return bool[]
     */
    private function testGeneratedKeyMany(array $disks)
    {
        try {
            return array_map(function ($result) {
                return $result ? 'yes' : 'no';
            }, $this->encryptedStorageService->testGeneratedKeyMany($disks));
        } catch (\Exception $e) {
            return [];
        }
    }
}
