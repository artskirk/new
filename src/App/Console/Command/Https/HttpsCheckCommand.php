<?php

namespace Datto\App\Console\Command\Https;

use Datto\Https\HttpsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command renews the Let's Encrypt certificate
 * if necessary.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class HttpsCheckCommand extends Command
{
    protected static $defaultName = 'https:check';

    /** @var HttpsService */
    private $httpsService;

    public function __construct(
        HttpsService $httpsService
    ) {
        parent::__construct();

        $this->httpsService = $httpsService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Check certificate, renew it if necessary and check connectivity to HTTPS URL.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force certificate renewal');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $this->httpsService->check($force);
        return 0;
    }
}
