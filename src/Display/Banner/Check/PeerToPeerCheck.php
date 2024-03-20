<?php

namespace Datto\Display\Banner\Check;

use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Replication\ReplicationDevices;
use Datto\Billing\ServicePlanService;
use Datto\Feature\FeatureService;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Check to determine if the peer to peer authorized target has not been configured yet for a device on a peer to peer
 * service plan.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class PeerToPeerCheck extends Check
{
    private DeviceConfig $deviceConfig;
    private DeviceState $deviceState;
    private ServicePlanService $servicePlanService;
    private FeatureService $featureService;

    /**
     * @param Environment $twig
     * @param DeviceConfig $deviceConfig
     * @param DeviceState $deviceState
     * @param ServicePlanService $servicePlanService
     * @param FeatureService $featureService
     */
    public function __construct(
        Environment $twig,
        DeviceConfig $deviceConfig,
        DeviceState $deviceState,
        ServicePlanService $servicePlanService,
        FeatureService $featureService,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->deviceConfig = $deviceConfig;
        $this->deviceState = $deviceState;
        $this->servicePlanService = $servicePlanService;
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerReplicationError' : 'banner-replication-error';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        $showBanner = $this->isPeerToPeer() && !$this->outboundReplicationDevicesExist();
        $banner = $showBanner ? $this->warning(
            'Banners/Replication/replication.error.html.twig',
            $this->clf ? $this->getReplicationErrorBanner()->toArray() : [],
            Banner::CLOSE_SESSION
        ) : null;
        return $banner;
    }

    protected function isPeerToPeer(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_PEER_REPLICATION);
    }

    protected function outboundReplicationDevicesExist()
    {
        $replicationDevices = ReplicationDevices::createOutboundReplicationDevices();
        return $this->deviceState->loadRecord($replicationDevices) && !empty($replicationDevices->getDevices());
    }

    private function getReplicationErrorBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.replication.errors.noTarget'))
            ->addButton(
                $this->translator->trans('banner.replication.errors.noTarget.link.text'),
                $this->translator->trans('banner.replication.errors.noTarget.link')
            )
            ->setIsDismissible(true);
    }
}
