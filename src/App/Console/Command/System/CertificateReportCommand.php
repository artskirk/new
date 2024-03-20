<?php

namespace Datto\App\Console\Command\System;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send a report that summarizes the status of certificates on the device and the agents.
 * This is normally called once daily by a system timer.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class CertificateReportCommand extends AbstractCommand
{
    protected static $defaultName = 'system:certificate:report';

    /** @var CertificateUpdateService */
    private $certificateUpdateService;

    /**
     * @inheritdoc
     */
    public function __construct(
        CertificateUpdateService $certificateUpdateService
    ) {
        parent::__construct();

        $this->certificateUpdateService = $certificateUpdateService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_AGENT_BACKUPS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Send the summary report of the certificate status for this device and all of its agents.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->certificateUpdateService->testAllLatestWorkingAgentCertificates();
        } finally {
            $this->certificateUpdateService->sendCertificatesInUseEvent();
        }
        return 0;
    }
}
