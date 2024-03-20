<?php

namespace Datto\App\Console\Command\System\Ssh;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Registration\SshKeyService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class SshGenerateKeysCommand extends AbstractCommand
{
    protected static $defaultName = 'system:ssh:generatekeys';

    /** @var SshKeyService */
    private $sshKeyService;

    /**
     * @inheritDoc
     */
    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_SSH_AUTO_GENERATE_KEYS];
    }

    public function __construct(SshKeyService $sshKeyService)
    {
        parent::__construct();
        $this->sshKeyService = $sshKeyService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription("Generate SSH keys if they don't exist");
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sshKeyService->autoGenerateSshKey();
        return 0;
    }
}
