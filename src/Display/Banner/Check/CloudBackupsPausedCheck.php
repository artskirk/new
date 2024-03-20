<?php

namespace Datto\Display\Banner\Check;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Display a banner if offsite synchronization is disabled for any agents.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class CloudBackupsPausedCheck extends Check
{
    private SpeedSyncMaintenanceService $speedSyncMaintenanceService;

    /**
     * @param Environment $twig
     * @param SpeedSyncMaintenanceService $speedSyncMaintenanceService
     * @param Service $billingService $bullingService
     */
    public function __construct(
        Environment $twig,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerCloudBackupPaused' : 'banner-cloud-backup-paused';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        if ($this->speedSyncMaintenanceService->isEnabled()) {
            try {
                if ($this->speedSyncMaintenanceService->isDevicePaused()) {
                    return $this->warning('Banners/Cloud/device.paused.html.twig', $this->clf ? $this->getDevicePausedBanner()->toArray() : [], Banner::CLOSE_LOCKED);
                } else {
                    $pausedAssetNames = $this->speedSyncMaintenanceService->getPausedAssetNames();
                    if (count($pausedAssetNames) > 0) {
                        return $this->warning(
                            'Banners/Cloud/assets.paused.html.twig',
                            $this->clf ? $this->getAssetsPausedBanner($pausedAssetNames)->toArray() : ['pausedAssetNames' => $pausedAssetNames],
                            Banner::CLOSE_LOCKED
                        );
                    }
                }
            } catch (\Throwable $e) {
                return $this->warning('Banners/Cloud/unknown.html.twig', $this->clf ? $this->getUnknownBanner()->toArray() : [], Banner::CLOSE_LOCKED);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function update(Context $context): ?Banner
    {
        $banner = $this->check($context);

        if ($banner === null) {
            return $this->success('Banners/Cloud/enabled.html.twig', $this->clf ? $this->getEnabledBanner()->toArray() : [], Banner::CLOSE_LOCKED);
        }

        return $banner;
    }

    private function getAssetsPausedBanner(array $assets = []): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.cloud.assets.paused', ['%pausedAssetNames%' => implode(', ', $assets)]));
    }

    private function getDevicePausedBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.cloud.device.paused'))
            ->setMessageLink($this->translator->trans('banner.cloud.device.paused.link'), '/configure/device');
    }

    private function getEnabledBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_SUCCESS)
            ->setMessageText($this->translator->trans('banner.cloud.enabled'));
    }

    private function getUnknownBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.cloud.unknown'));
    }
}
