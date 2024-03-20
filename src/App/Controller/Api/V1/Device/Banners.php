<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Display\Banner\BannerService;
use Datto\Display\Banner\Context;

/**
 * API endpoint for device banners
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Banners
{
    /**
     * @var BannerService
     */
    private $bannerService;

    public function __construct(BannerService $bannerService)
    {
        $this->bannerService = $bannerService;
    }

    /**
     * Gets all banners that need to be displayed.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HOME")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "bannersNeedingUpdates" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     *
     * @param string $uri
     * @param string[] $bannersNeedingUpdates
     *      If a banner has already been displayed, then we would want to fetch an updated message. This parameter
     *      is an array of banner id's that we want updated messages for.
     *
     * @return array
     */
    public function getAll(string $uri, array $bannersNeedingUpdates = []): array
    {
        $context = new Context($uri);

        $checkedBanners = $this->bannerService
            ->skip($bannersNeedingUpdates)
            ->checkAll($context);

        $updatedBanners = $this->bannerService
            ->only($bannersNeedingUpdates)
            ->updateAll($context);

        $bannerObjects = array_merge($checkedBanners, $updatedBanners);

        // Updated banners never go away on their own so caching would make them stay forever. Only cache checked ones.
        $checkedBannersArray = $this->bannerService->toArray($checkedBanners);
        $this->bannerService->cacheBannerArray($checkedBannersArray);

        return array(
            'banners' => $this->bannerService->toArray($bannerObjects)
        );
    }
}
