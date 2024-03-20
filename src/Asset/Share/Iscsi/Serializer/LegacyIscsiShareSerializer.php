<?php

namespace Datto\Asset\Share\Iscsi\Serializer;

use Datto\Asset\AssetType;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Iscsi\IscsiShareBuilder;
use Datto\Dataset\DatasetFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Utility\ByteUnit;
use InvalidArgumentException;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Psr\Log\LoggerAwareInterface;

/**
 * Serialize and unserialize an iSCSI share object using the contents of the
 * legacy key files (e.g. '.agentInfo', ...)
 *
 * Unserializing:
 *   $iscsiShare = $serializer->unserialize(array(
 *       'agentInfo' => 'a:60:{...',
 *       'interval' => '60',
 *       'backupPause' => '',
 *       // A lot more ...
 *   ));
 *
 * Serializing:
 *   $serializedIscsiFileArray = $serializer->serialize(new IscsiShare(..));
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class LegacyIscsiShareSerializer implements Serializer, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected LegacyLocalSettingsSerializer $localSerializer;
    protected LegacyOffsiteSettingsSerializer $offsiteSerializer;
    protected LegacyEmailAddressSettingsSerializer $emailAddressesSerializer;
    protected LegacyLastErrorSerializer $lastErrorSerializer;
    private OriginDeviceSerializer $originDeviceSerializer;
    private OffsiteTargetSerializer $offsiteTargetSerializer;
    private DatasetFactory $datasetFactory;

    public function __construct(
        DatasetFactory $datasetFactory = null,
        DeviceLoggerInterface $logger = null,
        LegacyLocalSettingsSerializer $localSerializer = null,
        LegacyOffsiteSettingsSerializer $offsiteSerializer = null,
        LegacyEmailAddressSettingsSerializer $emailAddressesSerializer = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
    }

    /**
     * Serialize an IscsiShare into an array of file contents.
     *
     * @param IscsiShare $share
     * @return array
     */
    public function serialize($share): array
    {
        $dataset = $share->getDataset();

        $fileArray = [
            'agentInfo' => serialize([
                'iscsiChapUser' => $share->getChap()->getUser(),
                'apiVersion' => '',// N/A
                'iscsiMutualChapUser' => $share->getChap()->getMutualUser(),
                'hostname' => $share->getName(),
                'nfsEnabled' => '',// N/A
                'Volumes' => [],// N/A
                'os_name' => '',// N/A
                'version' => '',// N/A
                'iscsiBlockSize' => $share->getBlockSize(),
                'name' => $share->getName(),
                'iscsiTarget' => $share->getTargetName(),
                'hostName' => $share->getName(),
                'afpEnabled' => '',// N/A
                'format' => '',// N/A
                'type' => 'snapnas',// this will not change
                'isIscsi' => 1,// this will not change
                'shareType' => AssetType::ISCSI_SHARE,// this will not change
                'localUsed' => ByteUnit::BYTE()->toGiB($dataset->getUsedSize()),
                'uuid' => $share->getUuid(),
            ]),
            'dateAdded' => $share->getDateAdded(),
            'emails' => $this->emailAddressesSerializer->serialize($share->getEmailAddresses()),
            'lastError' => $this->lastErrorSerializer->serialize($share->getLastError()),
            'offsiteTarget' => $this->offsiteTargetSerializer->serialize($share->getOffsiteTarget())
        ];

        $fileArray = array_merge_recursive($fileArray, $this->originDeviceSerializer->serialize($share->getOriginDevice()));
        $fileArray = array_merge_recursive($fileArray, $this->localSerializer->serialize($share->getLocal()));
        $fileArray = array_merge_recursive($fileArray, $this->offsiteSerializer->serialize($share->getOffsite()));

        return $fileArray;
    }

    /**
     * Unserializes the set of share config files into a Share object. Direct shareproperties are unserialized
     * in this function, sub-properties are unserialized using sub-serializers.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return IscsiShare Instance of an IscsiShare
     */
    public function unserialize($fileArray): IscsiShare
    {
        if (empty($fileArray['agentInfo'])) {
            throw new InvalidArgumentException('Cannot read "agentInfo" contents.');
        }

        $agentInfo = @unserialize($fileArray['agentInfo'], ['allowed_classes' => false]);

        if (!isset($agentInfo['name'])) {
            throw new InvalidArgumentException('Cannot read "name" attribute for share.');
        }

        $name = $agentInfo['name'];
        $keyName = $fileArray['keyName'];
        $uuid = $agentInfo['uuid'] ?? null;
        $dateAdded = $fileArray['dateAdded'] ?? null;
        $fileArray['integrityCheckEnabled'] = false;
        $originDevice = $this->originDeviceSerializer->unserialize($fileArray);

        $local = $this->localSerializer->unserialize($fileArray);
        $offsite = $this->offsiteSerializer->unserialize($fileArray);
        $emailAddresses = $this->emailAddressesSerializer->unserialize($fileArray);

        $iscsiChapUser = $agentInfo['iscsiChapUser'] ?? '';
        $iscsiMutualChapUser = $agentInfo['iscsiMutualChapUser'] ?? '';

        $lastError = $this->lastErrorSerializer->unserialize($fileArray['lastError'] ?? null);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);

        $blockSize = $agentInfo['iscsiBlockSize'] ?? IscsiShare::DEFAULT_BLOCK_SIZE;

        $builder = new IscsiShareBuilder($name, $this->logger, $this->datasetFactory);
        $share = $builder
            ->keyName($keyName)
            ->dateAdded($dateAdded)
            ->local($local)
            ->blockSize($blockSize)
            ->offsite($offsite)
            ->emailAddresses($emailAddresses)
            ->chapUser($iscsiChapUser)
            ->mutualChapUser($iscsiMutualChapUser)
            ->lastError($lastError)
            ->uuid($uuid)
            ->originDevice($originDevice)
            ->offsiteTarget($offsiteTarget)
            ->build();

        return $share;
    }
}
