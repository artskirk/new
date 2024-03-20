<?php

namespace Datto\Verification\Stages;

use Datto\Feature\FeatureService;
use Datto\Screenshot\StatusFactory;
use Datto\System\Transaction\TransactionException;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Verification\VerificationResultType;
use Throwable;

/**
 * Wait for the asset to be ready.
 *
 * Logs messages with the VER prefix in the range 0400-0499.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class AssetReady extends VerificationStage
{
    const MINIMUM_POLL_SECONDS = 5;
    const READY_STATE_DETECTED = 'ReadyStateDetected';

    /** @var StatusFactory Used to create the Status object */
    private $statusFactory;

    /** @var DateTimeService */
    private $dateService;

    /** @var Sleep */
    private $sleep;

    private $featureService;

    public function __construct(
        StatusFactory $statusFactory,
        DateTimeService $dateService,
        Sleep $sleep,
        FeatureService $featureService
    ) {
        $this->statusFactory = $statusFactory;
        $this->dateService = $dateService;
        $this->sleep = $sleep;
        $this->featureService = $featureService;
    }

    public function commit()
    {
        if ($this->context->getOsUpdatePending() && $this->featureService->isSupported(FeatureService::FEATURE_SKIP_VERIFICATION)) {
            $this->setResult(VerificationResultType::SKIPPED());
            return;
        }

        $readyStateDetected = false;

        try {
            // Wait for the VM to boot to a ready state or for the delay to expire.
            $this->logger->debug(
                'VER0400 Waiting for the VM to boot. Timeout in ' . $this->context->getReadyTimeout() . ' seconds.'
            );

            if (is_null($this->context->getVirtualMachine())) {
                throw new \RuntimeException("Expected VirtualMachine to be instantiated.");
            }

            if ($this->context->isLakituInjected()) {
                $status = $this->statusFactory->create($this->context->getAgent(), $this->context->getVirtualMachine());

                $this->waitForStatusOrTimeout([$status, 'isAgentReady']);
                if ($this->context->getReadyTimeout() > 0) {
                    $this->context->setLakituResponded();
                    $this->waitForStatusOrTimeout(function () use (&$status) {
                        try {
                            $this->context->setLakituVersion($status->getVersion());
                            return true;
                        } catch (Throwable $ex) {
                            $this->logger->debug('VER0407 Error getting Lakitu Version. Retrying', [
                                'exception' => $ex
                            ]);
                            return false;
                        }
                    });
                }

                if ($this->context->getReadyTimeout() > 0) {
                    $this->waitForStatusOrTimeout([$status, 'isLoginManagerReady']);
                }

                // Close the connection to the Transport.
                unset($status);
            } else {
                $this->logger->debug('VER0408 Waiting for ready timeout to elapse');
                $this->sleep->sleep($this->context->getReadyTimeout());
                $this->context->setReadyTimeout(0);
            }

            if ($this->context->getReadyTimeout() > 0) {
                $readyStateDetected = true;
                $this->logger->debug('VER0402 Ready state detected. This is a successful boot.');
            } else {
                $this->logger->debug('VER0403 Timeout reached without any indication of a successful boot.');
            }

            $this->logger->debug(
                'VER0404 Exited ready state detection loop, timeout = ' . $this->context->getReadyTimeout()
            );
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        $this->waitCustomDelay();

        if (!$this->result) {
            $this->setResult(VerificationResultType::SUCCESS());
        }

        $this->result
            ->getDetails()
            ->setDetail(AssetReady::READY_STATE_DETECTED, $readyStateDetected);

        if (!$this->result->didSucceed()) {
            throw new TransactionException('Asset ready failed. Error message: ' . $this->result->getErrorMessage());
        }
    }

    public function cleanup()
    {
        // No cleanup required for this stage.
    }

    /**
     * Poll a callable and watch the timeout
     *
     * @param callable $c Callable that returns TRUE when the loop should end
     */
    private function waitForStatusOrTimeout(callable $c)
    {
        $lastQueryTime = $this->dateService->getTime();
        while (!call_user_func($c)) {
            $duration = $this->dateService->getTime() - $lastQueryTime;
            $wait = floor(self::MINIMUM_POLL_SECONDS - $duration); // make sure it takes at least 5 seconds
            $waitedLongEnough = $wait <= 0;
            if (!$waitedLongEnough) {
                $this->sleep->sleep($wait);
            }
            $this->context->setReadyTimeout($this->context->getReadyTimeout() - self::MINIMUM_POLL_SECONDS);
            $lastQueryTime = $this->dateService->getTime();
            $timeoutReached = $this->context->getReadyTimeout() <= 0;
            if ($timeoutReached) {
                break;
            }
        }
    }
    /**
     * Pause for the user-defined custom delay.
     */
    private function waitCustomDelay()
    {
        // If a delay has been set, wait for that length...
        $delay = $this->context->getScreenshotWaitTime();
        if ($delay) {
            $this->logger->debug("VER0405 Custom delay requested. Waiting $delay seconds.");
            $this->sleep->sleep($delay);
            $this->logger->debug('VER0406 Custom delay complete.');
        }
    }
}
