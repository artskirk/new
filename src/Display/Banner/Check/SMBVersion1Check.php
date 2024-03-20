<?php

namespace Datto\Display\Banner\Check;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Samba\SambaManager;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Display a banner if SMB protocol minimum version 1 is set
 *
 * @author Adam Marcionek <amarcionek@datto.com>
 */
class SMBVersion1Check extends Check
{
    private SambaManager $sambaManager;
    private AgentService $agentService;

    public function __construct(
        Environment $twig,
        SambaManager $sambaManager,
        AgentService $agentService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->sambaManager = $sambaManager;
        $this->agentService = $agentService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerSmbMinimumVersionOne': 'banner-smb-minimum-version-one';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        if (1 !== $this->sambaManager->getServerProtocolMinimumVersion()) {
            return null;
        }

        $agents = $this->agentService->getAllActiveLocal();
        foreach ($agents as $agent) {
            if ($agent->getPlatform() === AgentPlatform::SHADOWSNAP()) {
                return $this->warning(
                    'Banners/Settings/smb.minimum.version.with.shadowsnap.html.twig',
                    $this->clf ? $this->getSmbMinVersionWithShadowsnapBanner()->toArray() : [],
                    Banner::CLOSE_SESSION
                );
            }
        }

        return $this->warning(
            'Banners/Settings/smb.minimum.version.html.twig',
            $this->clf ? $this->getSmbMinVersionBanner()->toArray() : [],
            Banner::CLOSE_SESSION
        );
    }

    private function getSmbMinVersionWithShadowsnapBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText(
                $this->translator->trans('banner.settings.smb.minimum.version.one') .
                $this->translator->trans('banner.settings.smb.minimum.version.one.shadowsnap.text.pre')
            )
            ->setMessageLink(
                $this->translator->trans('banner.settings.smb.minimum.version.one.shadowsnap.link.text'),
                $this->translator->trans('banner.settings.smb.minimum.version.one.shadowsnap.link')
            )
            ->setIsDismissible(true);
    }

    private function getSmbMinVersionBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.settings.smb.minimum.version.one'))
            ->setIsDismissible(true);
    }
}
