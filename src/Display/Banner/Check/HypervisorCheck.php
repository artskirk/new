<?php

namespace Datto\Display\Banner\Check;

use Datto\Config\DeviceConfig;
use Datto\Connection\Service\ConnectionService;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Check to determine if the hypervisor has not been configured yet.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class HypervisorCheck extends Check
{
    private DeviceConfig $deviceConfig;
    private ConnectionService $connectionService;

    /**
     * @param Environment $twig
     * @param DeviceConfig $deviceConfig
     * @param ConnectionService $connectionService
     */
    public function __construct(
        Environment $twig,
        DeviceConfig $deviceConfig,
        ConnectionService $connectionService,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->deviceConfig = $deviceConfig;
        $this->connectionService = $connectionService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerHypervisorError' : 'banner-hypervisor-error';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        $banner = null;
        $hasHypervisorConfig = $this->deviceConfig->has('isVirtual') && !$this->deviceConfig->isAltoXL();

        if ($hasHypervisorConfig) {
            $connections = $this->connectionService->getAll();
            $onVirtPage = strpos(basename($context->getUri()), "connections") !== false;

            if (count($connections) === 0) {
                $parameters = [
                    'onVirtPage' => $onVirtPage
                ];

                $banner = $this->warning(
                    'Banners/Virtual/hypervisor.missing.html.twig',
                    $this->clf ? $this->getHypervisorMissingBanner($onVirtPage)->toArray() : $parameters,
                    Banner::CLOSE_LOCKED
                );
            } elseif (!$onVirtPage && $this->deviceConfig->has('hypervisor.error')) {
                $banner = $this->danger(
                    'Banners/Virtual/hypervisor.error.html.twig',
                    $this->clf ? $this->getHypervisorErrorBanner()->toArray() : [],
                    Banner::CLOSE_SESSION
                );
            }
        }

        return $banner;
    }
    private function getHypervisorMissingBanner(bool $onVirtPage): ClfBanner
    {
        $translation = $onVirtPage ? 'banner.virtual.hypervisor.missing.add' : 'banner.virtual.hypervisor.missing.required';

        $banner = $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans($translation));

        if (!$onVirtPage) {
            $banner->setMessageLink($this->translator->trans('banner.virtual.hypervisor.missing.required.link'), '/connections/add');
        }
        return $banner;
    }

    private function getHypervisorErrorBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.virtual.hypervisor.error'))
            ->setMessageLink($this->translator->trans('banner.virtual.hypervisor.error.link'), '/connections')
            ->setIsDismissible(true);
    }
}
