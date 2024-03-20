<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\System\RebootConfig;
use Datto\System\PowerManager;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Checks if there is a scheduled reboot on the device, if so displays
 * banner containing the date and time as well as the devices timezone
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScheduledRebootCheck extends Check
{
    private PowerManager $powerManager;

    /**
     * @param Environment $twig
     * @param PowerManager $powerManager
     */
    public function __construct(
        Environment $twig,
        PowerManager $powerManager,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->powerManager = $powerManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerRebootScheduled' : 'banner-reboot-scheduled';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        /* @var RebootConfig $config */
        $config = $this->powerManager->getRebootSchedule();

        if ($config) {
            $rebootAt = $config->getRebootAt();
            $parameters = [
                'rebootAt' => $rebootAt
            ];

            if ($config->hasFailed()) {
                return $this->danger(
                    'Banners/System/reboot.failed.html.twig',
                    $this->clf ? $this->getRebootFailedBanner($rebootAt)->toArray() : $parameters,
                    Banner::CLOSE_SESSION
                );
            }

            if ($config->isAttemptingReboot()) {
                return $this->warning(
                    'Banners/System/reboot.attempting.html.twig',
                    $this->clf ? $this->getRebootAttemptingBanner()->toArray() : $parameters,
                    Banner::CLOSE_SESSION
                );
            }

            return $this->warning(
                'Banners/System/reboot.scheduled.html.twig',
                $this->clf ? $this->getRebootScheduledBanner($rebootAt)->toArray() : $parameters,
                Banner::CLOSE_SESSION
            );
        }

        return null;
    }

    private function getRebootFailedBanner(int $rebootAt): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageText($this->translator->trans(
                'banner.system.reboot.failed',
                ['%rebootDate%' => date('Y/m/d g:i A T', $rebootAt)]
            ))
            ->addButton(
                $this->translator->trans('banner.system.reboot.failed.link'),
                '/configure/device'
            )
            ->setIsDismissible(true);
    }

    private function getRebootAttemptingBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.system.reboot.attempting'))
            ->setIsDismissible(true);
    }

    private function getRebootScheduledBanner(int $rebootAt): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans(
                'banner.system.reboot.scheduled',
                ['%scheduledDate%' => date('Y/m/d g:i A T', $rebootAt)]
            ))
            ->setIsDismissible(true);
    }
}
