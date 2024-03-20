<?php

namespace Datto\App\Console\Command\System;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\Certificate\CertificateHelper;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Make sure that the device certificate and CA certificate are up to date.
 * This is called by checkin every 10 minutes and is passed the hash of the current trusted root ca on device-web.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class CertificateCheckCommand extends AbstractCommand
{
    protected static $defaultName = 'system:certificate:check';

    private CertificateHelper $certificateHelper;
    private CertificateSetStore $certificateSetStore;

    public function __construct(
        CertificateHelper $certificateHelper,
        CertificateSetStore $certificateSetStore
    ) {
        parent::__construct();

        $this->certificateHelper = $certificateHelper;
        $this->certificateSetStore = $certificateSetStore;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    public function multipleInstancesAllowed(): bool
    {
        return false;
    }

    /**
     * Ignore --fuzz if device does not have certs at all.
     *
     * New devices won't have certificates on them yet and need to acquire them
     * as soon as possible so they are usable after registration.
     */
    public function fuzzAllowed(): bool
    {
        $certs = $this->certificateSetStore->getCertificateSets();

        if (!isset($certs[0])) {
            $this->logger->info(
                'CRT0010 Missing certificates... ignoring --fuzz option'
            );

            return false;
        }

        return true;
    }

    protected function configure(): void
    {
        $this->setDescription('Update root certificates for agent communication from device web if needed.')
            ->addArgument('deviceWebTrustedRootHash', InputArgument::REQUIRED, 'The hash of the trusted root ca on device-web. ' .
            'This is passed by checkin when it calls this command.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deviceWebTrustedRootHash = $input->getArgument('deviceWebTrustedRootHash');

        $this->certificateHelper->updateCertificates($deviceWebTrustedRootHash);
        return 0;
    }
}
