<?php

namespace Datto\Restore;

use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Network\NetworkExportService;

/**
 * An Image export restore
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class ExportRestore extends Restore
{
    /**
     * @inheritdoc
     */
    public function repair()
    {
        // only repair network exports
        if (($this->getOptions()['network-export'] ?? false) === true) {
            $networkExportService = new NetworkExportService();
            $imageType = ImageType::get($this->getOptions()['image-type']);
            $networkExportService->repair($this->getAssetKey(), $this->getPoint(), $imageType);
        }
    }
}
