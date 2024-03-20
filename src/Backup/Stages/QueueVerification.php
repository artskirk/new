<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Feature\FeatureService;
use Datto\Verification\VerificationScheduler;

/**
 * This backup stage queues snapshot in the verification queue if they are scheduled to be run.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class QueueVerification extends BackupStage
{
    /** @var FeatureService */
    private $featureService;

    /** @var VerificationScheduler */
    private $verificationScheduler;

    public function __construct(
        FeatureService $featureService,
        VerificationScheduler $verificationScheduler
    ) {
        $this->featureService = $featureService;
        $this->verificationScheduler = $verificationScheduler;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS)) {
            /** @var Agent $agent */
            $agent = $this->context->getAsset();
            try {
                $this->verificationScheduler->scheduleVerifications($agent);
            } catch (\Throwable $e) {
                // Already logged
            }
        } else {
            $this->context->getLogger()->debug('VRF2509 Device does not support verifications; skipping screenshot verification');
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }
}
