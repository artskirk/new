<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Volume;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Common\Resource\Filesystem;
use Datto\Core\Network\DeviceAddress;
use Datto\Restore\RestoreRepository;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\DateTimeZoneService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Throwable;

/**
 * Controller for the File Restore page
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class FileRestoreController extends AbstractBaseController
{
    private AssetService $assetService;
    private DeviceAddress $deviceAddress;
    private RestoreRepository $restoreRepository;
    private DateTimeZoneService $dateService;
    private AgentSnapshotService $agentSnapshotService;
    private TempAccessService $tempAccessService;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        RestoreRepository $restoreRepository,
        DateTimeZoneService $dateService,
        AgentSnapshotService $agentSnapshotService,
        TempAccessService $tempAccessService,
        DeviceAddress $deviceAddress,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
        $this->restoreRepository = $restoreRepository;
        $this->dateService = $dateService;
        $this->agentSnapshotService = $agentSnapshotService;
        $this->tempAccessService = $tempAccessService;
        $this->deviceAddress = $deviceAddress;
    }

    /**
     * Render the file restore configure page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_FILE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_FILE_READ")
     *
     * @param string $assetKeyName key name of the asset to restore
     * @param int|string $point snapshot point to restore to
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function configureAction($assetKeyName, $point)
    {
        $point = (int) $point;
        $pointDate = date($this->dateService->localizedDateFormat('time-date-hyphenated'), $point);
        $asset = $this->assetService->get($assetKeyName);
        $hostname = $asset instanceof Agent ? $asset->getHostname() : $asset->getName();
        $pairName = $asset->getPairName();

        $passphraseIsRequired = $asset instanceof Agent &&
            $asset->getEncryption()->isEnabled() && !$this->tempAccessService->isCryptTempAccessEnabled($assetKeyName);
        $restores = $this->restoreRepository->getAll();
        $isMounted = isset($restores[$assetKeyName . $point . 'file']);

        $hasRefsInSnapshot = $this->hasRefsVolumesInSnapshot($asset, $point);

        return $this->render(
            'Restore/File/configure.html.twig',
            [
                'keyName' => $assetKeyName,
                'hostname' => $hostname,
                'pairName' => $pairName,
                'point' => $point,
                'passphraseIsRequired' => $passphraseIsRequired,
                'sambaUris' => $this->getSambaUris($hostname, $pointDate),
                'webShareUrl' => $this->generateUrl('restore_file_browse', [
                    'assetKey' => $assetKeyName,
                    'point' => $point,
                    'path' => ''
                ]),
                'isMounted' => $isMounted,
                'containsRefsVolumes' => $hasRefsInSnapshot
            ]
        );
    }

    /**
     * @return string[]
     */
    private function getSambaUris(string $hostname, string $pointDate): array
    {
        $deviceIPs = $this->deviceAddress->getActiveIpAddresses();

        return array_map(fn($ip) => "\\\\$ip\\$hostname-$pointDate", $deviceIPs);
    }

    /**
     * Returns whether the asset has backed up ReFS volumes for the particular snapshot $point
     */
    private function hasRefsVolumesInSnapshot(Asset $asset, int $point): bool
    {
        try {
            $agentSnapshot = $this->agentSnapshotService->get($asset->getKeyName(), $point);
            $volumes = $agentSnapshot->getVolumes()->getArrayCopy();
            foreach ($volumes as $volume) {
                if ($volume->getFilesystem() === Volume::FILESYSTEM_REFS) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // don't break file restore if we can't determine whether the asset contains ReFS volumes
        }
        return false;
    }
}
