<?php

namespace Datto\App\Twig;

use Datto\Util\BackgroundImageService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides useful extensions for Twig.
 *
 * Important:
 *   This class has the potential to grow and be misused for all sorts of things.
 *   If this class grows too big, please split it sensibly. If you add
 *   business or device logic in here, we will hunt you down :-)
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LayoutExtension extends AbstractExtension
{
    /** @var BackgroundImageService */
    private $backgroundImageService;

    public function __construct(
        BackgroundImageService $backgroundImageService
    ) {
        $this->backgroundImageService = $backgroundImageService;
    }

    /**
     * Returns the name of the extension.
     * @return string The extension name
     */
    public function getName()
    {
        return 'app.twig.layout_extension';
    }

    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return TwigFunction[]
     */
    public function getFunctions()
    {
        return array(
            new TwigFunction('getBackgroundImage', array($this, 'getBackgroundImage'))
        );
    }

    /**
     * Returns the current background image's web path
     * @return string
     */
    public function getBackgroundImage()
    {
        return $this->backgroundImageService->getCurrent();
    }
}
