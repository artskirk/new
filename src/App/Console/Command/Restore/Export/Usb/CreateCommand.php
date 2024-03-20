<?php

namespace Datto\App\Console\Command\Restore\Export\Usb;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Feature\FeatureService;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Usb\UsbExportService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to start an image export to a USB drive.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CreateCommand extends AbstractCommand
{
    protected static $defaultName = 'export:usb:create';

    /** @var UsbExportService */
    private $usbExportService;

    /** @var AgentService */
    private $agentService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        UsbExportService $exportService,
        AgentService $agentService,
        TempAccessService $tempAccessService,
        EncryptionService $encryptionService
    ) {
        parent::__construct();

        $this->usbExportService = $exportService;
        $this->agentService = $agentService;
        $this->tempAccessService = $tempAccessService;
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
        $bootTypeMessage =
            'Type of boot system, either UEFI, BIOS, or AUTO. ' .
            'AUTO detects boot type from the OS partition type.';
        $this
            ->setDescription("Exports images to a USB drive")
            ->addArgument('agent', InputArgument::REQUIRED, 'UUID of agent for which to export image')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot for which to export image')
            ->addArgument('format', InputArgument::REQUIRED, 'The format of image to export')
            ->addOption('boot-type', 'b', InputOption::VALUE_REQUIRED, $bootTypeMessage);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('agent');
        $agent = $this->agentService->get($assetKey);
        $snapshot = $input->getArgument('snapshot');
        $format = $input->getArgument('format');
        $type = $input->getOption('boot-type');
        $noInteraction = $input->getOption('no-interaction');

        if ($noInteraction) {
            $this->logger->info("EXP0001 Usb export called without interaction.");
        }

        $imageType = ImageType::get($format);
        $bootType = BootType::AUTO();
        if ($type) {
            $bootType = BootType::get(strtolower($type));
        }

        // TODO: this really should just be prompting for password, the unseal logic should be part of a service class
        // NOTE: skipPromptWhenNonInteractive is true because this command is run non-interactive in a screen by the UI.
        // It has already been unsealed in that case.
        $this->unsealAgentIfRequired(
            $agent,
            $this->encryptionService,
            $this->tempAccessService,
            $input,
            $output,
            $skipPromptWhenNonInteractive = true
        );

        $this->usbExportService->exportImage($assetKey, $snapshot, $imageType, $bootType);
        return 0;
    }
}
