<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Asset\Agent\Backup\DiskDriveFactory;
use Datto\Asset\Agent\Backup\Serializer\DiskDriveSerializer;
use Datto\Asset\Agent\Serializer\AgentApiPrePostScriptsSerializer;
use Datto\Asset\AssetType;
use Datto\Backup\BackupContext;
use Datto\Config\AgentConfigFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\ByteUnit;
use Datto\ZFS\ZfsDataset;
use Datto\ZFS\ZfsDatasetService;
use LogicException;

/**
 * Encapsulates the functionality for building the agentInfo files for all agents
 * @author Devon Welcheck <dwelcheck@datto.com>
 */
class AgentInfoBuilderService
{
    const VMWARE_BIOS_NAME_PREFIX = 'VMware';

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var VolumesNormalizer */
    private $volumesNormalizer;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Filesystem */
    private $filesystem;

    /** @var AgentApiPrePostScriptsSerializer */
    private $prePostScriptsSerializer;

    /** @var AgentService */
    private $agentService;

    /** @var DiskDriveFactory */
    private $diskDriveFactory;

    /** @var DiskDriveSerializer */
    private $diskDriveSerializer;

    /** @var IncludedVolumesKeyService */
    private $includedVolumesKeyService;

    /**
     * @param ZfsDatasetService $zfsDatasetService
     * @param VolumesNormalizer $volumesNormalizer
     * @param AgentConfigFactory $agentConfigFactory
     * @param DateTimeService $dateTimeService
     * @param Filesystem $filesystem
     * @param AgentApiPrePostScriptsSerializer $prePostScriptsSerializer
     * @param AgentService $agentService
     * @param DiskDriveFactory $diskDriveFactory
     * @param DiskDriveSerializer $diskDriveSerializer
     * @param IncludedVolumesKeyService $includedVolumesKeyService
     */
    public function __construct(
        ZfsDatasetService $zfsDatasetService,
        VolumesNormalizer $volumesNormalizer,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        Filesystem $filesystem,
        AgentApiPrePostScriptsSerializer $prePostScriptsSerializer,
        AgentService $agentService,
        DiskDriveFactory $diskDriveFactory,
        DiskDriveSerializer $diskDriveSerializer,
        IncludedVolumesKeyService $includedVolumesKeyService
    ) {
        $this->zfsDatasetService = $zfsDatasetService;
        $this->volumesNormalizer = $volumesNormalizer;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->filesystem = $filesystem;
        $this->prePostScriptsSerializer = $prePostScriptsSerializer;
        $this->agentService = $agentService;
        $this->diskDriveFactory = $diskDriveFactory;
        $this->diskDriveSerializer = $diskDriveSerializer;
        $this->includedVolumesKeyService = $includedVolumesKeyService;
    }

    /**
     * Builds the correct agentInfo file based on the given agent's platform.
     * @param AgentRawData $updateData
     * @param bool $updateZfsInfo
     * @return array
     */
    public function buildAgentInfo(
        AgentRawData $updateData,
        bool $updateZfsInfo = true
    ): array {
        $agentPlatform = $updateData->getPlatform();
        $agentConfig = $this->agentConfigFactory->create($updateData->getAssetKey());

        // If the agentInfo file doesn't exist yet (such as on pairing), just
        // use a blank array for comparison against the 'original' file.
        if ($agentConfig->has('agentInfo')) {
            $origAgentInfo = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);
        } else {
            $origAgentInfo = [];
        }

        switch ($agentPlatform) {
            case AgentPlatform::DATTO_WINDOWS_AGENT():
                $agentInfo = $this->buildWindowsAgentInfo($updateData, $origAgentInfo, $updateZfsInfo);
                break;
            case AgentPlatform::DATTO_LINUX_AGENT():
                $agentInfo = $this->buildLinuxAgentInfo($updateData, $origAgentInfo);
                break;
            case AgentPlatform::DIRECT_TO_CLOUD():
                if ($origAgentInfo['type'] === AssetType::LINUX_AGENT) {
                    $agentInfo = $this->buildLinuxAgentInfo($updateData, $origAgentInfo);
                    break;
                }
                $agentInfo = $this->buildWindowsAgentInfo($updateData, $origAgentInfo, $updateZfsInfo);
                break;
            case AgentPlatform::DATTO_MAC_AGENT():
                $agentInfo = $this->buildMacAgentInfo($updateData, $origAgentInfo);
                break;
            case AgentPlatform::SHADOWSNAP():
                $agentInfo = $this->buildShadowSnapAgentInfo($updateData, $origAgentInfo);
                break;
            case AgentPlatform::AGENTLESS_GENERIC():
                $agentInfo = $this->buildAgentlessGenericInfo($updateData, $origAgentInfo);
                break;
            case AgentPlatform::AGENTLESS():
                // For initial asset creation, we can't determine the type from $origAgentInfo since it doesn't
                // exist yet so we use the agent info returned by the host call.
                $typeInfo = isset($origAgentInfo['type']) ? $origAgentInfo : $updateData->getHostResponse();

                if (AssetType::isType(AssetType::AGENTLESS_WINDOWS, $typeInfo)) {
                    $agentInfo = $this->buildAgentlessWindowsInfo($updateData, $origAgentInfo);
                } elseif (AssetType::isType(AssetType::AGENTLESS_LINUX, $typeInfo)) {
                    $agentInfo = $this->buildAgentlessLinuxInfo($updateData, $origAgentInfo);
                } else {
                    throw new LogicException('Invalid agentless asset type.');
                }
                break;
            default:
                throw new LogicException('Invalid agent platform.');
        }

        return $agentInfo;
    }

    /**
     * Serializes the provided agent info array to a keyfile.
     * @param AgentRawData $updateData
     */
    public function saveNewAgentInfo(AgentRawData $updateData)
    {
        $assetKey = $updateData->getAssetKey();
        $platform = $updateData->getPlatform();
        $completeInfo = $updateData->getHostResponse();
        $agentInfo = $updateData->getAgentInfo();

        if (empty($agentInfo)) {
            return;
        }

        $agentConfig = $this->agentConfigFactory->create($assetKey);
        if ($updateData->hasVssWriters()) {
            $agentConfig->setRaw('vssWriters', serialize($updateData->getVssWriters()));
        }

        if ($platform->isAgentless() && isset($completeInfo['esxInfo'])) {
            $esxInfo = $completeInfo['esxInfo'];
            $oldEsxInfo = unserialize($agentConfig->get('esxInfo'), ['allowed_classes' => false]);
            $connectionName = $oldEsxInfo['connectionName'];
            $esxInfo['connectionName'] = $connectionName;

            $agentConfig->setRaw(EsxInfo::KEY_NAME, serialize($esxInfo));

            $diskDrives = $this->diskDriveFactory->createDiskDrivesFromVmdkInfo($completeInfo['esxInfo']['vmdkInfo']);
            $agentConfig->setRaw('diskDrives', $this->diskDriveSerializer->serialize($diskDrives));
        }

        $agentConfig->setRaw('agentInfo', serialize($agentInfo));

        if ($platform === AgentPlatform::DATTO_LINUX_AGENT() && !empty($completeInfo['scriptsPrePost'])) {
            $this->updatePrePostScripts($assetKey, $completeInfo['scriptsPrePost']);
        }
    }

    /**
     * Updates the pre-post (quiescing) scripts for DLA _only_.
     * @param string $assetKey
     * @param array $scriptsPrePost
     */
    public function updatePrePostScripts(
        string $assetKey,
        array $scriptsPrePost
    ): void {
        /** @var \Datto\Asset\Agent\Linux\LinuxAgent $agent */
        $agent = $this->agentService->get($assetKey);
        $agentQuiescingScripts = $this->prePostScriptsSerializer->unserialize($scriptsPrePost);
        $agent->getPrePostScripts()->refresh($agentQuiescingScripts, $agent->getVolumes());
        $this->agentService->save($agent);
    }

    /**
     * Shitty hack to get the complicated symfony UI to play nice with our vss writer data
     *
     * @param array $vssWriters
     * @return array
     */
    public function modifyVssWriterData($vssWriters)
    {
        $newWriters = array();
        foreach ($vssWriters as $guid => $name) {
            $newWriters[] = array("name" => $name, "id" => $guid);
        }
        return $newWriters;
    }

    /**
     * @param string $agentKey
     * @return ZfsDataset|null
     */
    private function getDataset(string $agentKey)
    {
        // On agent pair, the dataset does not exist yet
        if ($this->filesystem->exists(BackupContext::AGENTS_PATH . $agentKey)) {
            return $this->zfsDatasetService->findDataset(BackupContext::AGENTS_PATH . $agentKey);
        }
        return null;
    }

    /**
     * Builds agent info parameters common across all Datto agents.
     * @param string $assetKey
     * @param array $completeInfo API response returned from agent '/host' call.
     * @param array $origAgentInfo the agentInfo file currently stored
     * @param bool $updateZfsInfo
     * @return array
     */
    private function buildCommonAgentInfo(string $assetKey, array $completeInfo, array $origAgentInfo, bool $updateZfsInfo = true): array
    {
        $fqdn = empty($origAgentInfo['fqdn']) ? $assetKey : $origAgentInfo['fqdn'];
        $uuid = $origAgentInfo['uuid'] ?? null;
        $localUsed = isset($origAgentInfo['localUsed']) ? $origAgentInfo['localUsed'] : 0;
        $usedBySnaps = isset($origAgentInfo['usedBySnaps']) ? $origAgentInfo['usedBySnaps'] : 0;

        if ($updateZfsInfo) {
            $dataset = $this->getDataset($assetKey);
            $localUsed = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpace()), 2) : 0;
            $usedBySnaps = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpaceBySnapshots()), 2) : 0;
        }

        $os = $completeInfo['os'];

        return [
            'name' => $assetKey,
            'fqdn' => $fqdn,
            'uuid' => $uuid,
            'arch' => $completeInfo['archBits'],
            'agentVersion' => $completeInfo['agentVersion'],
            'cpus' => $completeInfo['cpus'],
            'ram' => (int)$completeInfo['ram'],
            'os' => $os,
            'os_name' => $os,
            'localUsed' => $localUsed,
            'usedBySnaps' => $usedBySnaps
        ];
    }

    /**
     * Builds the agentInfo file for a given Datto Windows Agent API response.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @param bool $updateZfsInfo
     * @return array
     */
    private function buildWindowsAgentInfo(AgentRawData $updateData, array $origAgentInfo, bool $updateZfsInfo = true): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        $agentInfo = $this->buildCommonAgentInfo($assetKey, $completeInfo, $origAgentInfo, $updateZfsInfo);
        $agentInfo['type'] = AssetType::WINDOWS_AGENT;
        $agentInfo['apiVersion'] = $completeInfo['agentVersion'];
        // DWA 3 replaced 'filterDriverVersion' with 'cbtDriverVersion', and then eventually went back to
        // 'filterDriverVersion', so we have to handle both.
        $agentInfo['version'] = $completeInfo['filterDriverVersion'] ?? $completeInfo['cbtDriverVersion'] ?? 'unknown';
        $agentInfo['driverType'] = $completeInfo['driverType'] ?? 'unknown';
        $agentInfo['hostname'] = $completeInfo['hostname'];
        $agentInfo['hostName'] = $completeInfo['hostname'];

        $smbiosName = $completeInfo['smbiosName'] ?? '';
        $isRunningOnVmWare = strpos($smbiosName, self::VMWARE_BIOS_NAME_PREFIX) === 0;
        $agentInfo['isVirtualMachine'] = $isRunningOnVmWare;

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $vols = $this->volumesNormalizer->normalizeWindowsVolumes($completeInfo['volumes'], $includedVolumesSettings);
        $agentInfo['volumes'] = $vols;

        $formattedOsVersion = null;
        if (isset($completeInfo['os_version'])) {
            $osVersion = $completeInfo['os_version'];
            $formattedOsVersion = $osVersion['major'] . "." . $osVersion["minor"] .
                "." . $osVersion["build"];
        }
        $agentInfo['os_version'] = $formattedOsVersion;

        return $agentInfo;
    }

    /**
     * Builds the agentInfo file for a given Datto Linux Agent API response.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildLinuxAgentInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        $agentInfo = $this->buildCommonAgentInfo($assetKey, $completeInfo, $origAgentInfo);
        $agentInfo['type'] = AssetType::LINUX_AGENT;
        $agentInfo['hostname'] = $completeInfo['hostname'];
        $agentInfo['hostName'] = $completeInfo['hostname'];
        $agentInfo['apiVersion'] = $completeInfo['agentVersion'];
        $agentInfo['version'] = $completeInfo['driverVersion'];
        $agentInfo['kernel'] = $completeInfo['kernel'];

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $vols = $this->volumesNormalizer->normalizeLinuxVolumes($completeInfo['volumes'], $includedVolumesSettings);
        $agentInfo['volumes'] = $vols;

        return $agentInfo;
    }

    /**
     * Builds the agentInfo file for a given Datto Mac Agent API response.
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
     * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release
     * notes for the removal.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildMacAgentInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        $agentInfo = $this->buildCommonAgentInfo($assetKey, $completeInfo, $origAgentInfo);
        $agentInfo['type'] = AssetType::MAC_AGENT;
        $agentInfo['hostname'] = $completeInfo['hostName'];
        $agentInfo['hostName'] = $completeInfo['hostName'];
        $agentInfo['apiVersion'] = $completeInfo['apiVersion'];
        $agentInfo['version'] = $completeInfo['driverVersion'];

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $vols = $this->volumesNormalizer->normalizeMacVolumes($completeInfo['volumes'], $includedVolumesSettings);
        $agentInfo['volumes'] = $vols;

        return $agentInfo;
    }

    /**
     * Builds the agentInfo file for a given ShadowSnap API response.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildShadowSnapAgentInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();
        if (empty($completeInfo)) {
            $completeInfo = $origAgentInfo;
        }

        $agentInfo = $completeInfo;
        $agentInfo['name'] = $assetKey;
        $agentInfo['hostname'] = $completeInfo['hostName'];
        $agentInfo['fqdn'] = empty($origAgentInfo['fqdn']) ? $assetKey : $origAgentInfo['fqdn'];
        $agentInfo['uuid'] = $origAgentInfo['uuid'] ?? null;
        $agentInfo['arch'] = str_replace("bits", "", $completeInfo['archBits']); // difference from common
        $agentInfo['os'] = str_replace("-", " ", $completeInfo['os']); // difference from common

        // todo 'cores' and 'memory' likely aren't used and maybe could be removed
        $agentInfo['cores'] = $completeInfo['cpus'];
        $agentInfo['memory'] = round($completeInfo['ram'] / (1024 * 1024));

        $agentInfo['generated'] = $this->dateTimeService->getTime();
        $agentInfo['version'] = $completeInfo['agentVersion']; // difference from common, common sets agentVersion field

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $agentInfo['volumes'] = $this->volumesNormalizer->normalizeShadowSnapVolumes(
            $completeInfo['volumes'],
            $includedVolumesSettings
        );

        $dataset = $this->getDataset($assetKey);
        $agentInfo['localUsed'] = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpace()), 2) : 0;
        $agentInfo['usedBySnaps'] = $dataset
            ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpaceBySnapshots()), 2)
            : 0;

        if ($updateData->hasWinData()) {
            $winData = $updateData->getWinData();
            $agentInfo['os'] = $winData['long'];
            $agentInfo['os_version'] = $winData["version"];
            $agentInfo['os_name'] = $winData['windows'];
            $agentInfo['os_servicepack'] = $winData['servicePack'];
        }

        foreach ($agentInfo as $key => $value) {
            if (is_string($value)) {
                $agentInfo[$key] = trim($value);
            }
        }

        return $agentInfo;
    }

    /**
     * Builds an agentInfo array for agentless Linux machines.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildAgentlessLinuxInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $normalizedVolumes = $this->volumesNormalizer->normalizeLinuxVolumes($completeInfo['volumes'], $includedVolumesSettings);

        $dataset = $this->getDataset($assetKey);
        $localUsed = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpace()), 2) : 0;
        $usedBySnaps = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpaceBySnapshots()), 2) : 0;

        return [
            'type' => AssetType::AGENTLESS_LINUX,
            'hostname' => $completeInfo['hostname'],
            'hostName' => $completeInfo['hostname'],
            'name' => $completeInfo['name'],
            'uuid' => $origAgentInfo['uuid'] ?? null,
            'arch' => $completeInfo['archBits'],
            'apiVersion' => $completeInfo['apiVersion'],
            'agentVersion' => $completeInfo['agentVersion'],
            'version' => $completeInfo['version'],
            'cpus' => $completeInfo['cpus'],
            'ram' => (int)$completeInfo['ram'],
            'os' => $completeInfo['os'],
            'os_name' => $completeInfo['os_name'],
            'os_version' => $completeInfo['os_version'],
            'kernel' => $completeInfo['kernel'],
            'volumes' => $normalizedVolumes,
            'localUsed' => $localUsed,
            'usedBySnaps' => $usedBySnaps
        ];
    }

    /**
     * Builds an agentInfo array for agentless Windows systems.
     * Currently the agentInfo received from the proxy mimics shadow protect, so this is pretty much the same logic.
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildAgentlessWindowsInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        $agentInfo = $completeInfo;
        $agentInfo['type'] = AssetType::AGENTLESS_WINDOWS;
        $agentInfo['hostname'] = $completeInfo['hostName'];
        $agentInfo['uuid'] = $origAgentInfo['uuid'] ?? null;
        $agentInfo['arch'] = str_replace("bits", "", $completeInfo['archBits']);
        $agentInfo['os'] = str_replace("-", " ", $completeInfo['os']);
        $agentInfo['cores'] = $completeInfo['cpus'];
        $agentInfo['memory'] = round($completeInfo['ram'] / (1024 * 1024));
        $agentInfo['generated'] = $this->dateTimeService->getTime();
        $agentInfo['version'] = $completeInfo['version'];

        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($assetKey);
        $agentInfo['volumes'] = $this->volumesNormalizer->normalizeWindowsVolumes($completeInfo['volumes'], $includedVolumesSettings);

        $dataset = $this->getDataset($assetKey);
        $agentInfo['localUsed'] = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpace()), 2) : 0;
        $agentInfo['usedBySnaps'] = $dataset
            ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpaceBySnapshots()), 2)
            : 0;

        foreach ($agentInfo as $key => $value) {
            if (is_string($value)) {
                $agentInfo[$key] = trim($value);
            }
        }

        return $agentInfo;
    }

    /**
     * Build an agentInfo array for generic agentless systems.
     *
     * @param AgentRawData $updateData
     * @param array $origAgentInfo the agentInfo file currently stored
     * @return array
     */
    private function buildAgentlessGenericInfo(AgentRawData $updateData, array $origAgentInfo): array
    {
        $assetKey = $updateData->getAssetKey();
        $completeInfo = $updateData->getHostResponse();

        // Use the same normalization as Linux, since a lot of these are likely to be
        // unsupported versions of Linux or UNIX anyway.
        $normalizedVolumes = $this->volumesNormalizer->normalizeAgentlessGenericVolumes($completeInfo['volumes']);

        $dataset = $this->getDataset($assetKey);
        $localUsed = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpace()), 2) : 0;
        $usedBySnaps = $dataset ? round(ByteUnit::BYTE()->toGiB($dataset->getUsedSpaceBySnapshots()), 2) : 0;

        $agentInfo = [
            'type' => AssetType::AGENTLESS_GENERIC,
            'hostname' => $completeInfo['hostname'],
            'hostName' => $completeInfo['hostname'],
            'name' => $completeInfo['name'],
            'uuid' => $origAgentInfo['uuid'] ?? null,
            'arch' => $completeInfo['archBits'],
            'apiVersion' => $completeInfo['apiVersion'],
            'agentVersion' => $completeInfo['agentVersion'],
            'version' => $completeInfo['version'],
            'cpus' => $completeInfo['cpus'],
            'ram' => (int)$completeInfo['ram'],
            'os' => $os = $completeInfo['os'],
            'os_name' => $completeInfo['os_name'],
            'os_version' => $completeInfo['os_version'],
            'kernel' => $completeInfo['kernel'],
            'volumes' => $normalizedVolumes,
            'localUsed' => $localUsed,
            'usedBySnaps' => $usedBySnaps
        ];

        return $agentInfo;
    }
}
