<?php

namespace Datto\App\Console\Command\Restore\Differential;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Restore\RestoreType;
use Datto\Util\ScriptInputHandler;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a differential rollback target.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class CreateCommand extends AbstractCommand
{
    protected static $defaultName = 'restore:differential:create';

    /** @var DifferentialRollbackService */
    private $differentialRollbackService;

    /** @var AssetService */
    private $assetService;

    /** @var ScriptInputHandler */
    private $inputHelper;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var TempAccessService */
    private $tempAccessService;

    public function __construct(
        DifferentialRollbackService $differentialRollbackService,
        AssetService $assetService,
        ScriptInputHandler $inputHelper,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService
    ) {
        parent::__construct();

        $this->differentialRollbackService = $differentialRollbackService;
        $this->assetService = $assetService;
        $this->inputHelper = $inputHelper;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a differential rollback target')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to restore')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot to restore');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $asset = $this->assetService->get($assetKey);
        $snapshot = $this->getSnapshot($assetKey, $input->getArgument('snapshot'));

        $passphrase = $this->promptAgentPassphraseIfRequired(
            $asset,
            $this->tempAccessService,
            $input,
            $output
        );
        $suffix = RestoreType::DIFFERENTIAL_ROLLBACK;

        $this->differentialRollbackService->create($assetKey, $snapshot, $suffix, $passphrase);
        return 0;
    }

    /**
     * @param string $assetKey
     * @param int|null $snapshot
     * @return int
     */
    private function getSnapshot(string $assetKey, int $snapshot = null): int
    {
        if ($snapshot !== null) {
            return $snapshot;
        }

        $asset = $this->assetService->get($assetKey);
        $lastPoint = $asset->getLocal()->getRecoveryPoints()->getLast();

        if ($lastPoint === null) {
            throw new Exception("No recovery points available for $assetKey");
        }

        return $lastPoint->getEpoch();
    }
}
