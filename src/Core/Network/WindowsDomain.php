<?php

namespace Datto\Core\Network;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Config\LocalConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Samba\SambaManager;
use Datto\Utility\Network\Hostname;
use Datto\Utility\Systemd\Systemctl;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Provides functionality related to Windows Domain and Workgroup Networking using Samba and Winbindd.
 *
 * @see docs/WindowsDomain.md For additional information on Domain functionality
 */
class WindowsDomain implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SAMBA_CONF = '/etc/samba/smb.conf';
    private const SAMBA_CONF_BACKUP = self::SAMBA_CONF . '-bak';
    private const NSSWITCH_CONF = '/etc/nsswitch.conf';
    private const NSSWITCH_CONF_BACKUP = self::NSSWITCH_CONF . '-bak';

    private const WINBIND_SERVICE = 'winbind.service';
    private const SMBD_SERVICE = 'smbd.service';
    private const NMBD_SERVICE = 'nmbd.service';

    private const MAX_WORKGROUP_LEN = 15; // A NetBIOS workgroup can be at most 15 characters long

    private ProcessFactory $processFactory;
    private Filesystem $filesystem;
    private SambaManager $sambaManager;
    private Systemctl $systemctl;
    private Hostname $hostname;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        SambaManager $sambaManager,
        Systemctl $systemctl,
        Hostname $hostname
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->sambaManager = $sambaManager;
        $this->systemctl = $systemctl;
        $this->hostname = $hostname;
    }

    /**
     * Join this device to a Windows Active Directory Domain
     *
     * @param string $domain The name of the domain to join (e.g. `example.com`, `ad.datto.lan`, etc...)
     * @param string $user The user with Domain Administrator privileges to join this device to the domain with
     * @param string $password The password for the `$user` account (base64 encoded)
     * @param string $dcOverride An optional Domain Controller override. If this is absent, we will choose
     *  the best DC automatically using DNS, as normal.
     */
    public function join(string $domain, string $user, string $password, string $dcOverride = ''): void
    {
        if ($this->inDomain()) {
            $this->logger->warning('DOM0003 Cannot join domain. Already a domain member.');
            return;
        }

        $this->logger->debug('DOM0001 Joining a Windows Domain', [
            'domain' => $domain,
            'dcOverride' => $dcOverride
        ]);

        // Get the primary DC server name for this domain, trying the hard-coded override first, then falling
        // back to network discovery, then finally just assuming it's the domain name (it usually is)
        $primaryDc = $dcOverride ?: $this->discoverServer($domain) ?: $domain;

        // Do an ADS lookup to get the "Pre-Win2k" style domain name. (mydomain.company.com => MYDOMAIN)
        $adsInfo = $this->adsLookup($primaryDc);
        $workgroup = $adsInfo['Pre-Win2k Domain'];

        try {
            // Back up the smb.conf and nsswitch.conf files, so we can restore them if something goes wrong
            $this->filesystem->copy(self::SAMBA_CONF, self::SAMBA_CONF_BACKUP);
            $this->filesystem->copy(self::NSSWITCH_CONF, self::NSSWITCH_CONF_BACKUP);

            // Update the Samba global configuration with the domain-membership settings
            $this->configureSamba($domain, $workgroup, $dcOverride);

            // Stop the samba services (smbd, nmbd, winbindd)
            $this->stopSamba();

            // Clear the net cache, kerberos conf, and secrets tdb
            $this->flushCachedAuth();

            // Update the nsswitch.conf with the domain-membership configuration
            $this->configureNsswitch(true);

            // Update the domain information in the /etc/hosts file
            $this->hostname->updateDomain($domain);

            // Join the domain (net ads join) -- The password from the UI is base64 encoded
            $this->adsJoin($user, base64_decode($password), $dcOverride);

            // Restart the samba services (smbd, nmbd, winbindd)
            $this->restartSamba();

            // Delete the temporary backups if they are still present
            $this->filesystem->unlinkIfExists(self::SAMBA_CONF_BACKUP);
            $this->filesystem->unlinkIfExists(self::NSSWITCH_CONF_BACKUP);
        } catch (Throwable $exception) {
            $this->logger->error('DOM0002 Could not join Windows Domain. Restoring original Samba config.', [
                'domain' => $domain,
                'dcOverride' => $dcOverride,
                'exception' => $exception
            ]);

            // Restore the backed-up samba and nsswitch configuration files
            $this->filesystem->rename(self::SAMBA_CONF_BACKUP, self::SAMBA_CONF);
            $this->filesystem->rename(self::NSSWITCH_CONF_BACKUP, self::NSSWITCH_CONF);

            // Restore the hosts file to its non-domain configuration
            $this->hostname->updateDomain(Hostname::LOCAL_DOMAIN);

            // Restart Samba
            $this->restartSamba();

            // Re-throw
            throw $exception;
        }
    }

    /**
     * Leave the current Windows AD Domain, and restore Samba to it's non-domain configuration. Because cleanly
     * leaving a domain requires credentials (and these aren't necessarily the same credentials as it took to join),
     * we don't tell the DC that we're leaving (which would be done with `net ads leave`), we just configure the
     * device to its non-domain configuration. Remnants of the device will still remain on the Domain Controllers and
     * DNS servers.
     */
    public function leave(): void
    {
        if (!$this->inDomain()) {
            $this->logger->warning('DOM0012 Cannot leave domain. Not currently a domain member');
            return;
        }

        $this->logger->info('DOM0010 Leaving a Windows Domain');

        try {
            // Apply the non-domain configuration to the nsswitch file
            $this->configureNsswitch(false);

            // Reset our hostname back to the non-domain configuration
            $this->hostname->updateDomain(Hostname::LOCAL_DOMAIN);

            // Apply the non-domain configuration to Samba, reverting us to the default workgroup
            $this->configureSamba('', 'WORKGROUP');

            // Stop the samba services
            $this->stopSamba();

            // Flush the samba auth stored on the device
            $this->flushCachedAuth();
        } catch (Throwable $throwable) {
            $this->logger->warning('DOM0011 Error while leaving Windows Domain', ['exception' => $throwable]);
            throw $throwable;
        } finally {
            // Make sure we restart Samba regardless of the errors
            $this->restartSamba();
        }
    }

    /**
     * Whether we are currently configured to be joined to a Windows domain
     *
     * @return bool True if we are currently in a domain, false otherwise
     */
    public function inDomain(): bool
    {
        return !is_null($this->getDomain());
    }

    /**
     * Get the name of the Active Directory domain, if we are currently configured as a domain member
     *
     * @return string|null The name of the current domain, or null if we are not a member of an AD domain
     */
    public function getDomain(): ?string
    {
        $globalSection = $this->sambaManager->getSectionByName('global');
        if ($globalSection && $globalSection->getProperty('security') === 'ads') {
            return $globalSection->getProperty('realm');
        }
        return null;
    }

    /**
     * Get the Windows Workgroup in which this device advertises its shares
     *
     * @return string The name of the current Windows Workgroup
     */
    public function getWorkgroup(): string
    {
        $globalSection = $this->sambaManager->getSectionByName('global');
        return $globalSection->getProperty('workgroup');
    }

    /**
     * Configure the Windows Workgroup in which this device advertises its shares
     *
     * @param string $workgroup The name of the workgroup
     */
    public function setWorkgroup(string $workgroup): void
    {
        $this->logger->info('DOM0020 Setting Windows Workgroup', ['workgroup' => $workgroup]);
        if (strlen($workgroup) === 0 || strlen($workgroup) > self::MAX_WORKGROUP_LEN) {
            throw new RuntimeException('Invalid Workgroup');
        }

        // Update the Samba global section and sync the config
        $globalSection = $this->sambaManager->getSectionByName('global');
        $globalSection->setProperty('workgroup', $workgroup);
        $this->sambaManager->sync();
    }

    /**
     * Updates the Active Directory DNS records for this system. Should be done after a system IP address changes.
     */
    public function updateDns(): void
    {
        // Early return if we're not actually configured for domain membership
        if (!$this->inDomain()) {
            return;
        }

        $this->logger->info('DOM0030 Updating Active Directory DNS records');
        try {
            $this->processFactory->get(['net', 'ads', 'dns', 'register', '-P'])->mustRun();
        } catch (Throwable $ex) {
            $this->logger->warning('DOM0031 AD DNS Registration Failed, retrying');
            // Sometimes, this will fail, especially if the number of configured interfaces changes. If that
            // happens, an unregister/re-register generally fixes the problem
            $fqdn = sprintf('%s.%s', $this->hostname->get(), $this->getDomain());
            $this->processFactory->get(['net', 'ads', 'dns', 'unregister', $fqdn, '-P'])->mustRun();
            $this->processFactory->get(['net', 'ads', 'dns', 'register', '-P'])->mustRun();
        }
    }

    /**
     * Discover the AD DC server name to use for a given domain.
     *
     * @param string $domain
     * @return string
     */
    private function discoverServer(string $domain): string
    {
        // The output of this command is undocumented, but unchanged since it was implemented in 2008
        // https://github.com/samba-team/samba/blob/master/nsswitch/wbinfo.c#L743
        // The first line of output contains the primary AD server
        try {
            $output = $this->processFactory->get(['wbinfo', '--dsgetdcname', $domain])->mustRun()->getOutput();
            $server = explode(PHP_EOL, trim($output))[0];
        } catch (Throwable $throwable) {
            $this->logger->warning('DOM0040 Could not discover AD DC for Domain', [
                'domain' => $domain,
                'exception' => $throwable
            ]);
            $server = '';
        }
        $this->logger->info('DOM0041 Auto-detected AD DC for Domain', ['domain' => $domain, 'server' => $server]);
        return $server;
    }

    /**
     * Look up a bunch of information from the given AD DS Domain Controller
     *
     * @param string $server
     * @return array
     */
    private function adsLookup(string $server): array
    {
        // Use the `net ads lookup` command to get a bunch of information from the DC.
        $output = $this->processFactory->get(['net', 'ads', '--json', 'lookup', '-S', $server])->mustRun()->getOutput();
        $adsInfo = json_decode($output, true);
        $this->logger->info('DOM0050 ADS Lookup Successful', ['server' => $server, 'adsInfo' => $adsInfo]);
        return $adsInfo;
    }

    /**
     * Configures Samba for a given domain. Passing an empty string for the domain will imply that we are leaving
     * the domain
     *
     * @param string $domain The domain to join, or an empty string if we are leaving a domain
     * @param string $workgroup The workgroup to configure
     * @param string $passwordServer An (optional) password server to override DC configuration via DNS
     */
    private function configureSamba(string $domain, string $workgroup, string $passwordServer = ''): void
    {
        $this->logger->info('DOM0060 Updating Samba AD Domain configuration', [
            'domain' => $domain,
            'workgroup' => $workgroup,
            'passwordServer' => $passwordServer
        ]);

        $globalSection = $this->sambaManager->getSectionByName('global');

        // If the domain parameter is not empty, we're joining a domain, so apply the configuration
        if ($domain) {
            // Use Active Directory Services for Samba's Security validation
            $globalSection->setProperty('security', 'ads');

            // The Domain ("realm") to configure Samba as a member of
            $globalSection->setProperty('realm', $domain);

            // The legacy NetBIOS name of the domain when using ADS security (e.g. mydomain.example.com => MYDOMAIN)
            $globalSection->setProperty('workgroup', $workgroup);

            // The hard-coded Domain Controller to use. If this is not specified by the user, Samba will determine
            // the appropriate DC to use based on DNS, which requires less maintenance and is more robust, and
            // is recommended by the Samba team
            $globalSection->setProperty('password server', $passwordServer ?: '*');

            // Configure our ID Mapping to use the `tdb` (Trivial Database) backend, and map domain users to linux
            // UID/GID 10000-999999
            $globalSection->setProperty('idmap config * : backend', 'tdb');
            $globalSection->setProperty('idmap config * : range', '10000 - 999999');

            // The list of interfaces whose IP addresses we report to the AD DNS server when we do DNS updates.
            // The goal here is to add the interfaces that the device regularly has IP addresses on (ethernet, bridges,
            // and bonds), but NOT the QEMU/KVM interfaces (virbr, vnet), or any tun/tap interfaces from Hybrid virts
            $globalSection->setProperty('interfaces', 'eth* en* br* bond*');
        } else {
            // If the domain parameter was empty, it means we're leaving the domain, reset all the relevant settings
            // to their defaults
            $globalSection->setProperty('security', 'user');
            $globalSection->setProperty('workgroup', 'WORKGROUP');
            $globalSection->removeProperty('realm');
            $globalSection->removeProperty('password server');
            $globalSection->removeProperty('idmap config * : backend');
            $globalSection->removeProperty('idmap config * : range');
            $globalSection->removeProperty('interfaces');

            // We also want to remove all the domain users from any shares.
            $allUsers = $this->sambaManager->listAllShareUsers();
            foreach ($allUsers as $user) {
                if (strpos($user, '\\') !== false) {
                    $this->sambaManager->removeUserFromAllShares($user);
                }
            }
        }

        // Explicitly unset old or legacy settings that are no longer supported or recommended by the Samba team.
        // Note: These were originally set in the domain membership code in `web/lib/Network/Network.php`

        // These were replaced by `idmap config`
        // https://www.samba.org/samba/docs/current/man-html/smb.conf.5.html#IDMAPUID)
        $globalSection->removeProperty('idmap uid');
        $globalSection->removeProperty('idmap gid');

        // These can slow the system down dramatically, and since we don't ever look up users/groups with the
        // `getpwent()` or `getgent()` system calls, they should just be removed.
        // https://www.samba.org/samba/docs/current/man-html/smb.conf.5.html#WINBINDENUMUSERS
        $globalSection->removeProperty('winbind enum users');
        $globalSection->removeProperty('winbind enum groups');

        // Sync the samba configuration, which will test the parameters for validity and fail if any are invalid
        if (!$this->sambaManager->sync()) {
            throw new RuntimeException('Invalid Samba Configuration');
        }
    }

    /**
     * Configure the `/etc/nsswitch.conf` file for domain or standalone operation
     *
     * @param bool $domain true to apply the domain configuration, false for standalone
     * @note On RHEL-based systems, this should be updated to use `authselect` and choose the 'winbind' profile
     */
    private function configureNsswitch(bool $domain): void
    {
        $this->logger->info('DOM0060 Updating nsswitch configuration', ['domain' => $domain]);

        $NSSWITCH_TMPL = <<<EOF
# /etc/nsswitch.conf
#
# Example configuration of GNU Name Service Switch functionality.
# If you have the `glibc-doc-reference' and `info' packages installed, try:
# `info libc "Name Service Switch"' for information about this file.

passwd:         compat {{WINBIND}}
group:          compat {{WINBIND}}
shadow:         compat
gshadow:        files

hosts:          files mdns4_minimal [NOTFOUND=return] dns
networks:       files

protocols:      db files
services:       db files
ethers:         db files
rpc:            db files

netgroup:       nis
EOF;

        $contents = str_replace('{{WINBIND}}', $domain ? 'winbind' : '', $NSSWITCH_TMPL);
        $this->filesystem->filePutContents(self::NSSWITCH_CONF, $contents);
    }

    private function stopSamba(): void
    {
        $this->logger->info('DOM0070 Stopping Samba Services');
        $this->systemctl->stop(self::WINBIND_SERVICE);
        $this->systemctl->stop(self::NMBD_SERVICE);
        $this->systemctl->stop(self::SMBD_SERVICE);
    }

    private function restartSamba(): void
    {
        $this->logger->info('DOM0080 Restarting Samba Services');
        $this->systemctl->restart(self::SMBD_SERVICE);
        $this->systemctl->restart(self::NMBD_SERVICE);
        $this->systemctl->restart(self::WINBIND_SERVICE);
    }

    private function flushCachedAuth(): void
    {
        // Clear out the samba cache using `net cache flush`
        $this->processFactory->get(['net', 'cache', 'flush'])->mustRun();

        // Delete the Kerberos conf file, Samba will re-create it when it joins
        $this->filesystem->unlinkIfExists('/etc/krb5.conf');

        // Delete the secrets.tdb and any other tdbs that might have expired credentials or stale data
        $this->filesystem->unlinkIfExists('/var/lib/samba/private/secrets.tdb');
    }

    private function adsJoin(string $username, string $password, string $dcOverride = ''): void
    {
        $this->logger->info('DOM0090 Joining Domain');
        $cmdline = ['net', 'ads', 'join', '-U', $username];
        if ($dcOverride) {
            $cmdline = array_merge($cmdline, ['-S', $dcOverride]);
        }
        $this->processFactory->get($cmdline)
            ->setInput($password)
            ->mustRun();
    }
}
