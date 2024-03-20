<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Represents a check to determine whether a banner needs to
 * be displayed for a certain business rule, e.g. whether the
 * device has been updated, or a reboot is required.
 *
 * @author Philipp Heckel <ph@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class Check
{
    private Environment $twig;
    protected bool $clf;
    protected TranslatorInterface $translator;
    private ClfService $clfService;

    public function __construct(
        Environment $twig,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        $this->twig = $twig;
        $this->clfService = $clfService;
        $this->translator = $translator;
        $this->clf = $this->clfService->isClfEnabled();
    }

    /**
     * The id of the banner returned by this check
     *
     * This method should be overridden
     *
     * @return mixed
     */
    abstract public function getId();

    /**
     * Check if a banner needs to be displayed. Returns
     * a Banner object if a banner should be shown, or null if not.
     *
     * @param Context $context
     * @return Banner|null
     */
    public function check(Context $context)
    {
        return null;
    }

    /**
     * Once a banner has been displayed to a user, this method will be called instead of check
     *
     * This should return either a banner object with the same id as check,
     * or null if the banner doesn't need to be updated
     *
     * When we check for banner updates, by default let's forward this call to `check` to ensure the displayed
     * banner information is accurate.
     *
     * @param Context $context
     * @return Banner|null
     */
    public function update(Context $context)
    {
        return $this->check($context);
    }

    /**
     * @return Environment
     */
    protected function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @param string $name
     * @param array $parameters
     * @return string
     */
    protected function render(string $name, array $parameters): string
    {
        $layout = $this->clfService->getThemeKey();
        return $this->twig->render("$layout/$name", $parameters);
    }

    /**
     * @param string $name
     * @param array $parameters
     * @param int $close
     * @return Banner
     */
    protected function success(string $name, array $parameters, $close): Banner
    {
        $type = $this->clf ? Banner::TYPE_SUCCESS_CLF : Banner::TYPE_SUCCESS;
        return $this->banner($name, $parameters, $close, $type);
    }

    /**
     * @param string $name
     * @param array $parameters
     * @param int $close
     * @return Banner
     */
    protected function warning(string $name, array $parameters, int $close): Banner
    {
        $type = $this->clf ? Banner::TYPE_WARNING_CLF : Banner::TYPE_WARNING;
        return $this->banner($name, $parameters, $close, $type);
    }

    /**
     * @param string $name
     * @param array $parameters
     * @param int $close
     * @return Banner
     */
    protected function info(string $name, array $parameters, int $close): Banner
    {
        $type = $this->clf ? Banner::TYPE_INFO_CLF : Banner::TYPE_INFO;
        return $this->banner($name, $parameters, $close, $type);
    }

    /**
     * @param string $name
     * @param array $parameters
     * @param int $close
     * @return Banner
     */
    protected function danger(string $name, array $parameters, int $close): Banner
    {
        return $this->banner($name, $parameters, $close, Banner::TYPE_DANGER);
    }

    /**
     * @param string $name
     * @param array $parameters
     * @param int $close
     * @param string $type
     * @return Banner
     */
    protected function banner(string $name, array $parameters, int $close, string $type): Banner
    {
        return new Banner(
            $this->getId(),
            $this->clf ? '' : $this->render($name, $parameters),
            null,
            $type,
            $close,
            $parameters
        );
    }

    protected function getBaseBanner(string $type): ClfBanner
    {
        return new ClfBanner($type, $this->getId());
    }
}
