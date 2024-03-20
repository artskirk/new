<?php

namespace Datto\App\Console\Command\Email;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Util\Email\TestEmailService;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command for sending test emails.
 *
 * @author Jack Corrigan <jcorrigan@datto.com>
 */
class TestEmailCommand extends AbstractCommand
{
    protected static $defaultName = 'email:test';

    /** @var TestEmailService */
    private $testEmailService;

    public function __construct(
        TestEmailService $testEmailService
    ) {
        parent::__construct();

        $this->testEmailService = $testEmailService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ALERTING
        ];
    }

    /**
     * Configure the console command.
     */
    protected function configure()
    {
        $this
            ->setDescription('Sends a test email of the given type. Types include: critical, missed, warning, screenshots, logs.')
            ->addArgument('assetKeyName', InputArgument::REQUIRED, 'The asset key name')
            ->addArgument('type', InputArgument::REQUIRED, 'The type of test email');
    }

    /**
     * Parse the command and call associated function from TestEmailService.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('assetKeyName');
        $type = $input->getArgument('type');

        switch ($type) {
            case 'critical':
                $this->testEmailService->sendTestCritical($assetKeyName);
                break;
            case 'missed':
            case 'warning':
                $this->testEmailService->sendTestWarning($assetKeyName);
                break;
            case 'screenshots':
                $this->testEmailService->sendTestScreenshots($assetKeyName);
                break;
            case 'logs':
                $this->testEmailService->sendTestLogReport($assetKeyName);
                break;
            default:
                throw new Exception('Invalid type. Supported test email types are: critical, missed, warning, screenshots, logs.');
        }
        return 0;
    }
}
