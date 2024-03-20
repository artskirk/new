<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\InputStream;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class Luks
{
    const MIN_KEY_SLOT = 0; // Min LUKS key slot (inclusive)
    const MAX_KEY_SLOT = 7; // Max LUKS key slot (inclusive)

    const ENABLED_KEY_SLOT_TEXT = 'ENABLED';

    const CRYPTSETUP_CIPHER = 'aes-xts-plain64';
    const CRYPTSETUP_KEY_SIZE = 512;
    const CRYPTSETUP_HASH = 'sha512';
    const CRYPTSETUP_ITER_TIME = 5000;
    const CRYPTSETUP_PAYLOAD_ALIGNMENT = 8192;

    const UNKILLABLE_SLOT = 0;

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * @param string $device
     * @param string $keyFile
     * @param int $slot
     */
    public function encrypt(string $device, string $keyFile, int $slot)
    {
        $process = $this->processFactory->get([
            'cryptsetup',
            '--cipher',
            self::CRYPTSETUP_CIPHER,
            '--key-size',
            self::CRYPTSETUP_KEY_SIZE,
            '--hash',
            self::CRYPTSETUP_HASH,
            '--iter-time',
            self::CRYPTSETUP_ITER_TIME,
            '--align-payload',
            self::CRYPTSETUP_PAYLOAD_ALIGNMENT,
            '-d',
            $keyFile,
            '--key-slot',
            $slot,
            'luksFormat',
            $device
        ]);

        $input = new InputStream();
        $process->setInput($input);
        $process->start();

        $input->write("YES\n"); // Are you sure? (Type uppercase yes):
        $input->close();
        $process->wait();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @param string $device
     * @return bool
     */
    public function encrypted(string $device): bool
    {
        $process = $this->processFactory->get([
            'cryptsetup',
            'isLuks',
            $device
        ]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param string $device
     * @param string $deviceMapperName
     * @param string $key
     */
    public function unlock(string $device, string $deviceMapperName, string $key)
    {
        $process = $this->processFactory->get([
            'cryptsetup',
            'luksOpen',
            $device,
            $deviceMapperName
        ]);

        $input = new InputStream();
        $process->setInput($input);
        $process->start();
        $input->write($key);
        $input->close();

        $process->wait();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @param string $device
     * @return int[]
     */
    public function getEnabledKeySlots(string $device)
    {
        $process = $this->processFactory->get([
                'cryptsetup',
                'luksDump',
                $device
            ]);
        $process->mustRun();

        $usedSlots = [];

        $lines = explode("\n", $process->getOutput());
        foreach ($lines as $line) {
            if (preg_match('/Key Slot (\d+):\s+(.+)/', $line, $matches)) {
                $slot = (int)$matches[1];

                if ($matches[2] === self::ENABLED_KEY_SLOT_TEXT) {
                    $usedSlots[] = $slot;
                }
            }
        }

        return $usedSlots;
    }

    /**
     * @param string $device
     * @param string $existingKey
     * @param string $newKey
     * @param int $slot
     */
    public function addKey(string $device, string $existingKey, string $newKey, int $slot)
    {
        $process = $this->processFactory->get([
            'cryptsetup',
            '--iter-time',
            self::CRYPTSETUP_ITER_TIME,
            '--key-slot',
            $slot,
            'luksAddKey',
            $device
        ]);

        $input = new InputStream();
        $process->setInput($input);
        $process->start();

        $input->write($existingKey . "\n");
        $input->write($newKey . "\n");
        $input->close();
        $process->wait();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @param string $device
     * @param string $key
     * @param int|null $slot If null, test all slots. If specified, only test that slot.
     * @return bool
     */
    public function testKey(string $device, string $key, int $slot = null)
    {
        $commandline = [];
        $commandline[] = 'cryptsetup';
        $commandline[] = '--test-passphrase';
        $commandline[] = '--tries';
        $commandline[] = 1;

        if (isset($slot)) {
            $this->validateKeySlot($slot);
            $commandline[] = '--key-slot';
            $commandline[] = $slot;
        }

        $commandline[] = 'luksOpen';
        $commandline[] = $device;

        $process = $this->processFactory->get($commandline);

        $input = new InputStream();
        $process->setInput($input);
        $process->start();

        $input->write($key);
        $input->close();
        $process->wait();

        return $process->isSuccessful();
    }

    /**
     * @param string $device
     * @param string $existingKey
     * @param int $slot
     */
    public function killSlot(string $device, string $existingKey, int $slot)
    {
        $this->validateKeySlot($slot);

        // Just to be safe, let's prevent killing slot UNKILLABLE_SLOT (holds the current shared secret).
        if ($slot === self::UNKILLABLE_SLOT) {
            throw new \InvalidArgumentException('LUKS key slot  ' . self::UNKILLABLE_SLOT . ' cannot be killed');
        }

        $process = $this->processFactory->get([
                'cryptsetup',
                'luksKillSlot',
                $device,
                $slot
            ]);

        $input = new InputStream();
        $process->setInput($input);
        $process->start();

        $input->write($existingKey);
        $input->close();
        $process->wait();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @param int $slot
     */
    private function validateKeySlot($slot)
    {
        if (!is_int($slot) || $slot < self::MIN_KEY_SLOT || $slot > self::MAX_KEY_SLOT) {
            throw new \InvalidArgumentException('Slot must be 0 <= $slot <= 7');
        }
    }
}
