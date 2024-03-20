<?php

namespace Datto\Restore\Export\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Volume;
use Datto\ImageExport\ImageType;

/**
 * Class ImageConversionHelper
 *
 * This is a helper class that contains methods common to many image conversion
 * classes.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class ImageConversionHelper
{
    /**
     * Returns the logical name of the given image GUID.
     *
     * @param string $imageGuid GUID of the image on the device.
     * @param Volume[] Array containing volumes associated with an agent.
     * @return string Name of the image.
     */
    public function getName($imageGuid, $volumes)
    {
        $name = $imageGuid;
        foreach ($volumes as $volume) {
            /** @var  Volume $volume */
            if ($volume->getGuid() === $imageGuid) {
                if (stripos($volume->getBlockDevice(), '/dev/') === 0) {
                    // Linux
                    $name = basename($volume->getBlockDevice());
                } elseif ($volume->getMountpoint()) {
                    // Windows
                    $name = rtrim(trim($volume->getMountpoint()), ':\\');
                }
                break;
            }
        }

        return $name;
    }

    /**
     * Returns the supported formats for the device.
     *
     * @param Agent $agent
     *
     * return ImageType[]
     */
    public function getSupportedFormats(Agent $agent): array
    {
        $supportedFormats = [
            'VHD' => ImageType::VHD(),
            'VMDK-linked' => ImageType::VMDK_LINKED(),
            'VHDX' => ImageType::VHDX(),
            'VMDK' => ImageType::VMDK()
        ];
        $isGenericBackup = !$agent->isSupportedOperatingSystem();

        if ($isGenericBackup) {
            $supportedFormats = [
                'VMDK' => ImageType::VMDK(),
            ];
        }

        ksort($supportedFormats);

        return $supportedFormats;
    }
}
