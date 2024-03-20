<?php

namespace Datto\App\Console\Command\Asset\Verification;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\SnapctlApplication;
use Datto\Asset\AssetService;
use Datto\Utility\Screen;
use Datto\Verification\VerificationRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run verification on the next asset in the verification queue, if there is one.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class VerificationQueueCheckCommand extends AbstractVerificationCommand
{
    /* This is a well-known name used by technical support */
    const SCREEN_SESSION_NAME = 'screenshotHandler';

    const SCREEN_OPTION_NAME = 'screen';

    protected static $defaultName = 'asset:verification:queue:check';

    /** @var VerificationRunner */
    private $verificationRunner;

    /** @var Screen */
    private $screen;

    public function __construct(
        VerificationRunner $verificationRunner,
        Screen $screen,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->verificationRunner = $verificationRunner;
        $this->screen = $screen;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Run verification on the next asset in the verification queue, if there is one.')
             ->addOption(
                 self::SCREEN_OPTION_NAME,
                 null,
                 InputOption::VALUE_NONE,
                 'Run in a single-instance screen with name "' . self::SCREEN_SESSION_NAME . '"'
             );
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $runInScreen = $input->getOption(self::SCREEN_OPTION_NAME);

        if ($runInScreen) {
            $this->executeInScreen();
        } else {
            $this->verificationRunner->runNextQueuedVerification();
        }
        return 0;
    }

    /**
     * Run the verification in a single-instance screen background process.
     */
    private function executeInScreen(): void
    {
        if (!$this->screen->isScreenRunning(self::SCREEN_SESSION_NAME)) {
            $this->logger->debug('VER0121 Running the verification in screen "' . self::SCREEN_SESSION_NAME . '"');
            $command = [SnapctlApplication::EXECUTABLE_NAME, self::$defaultName];
            $this->screen->runInBackground($command, self::SCREEN_SESSION_NAME, false);
        } else {
            $this->logger->debug('VER0122 Verification is currently running in screen "' . self::SCREEN_SESSION_NAME . '" -- Skipping');
        }
    }
}
