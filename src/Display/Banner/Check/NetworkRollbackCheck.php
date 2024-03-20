<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\LinkBackup;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Handle banners related to network rollback.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class NetworkRollbackCheck extends Check
{
    private LinkBackup $linkBackup;

    public function __construct(
        Environment $twig,
        LinkBackup $linkBackup,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->linkBackup = $linkBackup;
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerNetworkRollback' : 'banner-network-rollback';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        switch ($this->linkBackup->getState()) {
            case LinkBackup::STATE_PENDING:
                // Since this "check()" function is only called by a front end API call,
                // we can assume the user has a working network connection and therefore
                // commit any pending network configuration change without requiring
                // a banner and manual user confirmation.
                $this->linkBackup->commit();
                return null;
            case LinkBackup::STATE_REVERTED:
                return $this->buildRevertedBanner();
            default:
                return null;
        }
    }

    private function buildRevertedBanner(): Banner
    {
        return $this->banner(
            'Banners/Networking/change.reverted.html.twig',
            $this->clf ? $this->getNetworkingChangeRevertedBanner()->toArray() : [],
            Banner::CLOSE_SESSION,
            Banner::TYPE_WARNING
        );
    }

    private function getNetworkingChangeRevertedBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.networking.change.reverted'))
            ->setIsDismissible(true);
    }
}
