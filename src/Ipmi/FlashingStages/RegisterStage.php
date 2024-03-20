<?php

namespace Datto\Ipmi\FlashingStages;

use Datto\Ipmi\IpmiRegistrar;
use Datto\System\ModuleManager;
use Datto\System\Transaction\Stage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Stage that handles registering and unregistering IPMI.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RegisterStage implements Stage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ModuleManager */
    private $moduleManager;

    /** @var IpmiRegistrar */
    private $registrar;

    /** @var bool */
    private $shouldRegister;

    /**
     * @param DeviceLoggerInterface $logger
     * @param ModuleManager $moduleManager
     * @param IpmiRegistrar $registrar
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        ModuleManager $moduleManager,
        IpmiRegistrar $registrar
    ) {
        $this->logger = $logger;
        $this->moduleManager = $moduleManager;
        $this->registrar = $registrar;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        // not yet implemented
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->shouldRegister = !$this->registrar->isRegistered();

        $this->moduleManager->probe('ipmi_devintf');
        $this->moduleManager->probe('ipmi_si');

        if ($this->shouldRegister) {
            $this->logger->info("RSG0001 Registering IPMI ...");
            $this->registrar->register();
        } else {
            $this->logger->info("RSG0002 IPMI is already registered, skipping.");
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // Nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        if ($this->shouldRegister) {
            try {
                $this->logger->info("RSG0003 Rolling back IPMI registration ...");
                $this->registrar->unregister();
            } catch (\Throwable $e) {
                $this->logger->notice("RSG0004 Could not rollback IPMI registration", ['exception' => $e]);
            }
        }
    }
}
