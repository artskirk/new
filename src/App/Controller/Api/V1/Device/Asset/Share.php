<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Alert\AlertCodes;
use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\AssetType;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Serializer\ShareSerializer;
use Datto\Asset\Share\ShareService;
use Datto\Backup\BackupManagerFactory;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Screen;
use Exception;
use Datto\Asset\LastErrorAlert;

/**
 * This class contains the API endpoints for adding,
 * removing, and getting information about NAS shares.
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
 * @author John Roland <jroland@datto.com>
 */
class Share extends AbstractShareEndpoint
{
    /** @var ShareSerializer */
    private $serializer;

    /** @var Filesystem */
    private $filesystem;

    /** @var CreateShareService */
    private $createShareService;

    /** @var Screen */
    private $screen;

    /** @var BackupManagerFactory */
    private $backupManagerFactory;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        ShareService $shareService,
        ShareSerializer $serializer,
        Filesystem $filesystem,
        CreateShareService $createShareService,
        Screen $screen,
        BackupManagerFactory $backupManagerFactory,
        FeatureService $featureService
    ) {
        parent::__construct($shareService);

        $this->serializer = $serializer;
        $this->filesystem = $filesystem;
        $this->createShareService = $createShareService;
        $this->screen = $screen;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->featureService = $featureService;
    }

    /**
     * Check the current creation status of a share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="share"),
     * })
     * @param string $shareName Name of the share.
     * @return array
     */
    public function getStatus($shareName)
    {
        $share = $this->shareService->get($shareName);
        $lastError = $this->shareService->getLastError($share);

        return array(
            'createStatus' => $this->createShareService->getCreateStatus($shareName),
            'lastError' => $this->getErrorArray($lastError)
        );
    }

    /**
     * Start taking a snapshot for a given NAS share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_BACKUP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="share"),
     * })
     * @param string $shareName Name of the share.
     * @return string|null the epoch time of the new snapshot, or null if the asset is an external NAS share.
     */
    public function start($shareName)
    {
        $share = $this->shareService->get($shareName);
        $isExternalNas = $share->isType(AssetType::EXTERNAL_NAS_SHARE);

        if ($isExternalNas) {
            $startedScreen = $this->screen->runInBackground(
                ['snapctl', 'asset:backup:start', $shareName],
                "forceStartBackup-$shareName"
            );
            if (!$startedScreen) {
                throw new Exception('Failed to start the backup background process.', 500);
            }
            return null;
        } else {
            $this->featureService->assertSupported(FeatureService::FEATURE_ASSET_BACKUPS, null, $share);
            $backupManager = $this->backupManagerFactory->create($share);
            $backupManager->startUnscheduledBackup([]);

            // Re-read the share to get the updated recovery points list
            $share = $this->shareService->get($shareName);
            $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
            if ($lastSnapshot === null) {
                throw new Exception('Unable to retrieve the last recovery point.');
            }
            return $lastSnapshot->getEpoch();
        }
    }

    /**
     * Return detailed information for a specific share.
     *
     * By default, this endpoint will return all known fields of an share.
     * If $fields is non-empty, it is interpreted as a filter for share keys.
     *
     * To return all share information:
     *   $share = $endpoint->get('share1');
     *
     * To return only name and type information:
     *   $share = $endpoint->get('share1', array('name', 'type'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="share"),
     * })
     * @param string $shareName Name of the share
     * @param array $fields List of fields to include in the return array
     * @return array
     */
    public function get($shareName, array $fields = array())
    {
        $share = $this->shareService->get($shareName);

        $serializedShare = $this->serializer->serialize($share);
        $filteredSerializedShare = $this->filter($serializedShare, $fields);

        return $filteredSerializedShare;
    }

    /**
     * Get a list of all share names on this device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @return string[] List of share names
     */
    public function getAllNames()
    {
        $result = array();
        $shares = $this->shareService->getAll();

        foreach ($shares as $share) {
            $result[] = $share->getName();
        }

        return $result;
    }

    /**
     * Get a list of all shares on this device
     *
     * By default, this endpoint will get all shares and return all known fields.
     * If $fields is non-empty, it is interpreted as a filter for share keys.
     *
     * To return all share information:
     *   $shares = $endpoint->getAll();
     *
     * To return only name and type of all shares:
     *   $shares = $endpoint->getAll(array('name', 'type'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param array $fields List of fields to include in the return array
     * @return array JSON encoded array of share data
     */
    public function getAll(array $fields = array())
    {
        $result = array();
        $shares = $this->shareService->getAll();

        foreach ($shares as $share) {
            $serializedShare = $this->serializer->serialize($share);
            $filteredSerializedShare = $this->filter($serializedShare, $fields);

            $result[] = $filteredSerializedShare;
        }

        return $result;
    }

    /**
     * Get a list of all local (non replicated) shares on this device
     *
     * By default, this endpoint will get all local shares and return all known fields.
     * If $fields is non-empty, it is interpreted as a filter for share keys.
     *
     * To return all share information:
     *   $shares = $endpoint->getAllLocal();
     *
     * To return only name and type of all shares:
     *   $shares = $endpoint->getAllLocal(array('name', 'type'));
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @param array $fields List of fields to include in the return array
     * @return array JSON encoded array of share data
     */
    public function getAllLocal(array $fields = []): array
    {
        $result = [];
        $shares = $this->shareService->getAllLocal();

        foreach ($shares as $share) {
            $serializedShare = $this->serializer->serialize($share);
            $filteredSerializedShare = $this->filter($serializedShare, $fields);

            $result[] = $filteredSerializedShare;
        }

        return $result;
    }

    /**
     * Clears all errors
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     *
     * @return array
     */
    public function clearAllErrors()
    {
        /** @var Share[] $shares */
        $shares = $this->shareService->getAll();

        /** @var \Datto\Asset\Share\Share $share */
        foreach ($shares as $share) {
            if ($share->getLastError() != null) {
                $share->clearLastError();
                $this->shareService->save($share);
            }
        }

        return array(
            'lastError' => null
        );
    }

    /**
     * Clears the error report for a specific share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="share"),
     * })
     * @param string $shareName Name of the share.
     * @return array
     */
    public function clearError($shareName)
    {
        $share = $this->shareService->get($shareName);
        $share->clearLastError();
        $this->shareService->save($share);

        return array(
            'lastError' => $this->getErrorArray($share->getLastError())
        );
    }

    /**
     * @param LastErrorAlert|null $lastErrorAlert
     * @return array
     */
    private function getErrorArray($lastErrorAlert)
    {
        if ($lastErrorAlert) {
            return array(
                'message' => $lastErrorAlert->getMessage(),
                'time' => $lastErrorAlert->getTime(),
                'isWarning' => AlertCodes::checkWarning($lastErrorAlert->getCode())
            );
        }

        return null;
    }

    /**
     * Reduces a full share array to the list of keys given in
     * the $fields array.
     *
     * @param array $serializedShare
     * @param array $fields
     * @return array
     */
    private function filter(array $serializedShare, array $fields)
    {
        $hasFilter = !empty($fields);

        if ($hasFilter) {
            $filteredShare = array();

            foreach ($serializedShare as $field => $value) {
                if (in_array($field, $fields)) {
                    $filteredShare[$field] = $value;
                }
            }

            return $filteredShare;
        } else {
            return $serializedShare;
        }
    }
}
