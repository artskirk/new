<?php

namespace Datto\App\Console\Command\Config;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\App\Console\Input\InputArgumentException;
use Datto\Config\Login\LocalLoginService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Enable/disable local login to the device
 *
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class LocalLoginCommand extends AbstractCommand
{
    /** @var string */
    protected static $defaultName = 'config:login:local';

    /** @var LocalLoginService */
    private $localLoginService;

    public function __construct(
        LocalLoginService $localLoginService
    ) {
        parent::__construct();

        $this->localLoginService = $localLoginService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_REMOTE_WEB
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Enable/disable local login to the device')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Valid actions are: "status", "enable", "disable", "on", and "off". ' .
                'Leave blank to see whether local login is enabled or disabled.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower($input->getArgument('action'));
        if (strlen($action) === 0 || $action == 'status') {
            $output->writeln('Local login is ' .
                ($this->localLoginService->isEnabled() ? 'enabled' : 'disabled'));
        } elseif (preg_match('/^(enable|on)$/', $action)) {
            $this->localLoginService->enable();
        } elseif (preg_match('/^(disable|off)$/', $action)) {
            $this->localLoginService->disable();
        } else {
            throw new InputArgumentException('Valid actions: "status", "enable", "disable", "on", and "off"');
        }
        return 0;
    }
}
