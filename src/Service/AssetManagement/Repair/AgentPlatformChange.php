<?php

namespace Datto\Service\AssetManagement\Repair;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\UuidGenerator;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\License\ShadowProtectLicenseManagerFactory;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Updates the files necessary to convert from one Agent Platform to another
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentPlatformChange implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DiffMergeService $diffMergeService;
    private AgentService $agentService;
    private ShadowProtectLicenseManagerFactory $shadowProtectLicenseManagerFactory;
    private AgentConfigFactory $agentConfigFactory;
    private Filesystem $filesystem;
    private SirisStorage $sirisStorage;
    private StorageInterface $storage;

    public function __construct(
        DiffMergeService $diffMergeService,
        AgentService $agentService,
        ShadowProtectLicenseManagerFactory $shadowProtectLicenseManagerFactory,
        AgentConfigFactory $agentConfigFactory,
        Filesystem $filesystem,
        SirisStorage $sirisStorage,
        StorageInterface $storage
    ) {
        $this->diffMergeService = $diffMergeService;
        $this->agentService = $agentService;
        $this->shadowProtectLicenseManagerFactory = $shadowProtectLicenseManagerFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->filesystem = $filesystem;
        $this->sirisStorage = $sirisStorage;
        $this->storage = $storage;
    }

    /**
     * Determine if it's necessary to run through the process of converting a ShadowSnap agent to DWA.  That's the
     * only agent platform change that's currently supported.  The steps are:
     * 1. Convert config files and rename volume files
     * 2. Release the ShadowSnap license
     * 3. Configure the next backup to be a diff merge
     *
     * @param string $agentKeyName The agentKeyName for the agent that may need to be converted to DWA
     * @param AgentPlatform $currentPlatform The agent platform detected, based on which port responded to requests
     */
    public function runIfNeeded(string $agentKeyName, AgentPlatform $currentPlatform)
    {
        $agentConfig = $this->agentConfigFactory->create($agentKeyName);
        $persistedAgentType = $this->agentService->get($agentKeyName)->getPlatform();
        if ($currentPlatform === AgentPlatform::DATTO_WINDOWS_AGENT() && $persistedAgentType === AgentPlatform::SHADOWSNAP()) {
            $this->logger->info('PHD1010 Updating persisted Agent type', ['originalAgentType' => $persistedAgentType->value(), 'updatedAgentType' => $currentPlatform->value()]);
            $this->convertShadowsnapConfigToDwa($agentConfig);
            $this->logger->info('PHD1011 Persisted Agent type has been updated', ['originalAgentType' => $persistedAgentType->value(), 'updatedAgentType' => $currentPlatform->value()]);

            $this->releaseShadowSnapLicense($agentConfig);

            $this->setupDiffMerge($agentConfig);
        }
    }

    private function setupDiffMerge(AgentConfig $agentConfig)
    {
        $this->logger->info('PHD1012 Setting agent to perform a diff merge on next backup, due to agent type change');
        // set force diff merge
        $this->diffMergeService->setDiffMergeAllVolumes($agentConfig->getKeyName());
        $agentConfig->touch('inhibitRollback');
    }

    /**
     * Convert the agent config files from ShadowSnap (which they contain at the time of calling this function) to
     * DWA (which is actually what's installed on the protected system).  This involves renaming the datto/detto files
     * for the volumes as well as updating the 'include' key file to use the correct uuid format for DWA, which
     * includes hyphens.  The 'key' and 'shadowsnap' config files are also removed to complete the conversion.
     *
     * @param AgentConfig $agentConfig The agent config files to perform the conversion on
     */
    private function convertShadowsnapConfigToDwa(AgentConfig $agentConfig)
    {
        $storageId = $this->sirisStorage->getStorageId($agentConfig->getKeyName(), StorageType::STORAGE_TYPE_FILE);
        $storageMountpoint = $this->storage->getStorageInfo($storageId)->getFilePath();
        $files = $this->filesystem->glob("$storageMountpoint/*.{datto,detto}", GLOB_BRACE);
        foreach ($files as $file) {
            $volumeGuid = pathinfo($file, PATHINFO_FILENAME);
            $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

            // rename image files
            if (UuidGenerator::isUuid($volumeGuid, false)) {
                $newVolumeGuid = UuidGenerator::reformat($volumeGuid);
                $newName = "$storageMountpoint/$newVolumeGuid.$fileExtension";
                if (!$this->filesystem->rename($file, $newName)) {
                    throw new Exception("Unable to rename dataset file from $file to $newName");
                }

                // Replace the old volume name (without dashes) in include key file with the new name
                $includeContents = $agentConfig->getRaw('include');
                $updatedContents = str_replace($volumeGuid, $newVolumeGuid, $includeContents);
                $agentConfig->setRaw('include', $updatedContents);
                $this->logger->info('PHD1013 Updated all instances of volume guid in include key', ['previousVolumeGuid' => $volumeGuid, 'updatedVolumeGuid' => $newVolumeGuid, 'result' => $updatedContents]);
            }
        }

        // update key files
        $agentConfig->clear('key');
        $agentConfig->clear('shadowSnap');
    }

    private function releaseShadowSnapLicense(AgentConfig $agentConfig)
    {
        $shadowProtectLicenseManager = $this->shadowProtectLicenseManagerFactory->create($agentConfig->getKeyName());
        try {
            $shadowProtectLicenseManager->releaseUnconditionally();
        } catch (Exception $e) {
            // An exception can be thrown if the license has already been released. Don't prevent switching to
            // DWA in that case since we desperately need to get off of ShadowSnap. Note that any exception
            // is already logged in releaseUnconditionally()
        }
    }
}
