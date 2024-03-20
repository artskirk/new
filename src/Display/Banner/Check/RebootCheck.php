<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class RebootCheck extends Check
{
    private Filesystem $filesystem;

    /**
     * @param Environment $twig
     * @param Filesystem $filesystem
     */
    public function __construct(
        Environment $twig,
        Filesystem $filesystem,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerRebootRequired' : 'banner-reboot-required';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context): ?Banner
    {
        if ($this->filesystem->exists('/dev/shm/reboot-required')) {
            return $this->warning(
                'Banners/System/reboot.required.html.twig',
                $this->clf ? $this->getRebootRequiredBanner()->toArray() : [],
                Banner::CLOSE_SESSION
            );
        }

        return null;
    }

    private function getRebootRequiredBanner(): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_WARNING)
            ->setMessageText($this->translator->trans('banner.system.reboot.required'))
            ->addDataActionButton(
                $this->translator->trans('banner.system.reboot.required.link'),
                'reboot'
            )
            ->setIsDismissible(true);
    }
}
