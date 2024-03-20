<?php

namespace Datto\App\Console\Command\Firewall;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\ShareService;
use Datto\Mercury\MercuryFtpService;
use Datto\Service\Security\FirewallService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * This command invoked through the service 'datto-firewall-share-sync' on boot ensures that all relevant
 * ports are open/closed based on whether shares/MercuryFtp are active.
 */
class FirewallShareSyncCommand extends AbstractShareCommand
{
    protected static $defaultName = 'firewall:share:sync';

    private FirewallService $firewallService;

    private MercuryFtpService $mercuryFtpService;

    public function __construct(
        CommandValidator $commandValidator,
        ShareService $shareService,
        FirewallService $firewallService,
        MercuryFtpService $mercuryFtpService
    ) {
        parent::__construct($commandValidator, $shareService);
        $this->shareService = $shareService;
        $this->firewallService = $firewallService;
        $this->mercuryFtpService = $mercuryFtpService;
    }

    protected function configure(): void
    {
        $this->setDescription('Sync firewall settings to enabled shares for AFP, SFTP and MercuryFtp');
    }

    /**
     * Opens/closes firewall ports/services for AFP, SFTP and MercuryFtp.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentFirewallZone = $this->firewallService->getCurrentDefaultZone();
        if ($currentFirewallZone !== FirewallService::DATTO_ZONE) {
            $output->writeln("Current firewall zone is '" . $currentFirewallZone . "', no action needed");
            return 0;
        }

        $shares = $this->shareService->getAll();
        $afpEnabled = false;
        $sftpEnabled = false;

        foreach ($shares as $share) {
            if ($share instanceof NasShare) {
                $afpEnabled = $afpEnabled || $share->getAfp()->isEnabled();
                $sftpEnabled = $sftpEnabled || $share->getSftp()->isEnabled();
            }
        }

        $this->firewallService->enableMercuryFtp(count($this->mercuryFtpService->listTargets()) > 0);
        $this->firewallService->enableAfp($afpEnabled);
        $this->firewallService->enableSftp($sftpEnabled);
        return 0;
    }
}
