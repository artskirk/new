<?php

namespace Datto\Display\Banner\Check;

use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Config\DeviceConfig;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Display a banner that gets progressively scarier when there are agents with certs that are going to expire soon.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentCertExpirationCheck extends Check
{
    // The device will start trying to update 30 days before expiration. This alerts 29 days before expiration
    // so we give the device a chance to update before we start warning the partner.
    const ALERT_BEFORE_EXPIRE_SEC = 29 * 24 * 60 * 60;

    // If we are within 7 days of expiration, we absolutely need to display the banner, whether or not the feature
    // flag is still disabled.
    const CRITICAL_ALERT_BEFORE_EXPIRE_SEC = 7 * 24 * 60 * 60;

    private AgentConfigFactory $agentConfigFactory;
    private AgentStateFactory $agentStateFactory;
    private CertificateSetStore $certificateSetStore;
    private DateTimeService $dateTimeService;
    private FeatureService $featureService;
    private DeviceConfig $deviceConfig;

    public function __construct(
        Environment $twig,
        AgentConfigFactory $agentConfigFactory,
        AgentStateFactory $agentStateFactory,
        CertificateSetStore $certificateSetStore,
        DateTimeService $dateTimeService,
        FeatureService $featureService,
        DeviceConfig $deviceConfig,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($twig, $clfService, $translator);
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->certificateSetStore = $certificateSetStore;
        $this->dateTimeService = $dateTimeService;
        $this->featureService = $featureService;
        $this->deviceConfig = $deviceConfig;
    }

    public function getId(): string
    {
        return $this->clf ? 'bannerAgentCertExpiration' : 'banner-agent-cert-expiration';
    }

    public function check(Context $context): ?Banner
    {
        $route = basename($context->getUri());
        if ($route !== 'home' && $route !== 'agents') {
            return null;
        }

        $certificateSets = $this->certificateSetStore->getCertificateSets();
        if (count($certificateSets) === 0) {
            return null;
        }

        $dangerScale = 0;
        $agentsInDanger = [];
        $withinCriticalWarningTime = false;
        foreach ($this->agentConfigFactory->getAllKeyNames() as $keyName) {
            $agentState = $this->agentStateFactory->create($keyName);
            $expiration = $agentState->get(CertificateUpdateService::CERT_EXPIRATION_KEY, 0);
            if ($expiration <= 0) {
                continue; // This asset does not have a cert which expires
            }

            $expiresIn = $expiration - $this->dateTimeService->getTime();
            if ($expiresIn < self::ALERT_BEFORE_EXPIRE_SEC) {
                if ($expiresIn < self::CRITICAL_ALERT_BEFORE_EXPIRE_SEC) {
                    $withinCriticalWarningTime = true;
                }
                $agentsInDanger[] = $this->agentConfigFactory->create($keyName)->getFullyQualifiedDomainName();

                $agentDangerScale = 1 - max(min($expiresIn / self::ALERT_BEFORE_EXPIRE_SEC, 1), 0);
                if ($agentDangerScale > $dangerScale) {
                    $dangerScale = $agentDangerScale;
                }
            }
        }

        if (count($agentsInDanger) === 0) {
            return null;
        }

        if (!$this->featureService->isSupported(FeatureService::FEATURE_CERT_EXPIRATION_WARNING) &&
            !$withinCriticalWarningTime) {
            return null;
        }

        sort($agentsInDanger);

        return $this->danger(
            'Banners/Certificate/expiring.html.twig',
            $this->clf ? $this->getAgentCertificationExpiringBanner($agentsInDanger)->toArray() : ['agentFqdns' => $agentsInDanger, 'dangerScale' => $dangerScale],
            Banner::CLOSE_SESSION
        );
    }

    protected function getAgentCertificationExpiringBanner(array $agentsInDanger = []): ClfBanner
    {
        $messageText = $this->translator->trans('banner.certificate.expiring.intervention', ['%agentsList%' => $this->buildFqdnsList($agentsInDanger)]);
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageTitle($this->translator->trans('banner.certificate.expiring'))
            ->setMessageText($messageText)
            ->addButton(
                $this->translator->trans('banner.certificate.expiring.learnmore'),
                $this->translator->trans('banner.certificate.expiring.learnmore.url')
            )
            ->setIsDismissible(true);
    }

    private function buildFqdnsList(array $agentFqdns = []): string
    {
        $list = '';
        foreach ($agentFqdns as $agent) {
            $list .= "<li>$agent</li>";
        }
        return "<ul>$list</ul>";
    }
}
