<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent as AgentAgent;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\AssetType;
use Datto\Asset\AssetService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Common\Resource\Filesystem;
use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\UserType;
use Datto\Restore\Iscsi\IscsiMounterService;
use Datto\Restore\RestoreService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for iSCSI Restore page
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class IscsiRestoreController extends AbstractBaseController
{
    private AssetService $assetService;
    private RestoreService $restoreService;
    private IscsiTarget $iscsiTarget;
    private TempAccessService $tempAccessService;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        RestoreService $restoreService,
        IscsiTarget $iscsiTarget,
        TempAccessService $tempAccessService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
        $this->restoreService = $restoreService;
        $this->iscsiTarget = $iscsiTarget;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Render initial iSCSI restore page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_READ")
     *
     * @param string $assetKey The asset key name
     * @param int $snapshot The snapshot timestamp
     * @return Response
     */
    public function configureAction(string $assetKey, int $snapshot): Response
    {
        $chapEnabled = false;
        $chapUsername = '';
        $mutualChapEnabled = false;
        $mutualChapUsername = '';

        $asset = $this->assetService->get($assetKey);
        $isIscsiShare = $asset->isType(AssetType::ISCSI_SHARE);
        $suffix = $isIscsiShare ? IscsiMounterService::SUFFIX_RESTORE : IscsiMounterService::SUFFIX_EXPORT;
        $restore = $this->restoreService->find($assetKey, $snapshot, $suffix);
        $restoreOptions = isset($restore) ? $restore->getOptions() : [];
        $targetName = $restoreOptions['iscsiTarget'] ?? '';
        $passphraseIsRequired = $asset instanceof AgentAgent &&
            $asset->getEncryption()->isEnabled() && !$this->tempAccessService->isCryptTempAccessEnabled($assetKey);

        $blockSize = IscsiShare::DEFAULT_BLOCK_SIZE;
        if ($asset instanceof IscsiShare) {
            $blockSize = $asset->getBlockSize();
        } elseif ($asset->isType(AssetType::EXTERNAL_NAS_SHARE)) {
            $blockSize = ExternalNasShare::DEFAULT_BLOCK_SIZE;
        }

        if ($this->iscsiTarget->doesTargetExist($targetName)) {
            $chapUsers = $this->iscsiTarget->listTargetChapUsers($targetName);

            if (isset($chapUsers[UserType::INCOMING])) {
                $chapEnabled = true;
                $chapUsername = $chapUsers[UserType::INCOMING];

                if (isset($chapUsers[UserType::OUTGOING])) {
                    $mutualChapEnabled = true;
                    $mutualChapUsername = $chapUsers[UserType::OUTGOING];
                }
            }
        }

        return $this->render(
            'Restore/Iscsi/configure.html.twig',
            [
                'asset' => [
                    'keyName' => $asset->getKeyName(),
                    'displayName' => $asset->getDisplayName(),
                    'snapshot' => $snapshot,
                    'targetName' => $targetName,
                    'isIscsiShare' => $isIscsiShare,
                    'passphraseIsRequired' => $passphraseIsRequired,
                    'chap' => [
                        'enabled' => $chapEnabled,
                        'username' => $chapUsername,
                        'mutual' => [
                            'enabled' => $mutualChapEnabled,
                            'username' => $mutualChapUsername
                        ]
                    ],
                    'originDevice' => $asset->getOriginDevice(),
                    'blockSize' => (int) $blockSize
                ]
            ]
        );
    }
}
