<?php

namespace Datto\Display\Banner\Check;

use Datto\Billing\Service as BillingService;
use Datto\Config\DeviceConfig;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Check to determine if a device is out of service or expired.
 * This will return a Banner if either of these is the case.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ExpirationCheck extends Check
{
    private DeviceConfig $config;
    private BillingService $billingService;

    /**
     * @param Environment $twig
     * @param DeviceConfig $config
     * @param BillingService $billingService
     */
    public function __construct(
        Environment $twig,
        DeviceConfig $config,
        BillingService $billingService,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->config = $config;
        $this->billingService = $billingService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerServiceExpiration' : 'banner-service-expiration';
    }

    /**
     * Uses the BillingService class to determine if an out-of-service/expiration
     * banner needs to be displayed.
     *
     * @param Context $context
     * @return Banner|null
     */
    public function check(Context $context): ?Banner
    {
        if ($this->billingService->isOutOfService()) {
            $isSnapNAS = $this->config->isSnapNAS();
            $expiration = $this->billingService->getExpirationDate();

            $parameters = [
                'expiration' => $expiration,
                'isSnapNAS' => $isSnapNAS
            ];

            return $this->danger(
                'Banners/Billing/expired.html.twig',
                $this->clf ? $this->getExpirationBanner($isSnapNAS, $expiration)->toArray() : $parameters,
                Banner::CLOSE_SESSION
            );
        }

        return null;
    }

    private function getExpirationBanner(bool $isSnapNas, int $expiration): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageText($this->translator->trans('banner.billing.expired' . ($isSnapNas ? '.nas' : '' ), ['%expiration%' => date("Y M d g:i:sa", $expiration)]))
            ->setIsDismissible(true);
    }
}
