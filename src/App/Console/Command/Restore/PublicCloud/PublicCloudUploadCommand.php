<?php

namespace Datto\App\Console\Command\Restore\PublicCloud;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudManager;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command builds a single VHD file containing all of the volumes of
 *   a given snapshot for a given agent and uploads it to the public cloud provider
 *   that this siris is running on. Currently only Azure is supported.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudUploadCommand extends BasePublicCloudCommand
{
    protected static $defaultName = 'restore:public:upload';

    /** @var PublicCloudManager */
    private $publicCloudManager;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        CommandValidator $commandValidator,
        PublicCloudManager $publicCloudManager,
        Filesystem $filesystem
    ) {
        $this->publicCloudManager = $publicCloudManager;
        $this->filesystem = $filesystem;
        parent::__construct($commandValidator);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setDescription('Build and upload a VHD to the public cloud.')
            ->addArgument(
                'sasURIFile',
                InputArgument::OPTIONAL,
                'Secret file containing the URI to which the VHD should be uploaded, will not upload if empty.'
            )
            ->addOption(
                'enable-agent',
                null,
                InputOption::VALUE_NONE,
                'Use this option to specify whether the restored system should include a running agent EXE, defaults to False'
            )
            ->addOption(
                'no-remove',
                'r',
                InputOption::VALUE_NONE,
                'Use this option to suppress removal of the VHD file when this command completes.'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentKey = $input->getArgument('agent');
        $sasURIFile = $input->getArgument('sasURIFile');
        $snapshot = $input->getArgument('snapshot');
        $vmGeneration = $input->getArgument('vmGeneration');
        $remove = !$input->getOption('no-remove');
        $enableAgentInRestoredVm = $input->getOption('enable-agent');

        $sasURIMap = null;
        if ($sasURIFile) {
            $sasURIMap = $this->filesystem->fileGetContents($sasURIFile);
            $this->filesystem->unlink($sasURIFile);
        }

        $decodedSasURIMap = $sasURIMap ? json_decode($sasURIMap, true) : [];

        if (is_null($decodedSasURIMap)) {
            throw new Exception('Provided SAS URI map was not valid JSON');
        }

        $this->publicCloudManager->build(
            $agentKey,
            $snapshot,
            $vmGeneration,
            $enableAgentInRestoredVm,
            $decodedSasURIMap,
            $remove
        );
        return 0;
    }
}
