<?php

namespace Datto\App\Console\Command\System;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Common\Resource\DateTimeResource;
use Datto\Config\DeviceConfig;
use Datto\Resource\DateTimeService;
use Datto\Utility\Systemd\Systemctl;
use Datto\Utility\User\Passwd;
use Datto\Utility\User\UserMod;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Disables the root password if the necessary conditions have been met.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class DisableRootPassword extends AbstractCommand
{
    protected static $defaultName = 'system:disableRootPassword';
    /** @var string The systemd timer that triggers the service that calls this command. */
    private const TIMER_NAME = 'datto-disable-root-password.timer';

    private Passwd $passwd;
    private UserMod $userMod;
    private DeviceConfig $deviceConfig;
    private DateTimeService $dateTimeService;
    private Systemctl $systemctl;

    public function __construct(
        Passwd $passwd,
        UserMod $userMod,
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        Systemctl $systemctl
    ) {
        parent::__construct();

        $this->passwd = $passwd;
        $this->userMod = $userMod;
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeService = $dateTimeService;
        $this->systemctl = $systemctl;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    public function multipleInstancesAllowed(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        $this->setDescription('Disables the root password if the necessary conditions have been met.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($this->passwd->isPasswordDisabled('root')) {
                // This service runs on boot to verify the password stays disabled. If it is, we can stop this service.
                $this->logger->debug('DRP0005 root\'s password is disabled');
                $this->stopTimerForService();
                return 0;
            }
            if ($this->deviceConfig->has(DeviceConfig::KEY_NO_ALTER_ROOT_PASS)) {
                $this->logger->debug('DRP0007 ' . DeviceConfig::KEY_NO_ALTER_ROOT_PASS . ' is set');
                $this->stopTimerForService();
                return 0;
            }
            if ($this->passwd->isPasswordDisabled('backup-admin')) {
                // We allow root login if backup-admin's password has not yet been enabled.
                $this->logger->info(
                    'DRP0001 backup-admin\'s password is disabled. Can\'t disable root password until ' .
                    'backup-admin is available.'
                );
                return 0;
            }
            $imagingDate = intval($this->deviceConfig->get(DeviceConfig::KEY_IMAGING_DATE));
            $imagedInLastHour = $imagingDate > $this->dateTimeService->getTime() - DateTimeResource::SECONDS_PER_HOUR;
            if (!$this->deviceConfig->isConverted() && $imagedInLastHour) {
                // The build team logs in with root after imaging to run diagnostics. Give them 1 hour to log in.
                $this->logger->info('DRP0002 Device was imaged via CLI within the last hour');
                return 0;
            }
            $this->userMod->disablePasswordLogin('root');
            $this->logger->info('DRP0003 Disabled password login for root user');
            $this->stopTimerForService();
            return 0;
        } catch (Throwable $e) {
            $this->logger->error('DRP0006 Error disabling root password', ['error' => $e->getMessage()]);
            return 1;
        }
    }

    /**
     * Stops the timer that triggers the service that calls this command.
     */
    private function stopTimerForService(): void
    {
        $this->systemctl->stop(self::TIMER_NAME);
        $this->logger->debug('DRP0004 Stopped ' . self::TIMER_NAME);
    }
}
