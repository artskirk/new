<?php

namespace Datto\App\Console\Command\Agent\Encryption\Dm;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DmCryptManager;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attaches an encrypted image to dm-crypt, providing access
 * to the unencrypted contents (the agent must already be unsealed).
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class AttachCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:dm:attach';

    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var DmCryptManager */
    private $dmCryptManager;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        EncryptionService $encryptionService,
        AgentService $agentService,
        TempAccessService $tempAccessService,
        DmCryptManager $dmCryptManager,
        Filesystem $filesystem
    ) {
        parent::__construct($agentService);

        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->dmCryptManager = $dmCryptManager;
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Attach an encrypted image to dm-crypt')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the encrypted image to attach');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->filesystem->realpath($input->getArgument('path'));

        // Sanity check path
        if ($path === false) {
            throw new Exception('The given path does not exist');
        } elseif ($this->filesystem->isFile($path) === false) {
            throw new Exception('The given path is not a file');
        } elseif ($this->filesystem->extension($path) !== 'detto') {
            throw new Exception('The given path does not end in \'.detto\'');
        }

        $directoryPath = dirname($path);
        $directoryName = basename($directoryPath);

        // Figure out the agent name from the path
        if (strpos($directoryPath, ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET_PATH . '/') === 0) {
            $agentName = $directoryName;
        } elseif (strpos($directoryPath, ZfsDatasetService::HOMEPOOL_DATASET_PATH . '/') === 0) {
            /*
             * Removes the restore type suffix from the clone name.
             * E.g. '66db8c3a6dde48bbaef81a5a56982a3e-active' -> '66db8c3a6dde48bbaef81a5a56982a3e'
             */
            $agentName = implode('-', explode('-', $directoryName, -1));
        } else {
            throw new Exception('Could not determine agent name from path');
        }

        // CP-11115: Attach the image to a dm-crypt device if crypto temp access enabled
        // or if passphrase is entered.
        $agent = $this->agentService->get($agentName);
        if (!$agent->getEncryption()->isEnabled()) {
            throw new Exception('Agent for provided image file is not encrypted.');
        }

        // TODO: this really should just be prompting for password, the unseal logic should be part of a service class
        $this->unsealAgentIfRequired(
            $agent,
            $this->encryptionService,
            $this->tempAccessService,
            $input,
            $output
        );

        $agentCryptKey = $this->encryptionService->getAgentCryptKey($agentName);
        $dmPath = $this->dmCryptManager->attach($path, $agentCryptKey);
        $output->writeln($dmPath);
        return 0;
    }
}
