<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Log\LoggerAwareTrait;
use Datto\Websockify\WebsockifyPortToTokenService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Translates VNC ports to websockify tokens. This command is called every time a noVNC connection from
 * remote.datto.com (partner portal, etc.) is made to this device.
 *
 * If you're experiencing problems with empty tokens take a look in journald; websockify
 * has pretty extensive logging itself.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class WebsockifyPortToTokenCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'websockify:token:get';

    /** @var WebsockifyPortToTokenService */
    private $websockifyPortToTokenService;

    public function __construct(
        WebsockifyPortToTokenService $websockifyPortToTokenService
    ) {
        parent::__construct();

        $this->websockifyPortToTokenService = $websockifyPortToTokenService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Get websockify VNC token from port. Intended to be called by apache on noVNC websocket requests from remote.datto.com.')
            ->addArgument('port', InputArgument::REQUIRED, 'VNC port of the VM');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getArgument('port');

        // Output is being read directly by apache; don't let warnings from libvirt-php or anything else
        // get printed to STDOUT
        ob_start();

        $token = 'NULL'; // Magic value that lets apache know the lookup failed
        try {
            $port = (int)$port;
            $token = $this->websockifyPortToTokenService->getToken($port);

            $this->logger->info("WFY0001 Got noVNC request for port, proxying to websockify token", ['port' => $port, 'token' => $token]);
        } catch (Exception $e) {
            $this->logger->error("WFY0002 Got noVNC request for port but encountered an error", ['port' => $port, 'error' => $e->getMessage()]);
        }

        $errorString = ob_get_contents();
        ob_end_clean();

        if ($errorString) {
            $this->logger->error('WFY0003 Websockify error', ['error' => $errorString]);
        }

        $output->writeln($token);
        return 0;
    }
}
