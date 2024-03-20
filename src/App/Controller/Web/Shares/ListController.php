<?php

namespace Datto\App\Controller\Web\Shares;

use Datto\Alert\AlertManager;
use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetType;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Asset\Share\Zfs\ZfsShare;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Core\Network\DeviceAddress;
use Datto\Log\LoggerAwareTrait;
use Datto\Replication\ReplicationDevices;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles requests for the main shares page (list of shares).
 * @author Philipp Heckel <ph@datto.com>
 */
class ListController extends AbstractBaseController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ShareService $service;
    private DeviceAddress $deviceAddress;
    private DeviceConfig $deviceConfig;
    private CreateShareService $createShareService;
    private AlertManager $alertManager;
    private DeviceState $deviceState;

    public function __construct(
        NetworkService $networkService,
        DeviceConfig $deviceConfig,
        CreateShareService $createShareService,
        DeviceState $deviceState,
        ShareService $service,
        DeviceAddress $deviceAddress,
        AlertManager $alertManager,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);
        $this->deviceConfig = $deviceConfig;
        $this->createShareService = $createShareService;
        $this->deviceState = $deviceState;
        $this->service = $service;
        $this->deviceAddress = $deviceAddress;
        $this->alertManager = $alertManager;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(Request $request)
    {
        $parameters = $this->getParameters();

        if (count($parameters['shares']) > 0 || !$this->isGranted('PERMISSION_SHARE_CREATE')) {
            return $this->render(
                'Shares/List/index.html.twig',
                $parameters
            );
        } elseif (strpos($request->headers->get('referer'), $this->generateUrl('shares_add')) !== false) {
            // If we're coming from the shares add page, don't redirect back to it and cause an inescapable loop
            return $this->redirect($this->generateUrl('homepage'));
        } else {
            return $this->redirect($this->generateUrl('shares_add'));
        }
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param string $newShareName name of a newly added share (to display an alert on the page)
     * @param string $newShareKey
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addSuccessAction(string $newShareName, string $newShareKey)
    {
        $parameters = $this->getParameters();
        $parameters['addSuccess'] = $this->service->exists($newShareKey);
        $parameters['newShareName'] = $newShareName;

        return $this->render(
            'Shares/List/index.html.twig',
            $parameters
        );
    }

    private function getShares(): array
    {
        $viewShares = array();

        $shares = $this->service->getAll();
        foreach ($shares as $share) {
            $viewShare = null;

            if ($share instanceof NasShare) {
                $viewShare = $this->buildViewNasShare($share);
            } elseif ($share instanceof IscsiShare) {
                $viewShare = $this->buildViewIscsiShare($share);
            } elseif ($share instanceof ExternalNasShare) {
                $viewShare = $this->buildViewExternalNasShare($share);
            } elseif ($share instanceof ZfsShare) {
                $viewShare = $this->buildViewZfsShare($share);
            }

            if ($viewShare) {
                $viewShares[] = $viewShare;
            }
        }

        return $viewShares;
    }

    private function getParameters(): array
    {
        $shares = $this->getShares();

        return array(
            'inhibitAllCron' => $this->deviceConfig->get('inhibitAllCron'),
            'shares' => $shares,
            'types' => array(
                'nas' => AssetType::NAS_SHARE,
                'iscsi' => AssetType::ISCSI_SHARE,
                'externalnas' => AssetType::EXTERNAL_NAS_SHARE,
                'zfs' => AssetType::ZFS_SHARE
            ),
            'chaps' => array(
                'disabled' => ChapSettings::CHAP_DISABLED,
                'oneWay' => ChapSettings::CHAP_ONE_WAY,
                'mutual' => ChapSettings::CHAP_MUTUAL
            )
        );
    }

    private function buildViewZfsShare(ZfsShare $share): ?array
    {
        $shareName = $share->getName();
        $ipAddresses = $this->deviceAddress->getActiveIpAddresses();
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();

        try {
            $zfsShare = [
                'name' => $shareName,
                'displayName' => $shareName,
                'keyName' => $shareName,
                'type' => 'zfs',
                'users' => count($share->listUsers()),
                'access' => $share->isPublic() ? 'public' : 'private',
                'interval' => $share->getLocal()->getInterval(),
                'urls' => [
                    'samba' => array_map(fn($ip) => "\\\\$ip\\$shareName", $ipAddresses),
                    'afp' => $share->getAfp()->isEnabled()
                        ? array_map(fn($ip) => "afp://$ip/$shareName", $ipAddresses)
                        : [],
                    'nfs' => $share->getNfs()->isEnabled()
                        ? array_map(fn($ip) => "nfs://$ip:/datto/mounts/$shareName", $ipAddresses)
                        : [],
                ],
                'createStatus' => CreateShareService::CREATE_NOT_IN_PROGRESS,
                'spaceUsed' => $this->getUsedSpace($share),
                'lastSnapshot' => $lastSnapshot !== null ? $lastSnapshot->getEpoch() : null,
                'legacy' => true
            ];
        } catch (\Exception $e) {
            $this->logger->error('LCS0001 Cannot load legacy share', ['share' => $shareName, 'exception' => $e]);
            return null;
        }

        return $zfsShare;
    }

    private function buildViewNasShare(NasShare $share): ?array
    {
        $shareName = $share->getName();
        $shareKeyName = $share->getKeyName();
        $ipAddresses = $this->deviceAddress->getActiveIpAddresses();
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
        $isReplicated = $share->getOriginDevice()->isReplicated();
        $isOrphaned = $share->getOriginDevice()->isOrphaned();

        try {
            $nasShare = [
                'name' => $shareName,
                'displayName' => $share->getDisplayName(),
                'keyName' => $shareKeyName,
                'type' => $share->getType(),
                'spaceUsed' => $this->getUsedSpace($share),
                'lastSnapshot' => $lastSnapshot !== null ? $lastSnapshot->getEpoch() : null,
                'isReplicated' => $isReplicated,
                'isOrphaned' => $isOrphaned,
                'legacy' => false
            ];
            if (!$isReplicated) {
                $nasShareExtra = [
                    'hasErrors' => $this->alertManager->hasErrors($shareKeyName),
                    'users' => count($share->getUsers()->getAll()),
                    'access' => $share->getAccess()->getLevel(),
                    'paused' => $share->getLocal()->isPaused(),
                    'interval' => $share->getLocal()->getInterval(),
                    'urls' => [
                        'samba' => array_map(fn($ip) => "\\\\$ip\\$shareName", $ipAddresses),
                        'afp' => $share->getAfp()->isEnabled()
                            ? array_map(fn($ip) => "afp://$ip/$shareName", $ipAddresses)
                            : [],
                        'nfs' => $share->getNfs()->isEnabled()
                            ? array_map(fn($ip) => "nfs://$ip:/datto/mounts/$shareName", $ipAddresses)
                            : [],
                        'sftp' => $share->getSftp()->isEnabled() ?
                            array_map(fn($ip) => "sftp://$ip:2222/$shareName", $ipAddresses)
                            : [],
                        'snapshot' => "$shareName/start",
                        'remove' => $this->generateUrl('shares_remove', ['shareName' => $shareKeyName]),
                        'web' => $this->generateUrl('shares_browse', ['assetKey' => $shareKeyName, 'path' => ''])
                    ],
                    'createStatus' => $this->createShareService->getCreateStatus($shareKeyName)
                ];
                $nasShare = array_merge_recursive($nasShare, $nasShareExtra);

                $nasShare = $this->addReplicationDestination($share, $nasShare);
            } else {
                $nasShare = $this->addReplicationSource($share, $nasShare);
            }
        } catch (\Exception $e) {
            $this->logger->error('LCS0002 Cannot load nas share', ['share' => $shareName,  'exception' => $e ]);
            return null;
        }

        return $nasShare;
    }

    private function buildViewIscsiShare(IscsiShare $share): ?array
    {
        $shareName = $share->getName();
        $shareKeyName = $share->getKeyName();
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
        $isReplicated = $share->getOriginDevice()->isReplicated();
        $isOrphaned = $share->getOriginDevice()->isOrphaned();

        try {
            $iScsiShare = [
                'name' => $shareName,
                'displayName' => $share->getDisplayName(),
                'keyName' => $shareKeyName,
                'type' => $share->getType(),
                'spaceUsed' => $this->getUsedSpace($share),
                'lastSnapshot' => $lastSnapshot !== null ? $lastSnapshot->getEpoch() : null,
                'isReplicated' => $isReplicated,
                'isOrphaned' => $isOrphaned,
                'legacy' => false
            ];
            if (!$isReplicated) {
                $iScsiShareExtra = [
                    'paused' => $share->getLocal()->isPaused(),
                    'interval' => $share->getLocal()->getInterval(),
                    'authentication' => $share->getChap()->getAuthentication(),
                    'urls' => array(
                        'iscsi' => $share->getTargetName(),
                        'snapshot' => "$shareName/start",
                        'remove' => $this->generateUrl('shares_remove', ['shareName' => $shareKeyName]),
                        'settings' => $this->generateUrl('shares_configure', ['shareName' => $shareKeyName]),
                    ),
                    'createStatus' => $this->createShareService->getCreateStatus($shareKeyName)
                ];
                $iScsiShare = array_merge_recursive($iScsiShare, $iScsiShareExtra);

                $iScsiShare = $this->addReplicationDestination($share, $iScsiShare);
            } else {
                $iScsiShare = $this->addReplicationSource($share, $iScsiShare);
            }
        } catch (\Exception $e) {
            $this->logger->error('LCS0003 Cannot load iscsi share', ['share' => $shareName, 'exception' => $e]);
            return null;
        }

        return $iScsiShare;
    }

    private function buildViewExternalNasShare(ExternalNasShare $share): ?array
    {
        $shareName = $share->getName();
        $shareKeyName = $share->getKeyName();
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
        $isReplicated = $share->getOriginDevice()->isReplicated();
        $isOrphaned = $share->getOriginDevice()->isOrphaned();

        try {
            $externalShare = [
                'name' => $shareName,
                'displayName' => $share->getDisplayName(),
                'keyName' => $shareKeyName,
                'type' => $share->getType(),
                'spaceUsed' => $this->getUsedSpace($share),
                'lastSnapshot' => $lastSnapshot !== null ? $lastSnapshot->getEpoch() : null,
                'isReplicated' => $isReplicated,
                'isOrphaned' => $isOrphaned
            ];
            if (!$isReplicated) {
                $externalShareExtra = [
                    'hasErrors' => $this->alertManager->hasErrors($shareKeyName),
                    'paused' => $share->getLocal()->isPaused(),
                    'interval' => $share->getLocal()->getInterval(),
                    'urls' => [
                        'address' => '\\\\' . $share->getSambaMount()->getHost() . '\\' . $share->getSambaMount()->getFolder(),
                        'snapshot' => "$shareName/start",
                        'settings' => $this->generateUrl('shares_configure', ['shareName' => $shareKeyName]),
                        'remove' => $this->generateUrl('shares_remove', ['shareName' => $shareKeyName]),
                    ],
                    'backupAcls' => $share->isBackupAclsEnabled()
                ];
                $externalShare = array_merge_recursive($externalShare, $externalShareExtra);

                $externalShare = $this->addReplicationDestination($share, $externalShare);
            } else {
                $externalShare = $this->addReplicationSource($share, $externalShare);
            }
        } catch (\Exception $e) {
            $this->logger->error('LCS0004 Cannot load external nas share', ['share' => $shareName, 'exception' => $e]);
            return null;
        }

        return $externalShare;
    }

    private function addReplicationDestination(Share $share, array $shareArray): array
    {
        if (SpeedSync::isPeerReplicationTarget($share->getOffsiteTarget())) {
            $replicationDevices = ReplicationDevices::createOutboundReplicationDevices();
            $this->deviceState->loadRecord($replicationDevices);

            $targetDevice = $replicationDevices->getDevice($share->getOffsiteTarget());
            if ($targetDevice) {
                $shareArray['offsite']['targetDevice'] = $targetDevice->toArray();
            }
        }
        return $shareArray;
    }

    private function addReplicationSource(Share $share, array $shareArray): array
    {
        if ($share->getOriginDevice()->isReplicated()) {
            $replicationDevices = ReplicationDevices::createInboundReplicationDevices();
            $this->deviceState->loadRecord($replicationDevices);
            $inboundDevice = $replicationDevices->getDevice($share->getOriginDevice()->getDeviceId());
            //note that /var/lib/datto/device/inboundDevices will not exist until the first time the reconcile runs
            if ($inboundDevice) {
                $shareArray['replication']['hostname'] = $inboundDevice->getHostname();
                $shareArray['replication']['ddnsDomain'] = $inboundDevice->getDdnsDomain();
            }
        }
        return $shareArray;
    }

    /**
     * Get the used space of the share
     *
     * @param Share $share
     * @return int
     */
    private function getUsedSpace(Share $share): int
    {
        if ($share->getDataset()->exists()) {
            return (int)$share->getDataset()->getUsedSize();
        }

        return 0;
    }
}
