<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;

/**
 * API endpoint for handling agent error alerts..
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Error
{
    /** @var AssetService */
    private $assetService;

    public function __construct(AssetService $assetService)
    {
        $this->assetService = $assetService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "assetKey" = @Datto\App\Security\Constraints\AssetExists()
     * })
     *
     * @param string $assetKey
     * @return bool
     */
    public function clear(string $assetKey): bool
    {
        $asset = $this->assetService->get($assetKey);
        $asset->clearLastError();
        $this->assetService->save($asset);

        return true;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @return mixed[]
     */
    public function clearAll(): array
    {
        $cleared = [];

        $assets = $this->assetService->getAll();

        foreach ($assets as $asset) {
            $asset->clearLastError();
            $this->assetService->save($asset);

            $cleared[] = [
                'assetKey' => $asset->getKeyName()
            ];
        }

        return [
            'cleared' => $cleared
        ];
    }
}
