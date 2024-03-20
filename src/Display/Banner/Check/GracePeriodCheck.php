<?php

namespace Datto\Display\Banner\Check;

use Datto\Billing;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Checks if the Infinite Cloud Retention grace period condition is true
 * and returns a warning banner.
 *
 * Class GracePeriodCheck
 * @package Datto\Display\Banner\Check
 */
class GracePeriodCheck extends Check
{
    private Billing\Service $billingService;
    private DateTimeService $dateService;

    /**
     * @param Environment $twig
     * @param Billing\Service $billingService
     * @param DateTimeService $dateService
     */
    public function __construct(
        Environment $twig,
        Billing\Service $billingService,
        DateTimeService $dateService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->billingService = $billingService;
        $this->dateService = $dateService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerGracePeriod' : 'banner-graceperiod';
    }

    /**
     * The function checks if on ICR and then checks if on grace period. Show a banner warning that older data will be
     * deleted after a period of time.
     *
     * @param Context $context
     * @return Banner|null
     */
    public function check(Context $context): ?Banner
    {
        $inInfiniteRetentionGracePeriod = $this->billingService->inInfiniteRetentionGracePeriod();
        $gracePeriodExpired = $this->billingService->hasInfiniteRetentionGracePeriodExpired();
        $infiniteRetentionExpirationDate = $this->billingService->getInfiniteRetentionGracePeriodEndDate();

        if ($this->billingService->isInfiniteRetention() && ($inInfiniteRetentionGracePeriod || $gracePeriodExpired)) {
            $timeRemaining = $infiniteRetentionExpirationDate - $this->dateService->getTime();
            $daysRemaining = intval(floor($timeRemaining / DateTimeService::SECONDS_PER_DAY));

            $parameters = [
                'timeRemaining' => $timeRemaining,
                'daysRemaining' => $daysRemaining
            ];

            return $this->danger(
                'Banners/Billing/infinite.cloud.retention.expired.html.twig',
                $this->clf ? $this->getInfiniteCloudRetentionExpiredBanner($daysRemaining)->toArray() : $parameters,
                Banner::CLOSE_SESSION
            );
        }

        return null;
    }

    private function getInfiniteCloudRetentionExpiredBanner(int $daysRemaining): ClfBanner
    {
        $translation = $daysRemaining > 1 ? 'banner.billing.cloud.retention.expiring.plural' : 'banner.billing.cloud.retention.expiring';
        $messageText = $this->translator->trans($translation, ['%daysRemaining%' => $daysRemaining]) . $this->translator->trans('banner.billing.cloud.retention.expired');
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageText($messageText)
            ->addButton($this->translator->trans('banner.billing.cloud.retention.link.text'), $this->translator->trans('banner.billing.cloud.retention.link'))
            ->setIsDismissible(true);
    }
}
