<?php
namespace Datto\App\Console\Command\Alerts;

use Datto\Config\DeviceConfig;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\RebootEmailGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class implements a snapctl command to send an email notification to the
 * device user notifying them that the scheduled device reboot was successful.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class RebootAlertCommand extends Command
{
    protected static $defaultName = 'alerts:rebootalert';

    const SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_KEY = 'successfulScheduledRebootIndicator';

    /** @var  DeviceConfig deviceConfig */
    private $deviceConfig;

    /** @var  EmailService $emailService */
    private $emailService;

    /** @var RebootEmailGenerator */
    private $rebootEmailGenerator;

    public function __construct(
        DeviceConfig $deviceConfig,
        EmailService $emailService,
        RebootEmailGenerator $rebootEmailGenerator
    ) {
        parent::__construct();

        $this->deviceConfig = $deviceConfig;
        $this->emailService = $emailService;
        $this->rebootEmailGenerator = $rebootEmailGenerator;
    }

    /**
     * Sets this commands name and description.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Emails user on successful scheduled reboot.');
    }

    /**
     * Executes the email notification logic.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     * @return int 0 if everything went fine, or an exit code
     */
    public function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $retCode = 0; // Success
        // Only send alert if the config key is present
        if ($this->deviceConfig->has(self::SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_KEY)) {
            $email = $this->rebootEmailGenerator->generate();
            $sent = $this->emailService->sendEmail($email);

            if ($sent) {
                $this->deviceConfig->clear($this::SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_KEY);
            } else {
                $retCode = 1; //Error
            }
        }

        return $retCode;
    }
}
