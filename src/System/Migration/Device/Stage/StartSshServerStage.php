<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Ssh\SshClient;
use Datto\Log\DeviceLoggerInterface;

/**
 * Start and stop the remote ssh server
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StartSshServerStage extends AbstractMigrationStage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SshClient */
    private $sshClient;

    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        SshClient $sshClient
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->sshClient = $sshClient;
    }

    /**
     * @inheritdoc
     * Start the remote ssh server
     */
    public function commit()
    {
        $this->sshClient->startRemoteSshServer();
    }

    /**
     * @inheritdoc
     * Stop the remote ssh server
     */
    public function cleanup()
    {
        $this->sshClient->stopRemoteSshServer();
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $this->cleanup();
    }
}
