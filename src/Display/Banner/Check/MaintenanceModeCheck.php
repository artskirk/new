<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\System\MaintenanceModeService;
use Datto\Upgrade\UpgradeService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Check to determine if inhibitAllCron flag is set
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class MaintenanceModeCheck extends Check
{
    private MaintenanceModeService $maintenanceModeService;
    private UpgradeService $upgradeService;

    /**
     * @param Environment $twig
     * @param MaintenanceModeService $maintenanceModeService
     * @param UpgradeService $upgradeService
     */
    public function __construct(
        Environment $twig,
        MaintenanceModeService $maintenanceModeService,
        UpgradeService $upgradeService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->maintenanceModeService = $maintenanceModeService;
        $this->upgradeService = $upgradeService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerMaintenanceMode' : 'banner-maintenance-mode';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        $maintenanceModeOn = $this->maintenanceModeService->isEnabled();
        $deviceUpgrading = $this->upgradeService->getStatus() === UpgradeService::STATUS_UPGRADE_RUNNING;

        if ($maintenanceModeOn && !$deviceUpgrading) {
            $endDate = $this->maintenanceModeService->getEndTime();
            $parameters = [
                'endTime' => $endDate
            ];

            return $this->warning(
                'Banners/System/maintenance.enabled.html.twig',
                $this->clf ? $this->getMaintenanceEnabledBanner($endDate)->toArray() : $parameters,
                Banner::CLOSE_LOCKED
            );
        } else {
            return null;
        }
    }

    private function getMaintenanceEnabledBanner(int $endDate): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.system.maintenance.enabled', ['%endDate%' => date('g:ia M jS Y', $endDate)]))
            ->setMessageLink($this->translator->trans('banner.system.maintenance.enabled.link'), '/configure/device');
    }
}
