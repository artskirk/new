<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\Service\Storage\DriveHealthService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class DriveHealthCheck extends Check
{
    private DriveHealthService $driveHealthService;

    public function __construct(
        Environment $twig,
        DriveHealthService $driveHealthService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);
        $this->driveHealthService = $driveHealthService;
    }

    public function getId(): string
    {
        return $this->clf ? 'bannerDriveHealth' : 'banner-drive-health';
    }

    public function check(Context $context): ?Banner
    {
        if ($this->driveHealthService->getMissing()) {
            return $this->danger('Banners/DriveHealth/drives.missing.html.twig', $this->clf ? $this->getDrivesMissingBanner()->toArray() : [], Banner::CLOSE_LOCKED);
        }

        if ($this->driveHealthService->driveErrorsExist()) {
            return $this->warning('Banners/DriveHealth/drives.errors.html.twig', $this->clf ? $this->getDrivesErrorsBanner()->toArray() : [], Banner::CLOSE_SESSION);
        }
        return null;
    }
    
    public function getDrivesMissingBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageTitle($this->translator->trans('banner.drivehealth.missing'))
            ->setMessageText($this->translator->trans('banner.drivehealth.visit'))
            ->setMessageLink($this->translator->trans('banner.drivehealth.link'), '/advanced/status');
    }

    public function getDrivesErrorsBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageTitle($this->translator->trans('banner.drivehealth.errors'))
            ->setMessageText($this->translator->trans('banner.drivehealth.visit'))
            ->setMessageLink($this->translator->trans('banner.drivehealth.link'), '/advanced/status')
            ->setIsDismissible(true);
    }
}
