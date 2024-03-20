<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share;

use Datto\Alert\AlertCodes;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\ExternalNas\ExternalNasService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\ExternalNas\ExternalNasShareBuilder;
use Datto\Asset\Share\Serializer\ShareSerializer;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\SanitizedException;
use Datto\System\SambaMount;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * This class contains the API endpoints for adding, removing, and getting information about External NAS shares.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ExternalNas extends AbstractShareEndpoint
{
    /** @var ExternalNasService */
    private $externalNasService;

    /** @var ShareSerializer */
    private $shareSerializer;
    
    /** @var CreateShareService */
    private $createShareService;

    public function __construct(
        ShareService $shareService,
        ExternalNasService $externalNasService,
        ShareSerializer $shareSerializer,
        CreateShareService $createShareService
    ) {
        parent::__construct($shareService);
        $this->externalNasService = $externalNasService;
        $this->shareSerializer = $shareSerializer;
        $this->createShareService = $createShareService;
    }

    /**
     * Create a new External NAS share with default settings.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_EXTERNAL")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z][A-Za-z\d\-\_]+$~"),
     *   "host" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^\P{Cc}\S{1,256}$~"),
     *   "folder" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,4096}$~"),
     *   "interval" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "localSchedule" = @Symfony\Component\Validator\Constraints\Type("array"),
     *   "localRetention" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "replication" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^(always|never|\d+)$~"),
     *   "offsiteRetention" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "username" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~"),
     *   "password" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~"),
     *   "domain" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~")
     * })
     *
     * @param string $shareName The name to give to the new External NAS share
     * @param string $host The SMB host name
     * @param string $folder The directory on the SMB host to back up
     * @param int $interval number of minutes between backups
     * @param int[] $localSchedule local backup schedule as an array of days with arrays of hours inside
     * @param int $localRetention how long to keep local backups
     * @param string $replication always, never, or the number of seconds between sending offsite synchs
     * @param int $offsiteRetention how long to keep offsite backups
     * @param bool $backupAcls whether or not to backup NTFS permissions
     * @param string|null $username The username to provide, if any
     * @param string|null $password The password to provide, if any
     * @param string|null $domain the active directory domain, if any
     * @param string $offsiteTarget "cloud", "noOffsite" or a DeviceID of a siris device to replicate to
     *
     * @return array with keys: 'shareName', 'assetKeyName', 'host', 'folder'
     */
    public function add(
        string $shareName,
        string $host,
        string $folder,
        int $interval,
        array $localSchedule,
        int $localRetention,
        string $replication,
        int $offsiteRetention,
        bool $backupAcls,
        string $username = null,
        string $password = null,
        string $domain = null,
        string $offsiteTarget
    ) {
        try {
            $sambaMount = new SambaMount($host, $folder, $username, $password, $domain, true, $backupAcls);

            $localSettings = new LocalSettings($shareName);
            $localSettings->setInterval($interval);
            $weeklySchedule = new WeeklySchedule();
            $weeklySchedule->setSchedule($localSchedule);
            $localSettings->setSchedule($weeklySchedule);
            $localRetentionSettings = new Retention(
                Retention::DEFAULT_DAILY,
                Retention::DEFAULT_WEEKLY,
                Retention::DEFAULT_MONTHLY,
                $localRetention
            );
            $localSettings->setRetention($localRetentionSettings);

            $offsiteSettings = new OffsiteSettings();
            $offsiteSettings->setReplication($replication);
            $offsiteRetentionSettings = new Retention(
                Retention::DEFAULT_DAILY,
                Retention::DEFAULT_WEEKLY,
                Retention::DEFAULT_MONTHLY,
                $offsiteRetention
            );
            $offsiteSettings->setRetention($offsiteRetentionSettings);

            // create share
            $builder = new ExternalNasShareBuilder($shareName, $sambaMount, $this->logger);
            $builtShare = $builder
                ->local($localSettings)
                ->offsite($offsiteSettings)
                ->backupAcls($backupAcls)
                ->originDevice($this->createShareService->createOriginDevice())
                ->offsiteTarget($offsiteTarget)
                ->build();

            if (!$this->externalNasService->isMountable($builtShare, $sambaMount)) {
                throw new Exception('Cannot mount shared folder.');
            }

            $share = $this->createShareService->create($builtShare, Share::DEFAULT_MAX_SIZE);

            return $this->shareSerializer->serialize($share);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Create a new External NAS share from a template share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_EXTERNAL")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z][A-Za-z\d\-\_]+$~"),
     *   "host" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^\P{Cc}\S{1,256}$~"),
     *   "folder" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,4096}$~"),
     *   "template" = @Datto\App\Security\Constraints\AssetExists(type = "externalNas"),
     *   "username" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~"),
     *   "password" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~"),
     *   "domain" = @Symfony\Component\Validator\Constraints\Regex(pattern="~\P{Cc}{1,256}$~")
     * })
     *
     * @param string $shareName The name to give to the new External NAS share
     * @param string $host The SMB host name
     * @param string $folder The directory on the SMB host to back up
     * @param bool $backupAcls whether or not to backup NTFS permissions
     * @param string $template
     * @param string|null $username The username to provide, if any
     * @param string|null $password The password to provide, if any
     * @param string|null $domain the active directory domain, if any
     *
     * @return array with keys: 'shareName', 'assetKeyName', 'host', 'folder'
     */
    public function addFromTemplate(
        string $shareName,
        string $host,
        string $folder,
        bool $backupAcls,
        string $template,
        string $username = null,
        string $password = null,
        string $domain = null
    ) {
        try {
            $templateShare = $this->shareService->get($template);

            $sambaMount = new SambaMount($host, $folder, $username, $password, $domain, true, $backupAcls);

            // create share
            $builder = new ExternalNasShareBuilder($shareName, $sambaMount, $this->logger);
            $builtShare = $builder
                ->backupAcls($backupAcls)
                ->originDevice($this->createShareService->createOriginDevice())
                ->build();
            /** @var ExternalNasShare $share */
            $share = $this->createShareService->create($builtShare, Share::DEFAULT_MAX_SIZE, $templateShare);

            if (!$this->externalNasService->isMountable($share, $sambaMount)) {
                throw new Exception('Cannot mount shared folder.');
            }
            return $this->shareSerializer->serialize($share);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Update SMB connection information for a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_EXTERNAL")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="externalNas"),
     *   "host" = {
     *      @Symfony\Component\Validator\Constraints\NotBlank(),
     *      @Symfony\Component\Validator\Constraints\Length(max="256")
     *   },
     *   "folder" = {
     *      @Symfony\Component\Validator\Constraints\NotBlank(),
     *      @Symfony\Component\Validator\Constraints\Length(max="4096")
     *   },
     *   "username" = {
     *      @Symfony\Component\Validator\Constraints\Length(max="256")
     *   },
     *   "password" = {
     *      @Symfony\Component\Validator\Constraints\Length(max="256")
     *   },
     *   "domain" = {
     *      @Symfony\Component\Validator\Constraints\Length(max="256")
     *   }
     * })
     *
     * @param string $shareName The name to give to the new External NAS share
     * @param string $host The SMB host name
     * @param string $folder The directory on the SMB host to back up
     * @param string|null $username The username to provide, if any
     * @param string|null $password The password to provide, if any
     * @param string|null $domain the active directory domain, if any
     */
    public function updateConnectionInformation(
        string $shareName,
        string $host,
        string $folder,
        string $domain = null,
        string $username = null,
        string $password = null
    ): void {
        try {
            /** @var ExternalNasShare $share */
            $share = $this->shareService->get($shareName);
            $backupAcls = $share->getSambaMount()->includeCifsAcls();

            $newSambaMount = new SambaMount($host, $folder, $username, $password, $domain, true, $backupAcls);
            if (!$this->externalNasService->isMountable($share, $newSambaMount)) {
                throw new Exception('Cannot mount share.');
            }

            $share->setSambaMount($newSambaMount);
            $this->shareService->save($share);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get the current backup status for an external NAS share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_EXTERNAL")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="externalNas"),
     * })
     *
     * @param string $shareName The name of the External NAS share
     * @return array with keys: 'status', 'lastSnapshot', 'bytes', 'rate'
     */
    public function getBackupStatus($shareName)
    {
        $share = $this->shareService->get($shareName);

        $progress = $this->externalNasService->getBackupProgress($shareName);
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
        $lastSnapshotDate = $lastSnapshot ? $lastSnapshot->getEpoch() : null;

        $lastError = $this->shareService->getLastError($share);

        return [
            'status' => $progress->getStatus()->value(),
            'lastSnapshot' => $lastSnapshotDate,
            'bytesTransferred' => $progress->getBytesTransferred(),
            'transferRate' => $progress->getTransferRate(),
            'lastError' => $this->getErrorArray($lastError)
        ];
    }

    /**
     * @param LastErrorAlert|null $lastErrorAlert
     * @return array|null
     */
    private function getErrorArray(LastErrorAlert $lastErrorAlert = null)
    {
        if ($lastErrorAlert) {
            return [
                'message' => $lastErrorAlert->getMessage(),
                'time' => $lastErrorAlert->getTime(),
                'isWarning' => AlertCodes::checkWarning($lastErrorAlert->getCode())
            ];
        }

        return null;
    }
}
