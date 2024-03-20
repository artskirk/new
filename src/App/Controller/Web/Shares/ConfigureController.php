<?php

namespace Datto\App\Controller\Web\Shares;

use Datto\App\Controller\Web\Assets\AbstractAssetConfigureController;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\AccessSettings;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\ShareService;
use Datto\Asset\Share\Zfs\ZfsShare;
use Datto\Billing\Service as BillingService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Core\Network\DeviceAddress;
use Datto\Core\Network\WindowsDomain;
use Datto\Samba\UserService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Exception;

/**
 * Handles requests for the configure shares page.
 *
 * This controller deals with three types of shares:
 * - NAS shares
 * - iSCSI shares
 * - External NAS shares
 *
 * The configure page shares certain sections (asset-specific
 * and share-specific), some sections are type-specific though.
 *
 * Note to Performance:
 *    This page is implemented as ONE CONTROLLER. We started
 *    out with multiple controller methods, but that was way too
 *    slow!
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ConfigureController extends AbstractAssetConfigureController
{
    private DeviceAddress $deviceAddress;
    private WindowsDomain $windowsDomain;

    public function __construct(
        ShareService $service,
        DeviceState $deviceState,
        DeviceConfig $deviceConfig,
        UserService $userService,
        BillingService $billingService,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        DeviceAddress $deviceAddress,
        WindowsDomain $windowsDomain,
        NetworkService $networkService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct(
            $networkService,
            $service,
            $deviceState,
            $deviceConfig,
            $userService,
            $billingService,
            $speedSyncMaintenanceService,
            $filesystem,
            $clfService
        );

        $this->deviceAddress = $deviceAddress;
        $this->windowsDomain = $windowsDomain;
    }

    /**
     * Controller for shares.
     *
     * This method retrieves the share from the ShareService and delegates the
     * call to either renderNasShare() or renderIscsiShare(), depending on the type.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     *
     * FIXME This should check in the controller if NAS vs iSCSI vs external shares are allowed!
     *
     * @param string $shareName Name of the share
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($shareName)
    {
        $share = $this->service->get($shareName);

        if ($share instanceof NasShare) {
            return $this->renderNasShare($share);
        } elseif ($share instanceof IscsiShare) {
            return $this->renderIscsiShare($share);
        } elseif ($share instanceof ExternalNasShare) {
            return $this->renderExternalNasShare($share);
        } elseif ($share instanceof ZfsShare) {
            return $this->renderZfsShare($share);
        } else {
            return $this->redirect($this->generateUrl('shares'));
        }
    }

    protected function getCommonParameters(Asset $asset)
    {
        $parameters = parent::getCommonParameters($asset);
        $parameters['share'] = $parameters['asset'];
        unset($parameters['asset']);
        return $parameters;
    }

    protected function getNameAndTypeParameters(Asset $asset)
    {
        return array(
            'applyAllType' => AssetType::SHARE,
            'asset' => array(
                'name' => $asset->getName(),
                'displayName' => $asset->getDisplayName(),
                'keyName' => $asset->getKeyName()
            )
        );
    }

    protected function getLicenseParameters()
    {
        return array(
            'device' => array(
                'license' => array(
                    'canUnpause' => true,
                    'canUnpauseAll' => true
                )
            )
        );
    }

    private function renderNasShare($share): \Symfony\Component\HttpFoundation\Response
    {
        $commonParameters = $this->getCommonParameters($share);
        $errorGettingDomainUsers = $commonParameters['device']['domainError'];
        $users = $commonParameters['device']['users'];
        // getNasParameters breaks for replicated shares ("samba share does not exist")
        if ($commonParameters['share']['originDevice']['isReplicated']) {
            $nasParameters = [];
        } else {
            $nasParameters = $this->getNasParameters($share, $errorGettingDomainUsers, $users);
            if ($nasParameters['device']['domainError']) {
                // This is necessary so that we can set the domain error if it happens getting the groups
                // But not have array_merge_recursive turn device->domainError into an array
                $commonParameters['device']['domainError'] = true;
            }
            // Unset it so that array_merge_recursive doesn't turn it into an array
            unset($nasParameters['device']['domainError']);
        }
        return $this->render(
            'Shares/Configure/index.nas.html.twig',
            array_merge_recursive(
                $commonParameters,
                $nasParameters
            )
        );
    }

    private function renderIscsiShare($share): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render(
            'Shares/Configure/index.iscsi.html.twig',
            array_merge_recursive(
                $this->getCommonParameters($share),
                $this->getIscsiParameters($share)
            )
        );
    }

    private function renderExternalNasShare($share): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render(
            'Shares/Configure/index.extnas.html.twig',
            array_merge_recursive(
                $this->getCommonParameters($share),
                $this->getExternalNasParameters($share)
            )
        );
    }

    private function renderZfsShare($share): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render(
            'Shares/Configure/index.zfs.html.twig',
            $this->getCommonParameters($share)
        );
    }

    /**
     * @param string[] $allUsers
     */
    private function getNasParameters(NasShare $share, bool $errorGettingDomainUsers, array $allUsers): array
    {
        $ipAddresses = $this->deviceAddress->getActiveIpAddresses();

        return array_merge_recursive(
            $this->getNasAccessParameters($share, $ipAddresses),
            $this->getNasOtherParameters($share, $ipAddresses),
            $this->getNasUsersParameters($share, $errorGettingDomainUsers, $allUsers),
            $this->getNasReportingParameters($share)
        );
    }

    /**
     * @param string[] $ipAddresses
     */
    private function getNasAccessParameters(NasShare $share, array $ipAddresses): array
    {
        return [
            'share' => [
                'access' => [
                    'level' => $share->getAccess()->getLevel(),
                    'writeLevel' => $share->getAccess()->getWriteLevel(),
                    'authorizedUser' => $share->getAccess()->getAuthorizedUser(),
                    'creatorOnly' => $share->getAccess()->getWriteLevel() === AccessSettings::WRITE_ACCESS_LEVEL_CREATOR,
                ],
                'samba' => [
                    'urls' => array_map(fn($ip) => sprintf('\\\\%s\\%s', $ip, $share->getName()), $ipAddresses)
                ],
            ]
        ];
    }

    /**
     * @param string[] $ipAddresses
     */
    private function getNasOtherParameters(NasShare $share, array $ipAddresses): array
    {
        return [
            'share' => [
                'afp' => [
                    'enabled' => $share->getAfp()->isEnabled(),
                    'urls' => array_map(fn($ip) => sprintf('afp://%s/%s', $ip, $share->getName()), $ipAddresses)
                ],
                'apfs' => [
                    'enabled' => $share->getApfs()->isEnabled(),
                    'urls' => array_map(fn($ip) => sprintf('smb://%s/%s', $ip, $share->getName()), $ipAddresses)
                ],
                'nfs' => [
                    'enabled' => $share->getNfs()->isEnabled(),
                    'urls' => array_map(
                        fn($ip) => sprintf('nfs://%s:/datto/mounts/%s', $ip, $share->getName()),
                        $ipAddresses
                    )
                ],
                'sftp' => [
                    'enabled' => $share->getSftp()->isEnabled(),
                    'urls' => array_map(fn($ip) => sprintf('sftp://%s:2222/%s', $ip, $share->getName()), $ipAddresses)
                ],
                'localOnly' => $this->billingService->isLocalOnly()
            ]
        ];
    }

    private function getNasUsersParameters(NasShare $share, $errorGettingDomainData, $allUsers): array
    {
        $domainError = false;
        $shareUsers = $share->getUsers()->getAll();
        $availUsers = array_diff($allUsers, $shareUsers);
        $adminUsers = $share->getUsers()->getAdminUsers();

        $allGroups = array();
        if (!$errorGettingDomainData) {
            try {
                $allGroups = $this->userService->getDomainGroups();
            } catch (Exception $e) {
                $domainError = true;
            }
        }

        $availGroups = array_diff($allGroups, $shareUsers);

        return array(
            'share' => array(
                'users' => array(
                    'assigned' => $shareUsers,
                    'available' => $availUsers,
                    'admin' => $adminUsers
                ),
                'groups' => array(
                    'assigned' => array(),
                    'available' => $availGroups
                )
            ),
            'device' => array(
                'hasDomain' => $this->windowsDomain->inDomain(),
                'groups' => $allGroups,
                'domainError' => $domainError
            )
        );
    }

    private function getNasReportingParameters(NasShare $share): array
    {
        return array(
            'share' => array(
                'reporting' => array(
                    'growth' => array(
                        'emails' => $share->getGrowthReport()->getEmailList(),
                        'frequency' => $share->getGrowthReport()->getFrequency()
                    )
                )
            )
        );
    }

    private function getExternalNasParameters(ExternalNasShare $share): array
    {
        $address = '\\\\' . $share->getSambaMount()->getHost() . '\\' . $share->getSambaMount()->getFolder();
        return array(
            'share' => array(
                'connection' => array(
                    'address' => $address,
                    'username' => $share->getSambaMount()->getUsername()
                )
            )
        );
    }

    private function getIscsiParameters(IscsiShare $share): array
    {
        return $this->getChapParameters($share);
    }

    private function getChapParameters(IscsiShare $share): array
    {
        $chapSettings = $share->getChap();
        $authentication = $chapSettings->getAuthentication();

        return array(
            'share' => array(
                'chap' => array(
                    'enabled' => $authentication !== ChapSettings::CHAP_DISABLED,
                    'username' => $chapSettings->getUser(),
                    'mutual' => array(
                        'enabled' => $authentication === ChapSettings::CHAP_MUTUAL,
                        'username' => $chapSettings->getMutualUser()
                    )
                )
            )
        );
    }
}
