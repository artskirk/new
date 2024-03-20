<?php

namespace Datto\App\Controller\Api\V1\Device\Restore\Network;

use Datto\Log\SanitizedException;
use Datto\Restore\Export\ExportManager;
use Datto\Utility\Security\SecretString;
use Throwable;

/**
 * API endpoint query and change agent settings
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
class Exports
{
    /** @var ExportManager */
    private $exportManager;

    public function __construct(
        ExportManager $exportManager
    ) {
        $this->exportManager = $exportManager;
    }

    /**
     * Run the export process in the background.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentName" = {
     *          @Symfony\Component\Validator\Constraints\Type("string"),
     *          @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     *     },
     *     "snapshotEpoch" = {
     *          @Symfony\Component\Validator\Constraints\Type("integer")
     *     },
     *     "imageType" = {
     *          @Symfony\Component\Validator\Constraints\Type("string"),
     *          @Symfony\Component\Validator\Constraints\NotBlank()
     *     }
     * })
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param string $imageType
     * @param string|null $passphrase
     * @param string|null $bootType
     * @return array
     */
    public function create(
        string $agentName,
        int $snapshotEpoch,
        string $imageType,
        string $passphrase = null,
        string $bootType = null
    ) {
        try {
            $passphrase = $passphrase ? new SecretString($passphrase) : null;
            $created = $this->exportManager->createShareExport(
                $agentName,
                $snapshotEpoch,
                $imageType,
                $passphrase,
                $bootType
            );
            return $created;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$passphrase]);
        }
    }

    /**
     * Get the progress of a background export.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentName" = {
     *          @Symfony\Component\Validator\Constraints\Type("string"),
     *          @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     *     },
     *     "snapshotEpoch" = {
     *          @Symfony\Component\Validator\Constraints\Type("integer")
     *     },
     *     "imageType" = {
     *          @Symfony\Component\Validator\Constraints\Type("string"),
     *          @Symfony\Component\Validator\Constraints\NotBlank()
     *     }
     * })
     * @param string $agentName
     * @param int $snapshotEpoch
     * @param string $imageType
     * @return array
     */
    public function status(
        $agentName,
        $snapshotEpoch,
        $imageType
    ) {
        $status = $this->exportManager->getShareExportStatus(
            $agentName,
            $snapshotEpoch,
            $imageType
        );

        return $status;
    }

    /**
     * Get the details of an export, such as the image type, share path, and nfs path
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     * @param string $exportName The name of the export
     * @return array
     */
    public function getExportDetails($exportName)
    {
        $exportDetails = $this->exportManager->getExportShare($exportName);

        return $exportDetails;
    }
}
