<?php

namespace Datto\Events\Verification;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\AssetData;
use Datto\Events\Common\PlatformData;
use Datto\Events\Common\TransactionData;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in verification Event
 */
class VerificationEventData extends AbstractEventNode implements EventDataInterface
{
    /** @var PlatformData */
    protected $platform;

    /** @var AssetData */
    protected $asset;

    /** @var TransactionData */
    protected $transaction;

    /** @var VerificationAgentData */
    protected $verificationAgent;

    /** @var ScreenshotData */
    protected $screenshot;

    public function __construct(
        PlatformData $platform,
        AssetData $asset,
        TransactionData $transaction,
        VerificationAgentData $verificationAgent,
        ScreenshotData $screenshot
    ) {
        $this->platform = $platform;
        $this->asset = $asset;
        $this->transaction = $transaction;
        $this->verificationAgent = $verificationAgent;
        $this->screenshot = $screenshot;
    }

    /** @inheritDoc */
    public function getSchemaVersion(): int
    {
        return 7;
    }
}
