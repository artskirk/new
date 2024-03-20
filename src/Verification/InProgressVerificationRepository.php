<?php

namespace Datto\Verification;

use Datto\Config\DeviceConfig;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class InProgressVerificationRepository
{
    const IN_PROGRESS_KEY = 'screenshot.inProgress';
    const SERIALIZED_DELIMITER = ':';

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @param InProgressVerification $inProgressVerification
     */
    public function delete(InProgressVerification $inProgressVerification)
    {
        // FIXME: this has a race condition in that, we might not be deleting the verification we expect.

        $this->deviceConfig->clear(self::IN_PROGRESS_KEY);
    }

    /**
     * @return InProgressVerification|null
     */
    public function find()
    {
        $serialized = $this->deviceConfig->get(self::IN_PROGRESS_KEY, null);

        if ($serialized === null) {
            return null;
        }

        return $this->unserialize($serialized);
    }

    /**
     * @param string $assetKey
     * @return InProgressVerification|null
     */
    public function findByAssetKey(string $assetKey)
    {
        $inProgress = $this->find();

        if (!$inProgress) {
            return null;
        }

        if ($inProgress->getAssetKey() !== $assetKey) {
            return null;
        }

        return $inProgress;
    }

    /**
     * @param InProgressVerification $inProgress
     */
    public function save(InProgressVerification $inProgress)
    {
        $serialized = $this->serialize($inProgress);

        $saved = $this->deviceConfig->set(self::IN_PROGRESS_KEY, $serialized);

        if (!$saved) {
            throw new \Exception("Could not save in progress verification record");
        }
    }

    /**
     * @param InProgressVerification $inProgress
     * @return string
     */
    private function serialize(InProgressVerification $inProgress): string
    {
        return implode(self::SERIALIZED_DELIMITER, [
            $inProgress->getAssetKey(),
            $inProgress->getSnapshot(),
            $inProgress->getStartedAt(),
            $inProgress->getDelay(),
            $inProgress->getPid(),
            $inProgress->getTimeout()
        ]);
    }

    /**
     * @param string $raw
     * @return InProgressVerification
     */
    private function unserialize(string $raw): InProgressVerification
    {
        $exploded = explode(self::SERIALIZED_DELIMITER, $raw);

        return new InProgressVerification(
            (string)$exploded[0],
            (int)$exploded[1],
            (int)$exploded[2],
            (int)$exploded[3],
            (int)$exploded[4],
            (int)$exploded[5]
        );
    }
}
