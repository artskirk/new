<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share;

use Datto\Asset\AssetType;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Nas\NasShareBuilderFactory;
use Datto\Asset\Share\Serializer\ShareSerializer;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Core\Asset\Configuration\WeeklySchedule;

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
class Nas extends AbstractShareEndpoint
{
    private ShareSerializer $shareSerializer;
    private CreateShareService $createShareService;
    private NasShareBuilderFactory $nasShareBuilderFactory;

    public function __construct(
        ShareService $shareService,
        ShareSerializer $shareSerializer,
        CreateShareService $createShareService,
        NasShareBuilderFactory $nasShareBuilderFactory
    ) {
        parent::__construct($shareService);
        $this->shareSerializer = $shareSerializer;
        $this->createShareService = $createShareService;
        $this->nasShareBuilderFactory = $nasShareBuilderFactory;
    }

    /**
     * Create a new NAS share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z][A-Za-z\d\-\_]+$~"),
     *   "interval" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "localSchedule" = @Symfony\Component\Validator\Constraints\Type("array"),
     *   "localRetention" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "replication" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^(always|never|\d+)$~"),
     *   "offsiteRetention" = @Symfony\Component\Validator\Constraints\Type("int"),
     *   "format" = @Symfony\Component\Validator\Constraints\Choice(choices = { "ext4" }),
     *   "size" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^\d+[MGT]+$~")
     * })
     * @param string $shareName Name of the share to be created.
     * @param int $interval number of minutes between backups
     * @param int[] $localSchedule local backup schedule as an array of days with arrays of hours inside
     * @param int $localRetention how long to keep local backups
     * @param string $replication always, never, or the number of seconds between sending offsite synchs
     * @param int $offsiteRetention how long to keep offsite backups
     * @param string $format Format for this share
     * @param string $size Size of this share
     * @param string $offsiteTarget "cloud", "noOffsite" or a DeviceID of a siris device to replicate to
     *
     * @return array
     */
    public function add(
        string $shareName,
        int $interval,
        array $localSchedule,
        int $localRetention,
        string $replication,
        int $offsiteRetention,
        string $offsiteTarget,
        string $format = 'ext4',
        string $size = Share::DEFAULT_MAX_SIZE
    ): array {
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

        $builder = $this->nasShareBuilderFactory->create($shareName);
        $share = $builder
            ->format($format)
            ->originDevice($this->createShareService->createOriginDevice())
            ->offsiteTarget($offsiteTarget)
            ->local($localSettings)
            ->offsite($offsiteSettings)
            ->build();

        return $this->shareSerializer->serialize($this->createShareService->create($share, $size));
    }

    /**
     * Create a new NAS share from a template share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z][A-Za-z\d\-\_]+$~"),
     *   "template" = @Datto\App\Security\Constraints\AssetExists(type = "nas")
     * })
     * @param string $shareName Name of the share to be created.
     * @param string $template Name of a share to copy settings from
     *
     * @return array
     */
    public function addFromTemplate(string $shareName, string $template): array
    {
        $templateShare = $this->shareService->get($template);

        $builder = $this->nasShareBuilderFactory->create($shareName);
        $share = $builder
            ->originDevice($this->createShareService->createOriginDevice())
            ->build();

        return $this->shareSerializer->serialize($this->createShareService->create($share, Share::DEFAULT_MAX_SIZE, $templateShare));
    }

    /**
     * List all NAS shares currently on the system.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     *
     * @return array
     */
    public function getAll(): array
    {
        $result = [];
        $shares = $this->shareService->getAll(AssetType::NAS_SHARE);

        foreach ($shares as $share) {
            $result[] = [
                'shareName' => $share->getKeyName(),
                'type' => $share->getType()
            ];
        }

        return $result;
    }
}
