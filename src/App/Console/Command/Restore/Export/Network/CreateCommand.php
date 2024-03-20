<?php

namespace Datto\App\Console\Command\Restore\Export\Network;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Feature\FeatureService;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Network\NetworkExportService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CreateCommand
 *
 * Implements snapctl command to export images using network shares.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateCommand extends AbstractExportCommand
{
    protected static $defaultName = 'export:network:create';

    const AGENT_ARG_MESSAGE = 'UUID of the agent for which images need to be exported.';
    const SNAPSHOT_ARG_MESSAGE = 'Snapshot of the agent for which images need to be exported.';
    const TYPE_ARG_MESSAGE = 'Type of image you are exporting';
    const BOOT_TYPE_ARG_MESSAGE = 'Type of boot system, either UEFI, BIOS, or AUTO.' .
    'AUTO detects boot type from the OS partition type.';

    /** @var TempAccessService */
    private $tempAccess;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        TempAccessService $tempAccess,
        EncryptionService $encryptionService,
        CommandValidator $validator,
        NetworkExportService $exportService,
        AgentService $agentService
    ) {
        parent::__construct($validator, $exportService, $agentService);

        $this->tempAccess = $tempAccess;
        $this->encryptionService = $encryptionService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_IMAGE_EXPORT];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Exports images through network share.')
            ->addArgument('agent', InputArgument::REQUIRED, self::AGENT_ARG_MESSAGE)
            ->addArgument('snapshot', InputArgument::REQUIRED, self::SNAPSHOT_ARG_MESSAGE)
            ->addArgument('type', InputArgument::REQUIRED, self::TYPE_ARG_MESSAGE)
            ->addOption('boot-type', 'b', InputOption::VALUE_REQUIRED, self::BOOT_TYPE_ARG_MESSAGE);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentName = $input->getArgument('agent');
        $snapshotEpoch = $input->getArgument('snapshot');
        $type = ImageType::get($input->getArgument('type'));
        $bootType = BootType::AUTO();
        $agent = $this->agentService->get($agentName);
        $noInteraction = $input->getOption('no-interaction');

        if ($noInteraction) {
            $this->logger->setAssetContext($agentName);
            $this->logger->debug("EXP0002 Network export called without interaction.");
        }

        if ($input->getOption('boot-type')) {
            $bootType = BootType::get(strtolower(trim($input->getOption('boot-type'))));
        }

        // TODO: this really should just be prompting for password, the unseal logic should be part of a service class
        // NOTE: skipPromptWhenNonInteractive is true because this command is run non-interactive in a screen by the UI.
        // It has already been unsealed in that case.
        $this->unsealAgentIfRequired(
            $agent,
            $this->encryptionService,
            $this->tempAccess,
            $input,
            $output,
            $skipPromptWhenNonInteractive = true
        );

        $output->writeln('creating image export');
        $this->exportService->export($agentName, $snapshotEpoch, $type, $bootType);
        $output->writeln('done');
        return 0;
    }
}
