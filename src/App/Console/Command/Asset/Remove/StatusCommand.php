<?php

namespace Datto\App\Console\Command\Asset\Remove;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetRemovalStatus;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class StatusCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:remove:status';

    /** @var AssetRemovalService */
    private $assetRemovalService;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        AssetRemovalService $assetRemovalService,
        DateTimeService $dateTimeService
    ) {
        parent::__construct();

        $this->assetRemovalService = $assetRemovalService;
        $this->dateTimeService = $dateTimeService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_ASSETS];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Get asset removal statuses');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statuses = $this->assetRemovalService->getAssetRemovalStatuses();

        $table = new Table($output);
        $table->setHeaders([
            'Asset Key',
            'Status',
            'Metadata'
        ]);

        foreach ($statuses as $assetKey => $status) {
            $row = [$assetKey, $status->getState()];

            switch ($status->getState()) {
                case AssetRemovalStatus::STATE_PENDING:
                    break;
                case AssetRemovalStatus::STATE_REMOVING:
                    $row[] = sprintf('pid: %d', $status->getPid());
                    break;
                case AssetRemovalStatus::STATE_REMOVED:
                    $row[] = sprintf('date: %s', $this->dateTimeService->format('c', $status->getRemovedAt()));
                    break;
                case AssetRemovalStatus::STATE_ERROR:
                    $row[] = sprintf('code: %d, message: %s', $status->getErrorCode(), $status->getErrorMessage());
                    break;
            }

            $table->addRow($row);
        }

        $table->render();
        return 0;
    }
}
