<?php

namespace Datto\Display\Banner\Check;

use Datto\AppKernel;
use Datto\Config\DeviceConfig;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\Upgrade\UpgradeService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Displays a banner when an upgrade is available (or running).
 *
 * If an upgrade is available, we allow the user to kick off
 * said upgrade while also ignoring activity checks.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class UpgradeCheck extends Check
{
    private UpgradeService $upgradeService;
    private DeviceConfig $deviceConfig;

    /**
     * @param UpgradeService $upgradeService
     * @param Environment $twig
     */
    public function __construct(
        Environment $twig,
        UpgradeService $upgradeService,
        DeviceConfig $deviceConfig,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->upgradeService = $upgradeService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->clf ? 'bannerUpgrade' : 'banner-upgrade';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context)
    {
        $status = $this->upgradeService->getStatus();
        if ($status === UpgradeService::STATUS_UPGRADE_AVAILABLE) {
            return $this->buildUpgradeAvailable();
        } elseif ($status === UpgradeService::STATUS_UPGRADE_RUNNING) {
            return $this->buildUpgradeRunning();
        }
        return null;
    }

    /**
     * @return Banner
     */
    private function buildUpgradeAvailable(): Banner
    {
        return $this->banner(
            'Banners/Upgrade/upgrade.available.html.twig',
            $this->clf ? $this->getUpgradeAvailableBanner()->toArray() :[],
            Banner::CLOSE_SESSION,
            Banner::TYPE_DEFAULT
        );
    }

    /**
     * @return Banner
     */
    private function buildUpgradeRunning(): Banner
    {
        return $this->warning(
            'Banners/Upgrade/upgrade.running.html.twig',
            $this->clf ? $this->getUpgradeRunningBanner()->toArray() :[],
            Banner::CLOSE_LOCKED
        );
    }

    private function getUpgradeAvailableBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_INFO)
            ->setMessageText($this->translator->trans('banner.upgrade.available'))
            ->addDataActionButton($this->translator->trans('banner.upgrade.upgradeNow'), 'upgrade.now')
            ->setIsDismissible(true);
    }

    private function getUpgradeRunningBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.upgrade.running'))
            ->setIsDismissible(true);
    }
}
