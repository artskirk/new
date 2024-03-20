<?php

namespace Datto\Events;

use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Events\Common\TransactionData;
use Datto\Events\Verification\ScreenshotData;
use Datto\Events\Verification\VerificationAgentData;
use Datto\Events\Verification\VerificationEventContext;
use Datto\Events\Verification\VerificationEventData;
use Datto\System\Transaction\Transaction;
use Datto\Verification\Notification\VerificationResults;
use Datto\Verification\VerificationContext;
use Datto\Verification\VerificationResultType;

/**
 * Create an Event that summarizes the results of a verification run
 */
class VerificationEventFactory
{
    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    public function __construct(CommonEventNodeFactory $nodeFactory)
    {
        $this->nodeFactory = $nodeFactory;
    }

    public function create(
        Transaction $transaction,
        VerificationContext $verificationContext,
        VerificationResults $verificationResults,
        VerificationResultType $overallResult
    ): Event {
        $analysis = $verificationResults->getScreenshotAnalysis();

        /** @var AbstractLibvirtConnection */
        $connection = $verificationContext->getConnection();
        $hypervisorHost = "local";
        if (!$connection->isLocal()) {
            $hypervisorHost = $connection->getHost();
        }

        $data = new VerificationEventData(
            $this->nodeFactory->createPlatformData(),
            $this->nodeFactory->createAssetData(
                $verificationContext->getAgent()->getKeyName(),
                $verificationContext->getSnapshotEpoch()
            ),
            new TransactionData(
                $overallResult->key(),
                $transaction->getStartTime(),
                $transaction->getEndTime()
            ),
            new VerificationAgentData(
                $verificationContext->isLakituInjected(),
                $verificationContext->hasLakituResponded(),
                $verificationContext->getLakituVersion()
            ),
            new ScreenshotData(
                $verificationResults->getScreenshotSuccessful()
                    && $analysis === VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE,
                $verificationContext->getReadyTimeout(),
                $hypervisorHost
            )
        );

        $context = new VerificationEventContext($this->nodeFactory->createResultsContext($transaction), $analysis);

        return new Event(
            'iris',
            'device.agent.verification.completed',
            $data,
            $context,
            $verificationContext->getRunIdentifier(),
            $this->nodeFactory->getResellerId(),
            $this->nodeFactory->getDeviceId()
        );
    }
}
