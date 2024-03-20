<?php

namespace Datto\Asset\Share\ExternalNas\Serializer;

use Datto\Asset\AssetType;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\LegacySambaMountSerializer;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\ExternalNas\ExternalNasShareBuilder;
use Datto\Dataset\DatasetFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Utility\ByteUnit;
use InvalidArgumentException;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Psr\Log\LoggerAwareInterface;

/**
 * Serialize and unserialize an external nas share object
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LegacyExternalNasShareSerializer implements Serializer, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected LegacyLocalSettingsSerializer $localSerializer;
    protected LegacyOffsiteSettingsSerializer $offsiteSerializer;
    protected LegacyEmailAddressSettingsSerializer $emailAddressesSerializer;
    protected LegacyLastErrorSerializer $lastErrorSerializer;
    protected LegacySambaMountSerializer $sambaMountSerializer;
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
        LegacySambaMountSerializer $sambaMountSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->sambaMountSerializer = $sambaMountSerializer ?? new LegacySambaMountSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
    }

    /**
     * Serialize an ExternalNasShare into an array of file contents.
     *
     * @param ExternalNasShare $share
     * @return array
     */
    public function serialize($share): array
    {
        $dataset = $share->getDataset();

        $fileArray = [
            'agentInfo' => serialize([
                'apiVersion' => '',// N/A
                'hostname' => $share->getName(),
                'nfsEnabled' => '',// N/A
                'Volumes' => [],// N/A
                'os_name' => '',// N/A
                'version' => '',// N/A
                'name' => $share->getName(),
                'hostName' => $share->getName(),
                'afpEnabled' => '',// N/A
                'format' => $share->getFormat(),
                'type' => 'snapnas',// this will not change
                'shareType' => AssetType::EXTERNAL_NAS_SHARE,// this will not change
                'localUsed' => ByteUnit::BYTE()->toGiB($dataset->getUsedSize()),
                'uuid' => $share->getUuid(),
                'smbVersion' => $share->getSmbVersion(),
                'ntlmAuthentication' => $share->getNtlmAuthentication()
            ]),
            'dateAdded' => $share->getDateAdded(),
            'emails' => $this->emailAddressesSerializer->serialize($share->getEmailAddresses()),
            'lastError' => $this->lastErrorSerializer->serialize($share->getLastError()),
            'backupAcls' => $share->isBackupAclsEnabled() ? true : null, // null will clear away the flag.
            'offsiteTarget' => $this->offsiteTargetSerializer->serialize($share->getOffsiteTarget())
        ];

        $fileArray = array_merge_recursive($fileArray, $this->originDeviceSerializer->serialize($share->getOriginDevice()));
        $fileArray = array_merge_recursive($fileArray, $this->localSerializer->serialize($share->getLocal()));
        $fileArray = array_merge_recursive($fileArray, $this->offsiteSerializer->serialize($share->getOffsite()));
        $fileArray = array_merge_recursive($fileArray, $this->sambaMountSerializer->serialize($share->getSambaMount()));

        return $fileArray;
    }

    /**
     * Unserializes the set of share config files into a Share object. Direct shareproperties are unserialized
     * in this function, sub-properties are unserialized using sub-serializers.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return ExternalNasShare Instance of an ExternalNasShare
     */
    public function unserialize($fileArray): ExternalNasShare
    {
        if (!isset($fileArray['agentInfo']) || !$fileArray['agentInfo']) {
            throw new InvalidArgumentException('Cannot read "agentInfo" contents.');
        }

        $agentInfo = @unserialize($fileArray['agentInfo'], ['allowed_classes' => false]);

        if (!isset($agentInfo['name'])) {
            throw new InvalidArgumentException('Cannot read "name" attribute for share.');
        }

        $name = $agentInfo['name'];
        $keyName = $fileArray['keyName'];
        $uuid = $agentInfo['uuid'] ?? null;

        $smbVersion = $agentInfo['smbVersion'] ?? null;
        $ntlmAuthentication = $agentInfo['ntlmAuthentication'] ?? null;

        $fileArray['integrityCheckEnabled'] = false;

        $local = $this->localSerializer->unserialize($fileArray);
        $offsite = $this->offsiteSerializer->unserialize($fileArray);
        $emailAddresses = $this->emailAddressesSerializer->unserialize($fileArray);
        $sambaMount = $this->sambaMountSerializer->unserialize($fileArray);

        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);

        $format = $agentInfo['format'] ?? ExternalNasShare::DEFAULT_FORMAT;
        $backupAcls = $fileArray['backupAcls'] ?? false;

        $originDevice = $this->originDeviceSerializer->unserialize($fileArray);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);

        $builder = new ExternalNasShareBuilder($name, $sambaMount, $this->logger, $this->datasetFactory);
        $builder
            ->keyName($keyName)
            ->local($local)
            ->offsite($offsite)
            ->emailAddresses($emailAddresses)
            ->lastError($lastError)
            ->uuid($uuid)
            ->format($format)
            ->backupAcls($backupAcls)
            ->originDevice($originDevice)
            ->offsiteTarget($offsiteTarget)
            ->smbVersion($smbVersion)
            ->ntlmAuthentication($ntlmAuthentication);

        if (isset($fileArray['dateAdded']) && is_int($fileArray['dateAdded'])) {
            $builder->dateAdded($fileArray['dateAdded']);
        }

        return $builder->build();
    }
}
