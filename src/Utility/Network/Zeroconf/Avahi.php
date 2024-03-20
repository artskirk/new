<?php

namespace Datto\Utility\Network\Zeroconf;

use Datto\Asset\UuidGenerator;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Systemd\Systemctl;
use DOMDocument;
use Exception;
use Psr\Log\LoggerAwareInterface;
use SimpleXMLElement;

class Avahi implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const AVAHI_SERVICES_DIRECTORY = '/etc/avahi/services/';
    public const AVAHI_ADISK_SERVICE_FILENAME = 'adisk.service';
    public const AVAHI_ADISK_SERVICE_TEMPLATE = <<<EOF
<?xml version="1.0" standalone='no'?>
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<service-group>
 <name replace-wildcards="yes">%h</name>
 <service>
   <type>_adisk._tcp</type>
   <txt-record>sys=waMa=0,adVF=0x100</txt-record>
 </service>
</service-group>
EOF;

    public const AVAHI_DEVICE_INFO_SERVICE_FILENAME = 'deviceInfo.service';
    public const AVAHI_DEVICE_INFO_SERVICE_CONTENTS = <<<EOF
<?xml version="1.0" standalone='no'?>
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<service-group>
 <name replace-wildcards="yes">%h</name>
 <service>
   <type>_device-info._tcp</type>
   <port>0</port>
   <txt-record>model=RackMac</txt-record>
 </service>
</service-group>
EOF;
    public const AVAHI_SMB_SERVICE_FILENAME = 'smb.service';
    public const AVAHI_SMB_SERVICE_CONTENTS = <<<EOF
<?xml version="1.0" standalone='no'?>
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<service-group>
 <name replace-wildcards="yes">%h</name>
 <service>
   <type>_smb._tcp</type>
   <port>445</port>
 </service>
</service-group>
EOF;
    public const AVAHI_AFP_SERVICE_FILENAME = 'afp.service';
    public const AVAHI_AFP_SERVICE_CONTENTS = <<<EOF
<?xml version="1.0" standalone='no'?>
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<service-group>
 <name replace-wildcards="yes">%h</name>
 <service>
   <type>_afpovertcp._tcp</type>
   <port>548</port>
 </service>
</service-group>
EOF;
    public const AVAHI_SHARE_TYPE_AFP = '0x81';
    public const AVAHI_SHARE_TYPE_APFS = '0x82';
    private Filesystem $filesystem;
    private Systemctl $systemctl;
    private UuidGenerator $uuidGenerator;

    public function __construct(Filesystem $filesystem, Systemctl $systemctl, UuidGenerator $uuidGenerator)
    {
        $this->filesystem = $filesystem;
        $this->systemctl = $systemctl;
        $this->uuidGenerator = $uuidGenerator;
    }

    /**
     * Updates the Avahi to advertise this exact list of shares for this share type.  Existing shares of a
     * different share type than what is provided will not be modified.
     * If txt-records already exist for all shares in the list, no changes will be made
     * If txt-records need to be added because a share type is now enabled for a share, they will be added
     * If txt-records need to be removed because a share no longer supports a share type, they will be removed
     * If no shares are being advertised, clean up all relevant services (deviceInfo, adisk, and share type service)
     * @param string[] $shareNames The list of share names that should be advertised, for this share type
     * @param string $shareType The type of shares to advertise (AFP or APFS)
     */
    public function updateAvahiServicesForShares(array $shareNames, string $shareType): void
    {
        $this->updateAdiskTxtRecordsFromShares($shareNames, $shareType);

        // After all of this, if there are any shares left, make sure the device info service is there
        // If there are no shares left, remove it
        $shareCount = $this->getAvahiAdvertisedShareCount();
        $this->setAvahiFile(Avahi::AVAHI_DEVICE_INFO_SERVICE_FILENAME, Avahi::AVAHI_DEVICE_INFO_SERVICE_CONTENTS, $shareCount > 0);

        $sharesOfTypeExist = count($shareNames) > 0;
        $this->updateTypeSpecificServices($shareType, $sharesOfTypeExist);

        $this->logger->debug('AVH0009 Reloading avahi-daemon');
        $this->systemctl->reload('avahi-daemon');
    }

    /**
     * Create or remove an avahi service file
     */
    private function setAvahiFile(string $filename, string $contents, bool $shouldExist): bool
    {
        $path = $this->filesystem->pathJoin(self::AVAHI_SERVICES_DIRECTORY, $filename);
        if ($shouldExist) {
            // Write the file and change the permissions.
            // After this, we need to restart the avahi daemon to ensure that these changes get applied by the service.
            $bytesWritten = $this->filesystem->filePutContents($path, $contents);
            $this->filesystem->chmod($path, 0644);
            $success = $bytesWritten >= strlen($contents);
            if (!$success) {
                $this->logger->error('AVH9003 Unable to write avahi file', ['path' => $path, 'contentLength' => strlen($contents)]);
            } else {
                $this->logger->debug('AVH0003 Updated avahi service file', ['serviceName' => $filename]);
            }
        } else {
            $success = $this->filesystem->unlinkIfExists($path);
            if (!$success) {
                $this->logger->error('AVH9004 Unable to remove avahi file', ['path' => $path]);
            } else {
                $this->logger->debug('AVH0004 Disabled avahi service', ['serviceName' => $filename]);
            }
        }

        return $success;
    }

    /**
     * Handle creating or removing avahi service files for the share type being updated
     */
    private function updateTypeSpecificServices(string $shareType, bool $sharesOfTypeExist)
    {
        switch ($shareType) {
            case Avahi::AVAHI_SHARE_TYPE_APFS:
                $this->setAvahiFile(Avahi::AVAHI_SMB_SERVICE_FILENAME, Avahi::AVAHI_SMB_SERVICE_CONTENTS, $sharesOfTypeExist);
                break;
            case Avahi::AVAHI_SHARE_TYPE_AFP:
                $this->setAvahiFile(Avahi::AVAHI_AFP_SERVICE_FILENAME, Avahi::AVAHI_AFP_SERVICE_CONTENTS, $sharesOfTypeExist);
                break;
            default:
                throw new Exception("Unable to create avahi service files for type $shareType");
        }
    }

    /**
     * Creates or removes txt-records from the adisk service, based on the share names that should be advertised
     * for the given share type
     */
    private function updateAdiskTxtRecordsFromShares(array $shareNames, string $shareType)
    {
        $adiskPath = $this->filesystem->pathJoin(Avahi::AVAHI_SERVICES_DIRECTORY, Avahi::AVAHI_ADISK_SERVICE_FILENAME);
        $adiskExists = $this->filesystem->exists($adiskPath);
        $adiskServiceContents = $adiskExists ? $this->filesystem->fileGetContents($adiskPath) : self::AVAHI_ADISK_SERVICE_TEMPLATE;

        $adiskXml = simplexml_load_string($adiskServiceContents);
        $serviceElements = $adiskXml->xpath('/service-group/service');
        $serviceElement = $serviceElements[0];
        if (!$serviceElement) {
            $this->logger->error('AVH9000 service element missing from avahi adisk service contents', ['fromTemplate' => var_export(!$adiskExists, true)]);
            return;
        }

        $shareCount = $this->getAvahiAdvertisedShareCount();
        $existingShares = $this->getExistingShares($serviceElement->{'txt-record'}, $shareType);
        foreach ($shareNames as $shareName) {
            $existingRecord = $this->getMatchingAdiskTxtRecord($shareName, $shareType, $serviceElement->{'txt-record'});
            if (!isset($existingRecord)) {
                $this->addAdiskTxtRecord($shareName, $shareType, $serviceElement);
                $existingShares[] = $shareName;
                $shareCount++;
            }
        }
        $unusedShares = array_diff($existingShares, $shareNames);
        $this->removeUnusedShares($unusedShares, $shareType, $serviceElement->{'txt-record'});
        $shareCount -= count($unusedShares);

        $this->setAvahiFile(Avahi::AVAHI_ADISK_SERVICE_FILENAME, $this->getFormattedXml($adiskXml), $shareCount > 0);
    }

    /**
     * Finds an existing txt-record in the adisk service, and returns it
     * If unable to find a match, returns null
     */
    private function getMatchingAdiskTxtRecord(
        string $shareName,
        string $shareType,
        SimpleXMLElement $txtRecords
    ): ?SimpleXMLElement {
        foreach ($txtRecords as $txtRecord) {
            if ($txtRecordParts = $this->parseTxtRecord($txtRecord)) {
                if ($txtRecordParts['shareType'] === $shareType && $txtRecordParts['shareName'] === $shareName) {
                    return $txtRecord;
                }
            }
        }

        return null;
    }

    /**
     * Add a txt-record element for the new share, including the share type and a generated uuid that includes hyphens
     */
    private function addAdiskTxtRecord(string $shareName, string $shareType, SimpleXMLElement $serviceElement)
    {
        $nextDiskNum = $this->getNextDiskNum($serviceElement->{'txt-record'});
        $formattedUuid = $this->uuidGenerator->get(true);

        $txtRecord = "dk$nextDiskNum=adVN=$shareName,adVF=$shareType,adVU=$formattedUuid";
        $serviceElement->addChild('txt-record', $txtRecord);
    }

    /**
     * Edits the txt-records in-place to remove any shares that should no longer be advertised
     */
    private function removeUnusedShares(array $unusedShares, string $shareType, SimpleXMLElement $txtRecords)
    {
        foreach ($unusedShares as $shareToRemove) {
            for ($i = 0; $i < count($txtRecords); $i++) {
                if ($txtRecordParts = $this->parseTxtRecord($txtRecords[$i])) {
                    if ($txtRecordParts['shareType'] === $shareType && $txtRecordParts['shareName'] === $shareToRemove) {
                        unset($txtRecords[$i]);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Ensures that we get the next available dk number, so that the txt-records will be unique
     */
    private function getNextDiskNum(SimpleXMLElement $txtRecords): int
    {
        $diskNumsInUse = [];
        foreach ($txtRecords as $txtRecord) {
            if ($txtRecordParts = $this->parseTxtRecord($txtRecord)) {
                $diskNumsInUse[] = $txtRecordParts['diskNum'];
            }
        }

        sort($diskNumsInUse, SORT_NUMERIC);
        $maxDisk = count($diskNumsInUse) > 0 ? max($diskNumsInUse) : 0;
        $availableDiskNums = array_diff(range(0, $maxDisk + 1), $diskNumsInUse);
        $nextDiskNum = min($availableDiskNums);

        return $nextDiskNum;
    }

    /**
     * Returns the share names from existing txtRecords for the given share type
     */
    private function getExistingShares(SimpleXMLElement $txtRecords, string $shareType): array
    {
        $existingShares = [];
        foreach ($txtRecords as $txtRecord) {
            if ($txtRecordParts = $this->parseTxtRecord($txtRecord)) {
                if ($txtRecordParts['shareType'] === $shareType) {
                    $existingShares[] = $txtRecordParts['shareName'];
                }
            }
        }
        return $existingShares;
    }

    /**
     * Formats the given xml so it's consistent.  This makes it easier to read and modify.
     */
    private function getFormattedXml(SimpleXMLElement $adiskXml)
    {
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($adiskXml->asXML());

        return $dom->saveXML();
    }

    /**
     * Based on the current contents of the adisk service file, get the number of shares being advertised.
     */
    private function getAvahiAdvertisedShareCount()
    {
        $adiskPath = $this->filesystem->pathJoin(Avahi::AVAHI_SERVICES_DIRECTORY, Avahi::AVAHI_ADISK_SERVICE_FILENAME);
        $adiskExists = $this->filesystem->exists($adiskPath);
        $adiskServiceContents = $adiskExists ? $this->filesystem->fileGetContents($adiskPath) : self::AVAHI_ADISK_SERVICE_TEMPLATE;

        $adiskXml = simplexml_load_string($adiskServiceContents);
        $txtRecords = $adiskXml->xpath('/service-group/service/txt-record');

        // First txt-record is not a share (the waMa record)
        return count($txtRecords) - 1;
    }

    /**
     * Parse the information out of an individual txt-record entry used to advertise an afp or apfs share.
     * Some limited information about the format of txt-record entries that can be read by time machine is available:
     * https://developer.apple.com/library/archive/releasenotes/NetworkingInternetWeb/Time_Machine_SMB_Spec/index.html#//apple_ref/doc/uid/TP40017496-CH1-SW1
     * The dk# identifies the disk number, which should be unique against other txt-records in this service file
     * The adVN value is the share name
     * The adVF value is the share type
     * The adVU should be a unique identifier for the share
     */
    private function parseTxtRecord(SimpleXMLElement $txtRecord): ?array
    {
        if (preg_match("~dk(?<diskNum>\d+)=adVN=(?<shareName>\S[^,]+),adVF=(?<shareType>\S[^,]+),adVU=(?<shareUuid>\S[^,]+)~", $txtRecord, $txtRecordParts)) {
            return $txtRecordParts;
        }
        return null;
    }
}
