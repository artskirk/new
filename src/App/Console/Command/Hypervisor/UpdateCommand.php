<?php

namespace Datto\App\Console\Command\Hypervisor;

use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to update esx and hyper-v hypervisor connection information
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class UpdateCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'hypervisor:update';

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var HvConnectionService */
    private $hvConnectionService;

    public function __construct(
        EsxConnectionService $esxConnectionService,
        HvConnectionService $hvConnectionService
    ) {
        parent::__construct();

        $this->esxConnectionService = $esxConnectionService;
        $this->hvConnectionService = $hvConnectionService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Update All Connections with fresh info from system, eg esxVersion');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->debug("HYP0001 Updating hypervisor connections.");
        $this->esxConnectionService->refreshAll();
        $this->hvConnectionService->refreshAll();
        return 0;
    }

    public function isHidden(): bool
    {
        return true;
    }
}
