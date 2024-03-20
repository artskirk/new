<?php

namespace Datto\App\Console\Command\Restore\Export\Network;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Network\NetworkExportService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CreateCommand
 *
 * Implements snapctl command to export images using network shares.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoveCommand extends AbstractExportCommand
{
    protected static $defaultName = 'export:network:remove';

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_IMAGE_EXPORT];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $agentArgMessage = 'Hostname or IP address of the agent the exported share of which needs to be removed.';
        $snapshotArgMessage = 'Snapshot of the agent the exported share of which needs to be removed.';
        $typeArgMessage = 'Image type of the export which needs to be removed (use "all" to remove all).';
        $this
            ->setDescription('Remove images that have been shared over a network share.')
            ->addArgument('agent', InputArgument::REQUIRED, $agentArgMessage)
            ->addArgument('snapshot', InputArgument::REQUIRED, $snapshotArgMessage)
            ->addArgument('type', InputArgument::REQUIRED, $typeArgMessage);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentName = $input->getArgument('agent');
        $snapshotEpoch = $input->getArgument('snapshot');
        $imageType = $input->getArgument('type');

        if (strtolower($imageType) === 'all') {
            $output->writeln('removing all image exports');
            $this->exportService->removeAll($agentName, $snapshotEpoch);
        } else {
            $type = ImageType::get($input->getArgument('type'));
            $this->exportService->remove($agentName, $snapshotEpoch, $type);
        }
        $output->writeln('done');
        return 0;
    }
}
