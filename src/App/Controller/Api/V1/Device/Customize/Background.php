<?php

namespace Datto\App\Controller\Api\V1\Device\Customize;

use Datto\Util\BackgroundImageService;

/**
 * API endpoint to customize the device's background image
 *
 * @author Andrew Cope <acope@datto.com>
 */
class Background
{
    /** @var BackgroundImageService */
    private $backgroundImageService;

    public function __construct(
        BackgroundImageService $backgroundImageService
    ) {
        $this->backgroundImageService = $backgroundImageService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_CUSTOM_BACKGROUND")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CUSTOM_BACKGROUND")
     * @param string $backgroundImage
     */
    public function change($backgroundImage): void
    {
        $this->backgroundImageService->change($backgroundImage);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_CUSTOM_BACKGROUND")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CUSTOM_BACKGROUND")
     * @param string $backgroundImage
     * @return string the background image webpath
     */
    public function delete($backgroundImage)
    {
        return $this->backgroundImageService->delete($backgroundImage);
    }
}
