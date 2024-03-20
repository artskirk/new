<?php

namespace Datto\Asset\Agent;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\AssetCloneManager;
use Datto\Block\LoopManager;
use Psr\Log\LoggerAwareInterface;
use Exception;

class MountLoopHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected Filesystem $filesystem;
    private ProcessFactory $processFactory;
    private AssetCloneManager $cloneManager;
    private EncryptionService $encryptionService;
    private DmCryptManager $dmCrypt;
    private LoopManager $loopManager;

    public function __construct(
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        DmCryptManager $dmCrypt,
        LoopManager $loopManager,
        AssetCloneManager $cloneManager,
        EncryptionService $encryptionService
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->dmCrypt = $dmCrypt;
        $this->loopManager = $loopManager;
        $this->cloneManager = $cloneManager;
        $this->encryptionService = $encryptionService;
    }

    public function attachLoopDevices(string $assetName, string $srcDir, bool $encrypted): array
    {
        // Attach loop devices.
        $loopMap = array();
        $this->logger->setAssetContext($assetName);
        $this->dmCrypt->setLogger($this->logger);

        if ($encrypted && !$this->areDettosDecrypted($srcDir)) {
            // If it is a shadow snap device, it is already decrypted earlier on, so it skips this code block.
            // Agent is encrypted. Summon Device Mapper to expose the decrypted images.
            $volumes = $this->filesystem->glob("{$srcDir}/*.detto");
            foreach ($volumes as $volume) {
                try {
                    // Replace the extension on the volume file to get the UUID
                    $uuid = basename($volume, ".detto");

                    $imageFilename = $volume;
                    if ($dmDevices = $this->dmCrypt->getDMCryptDevicesForFile($imageFilename)) {
                        $loops = $this->loopManager->getLoopsOnFile($imageFilename);
                        if (!isset($loops[0])) {
                            throw new Exception("Unable to find loop for $imageFilename");
                        }
                    } else {
                        $this->logger->debug("MLH0021 Attaching encrypted image $uuid");//LAG0021, MAG0021
                        $this->dmCrypt->attach(
                            $imageFilename,
                            $this->encryptionService->getAgentCryptKey($assetName)
                        );

                        $loops = $this->loopManager->getLoopsOnFile($imageFilename);
                        if (!isset($loops[0])) {
                            throw new Exception("Unable to find loop for $imageFilename");
                        }
                        $loopMap[$uuid] = $loops[0];
                    }
                    $this->processFactory
                        ->get(['partprobe', $loopMap[$uuid]])
                        ->run();
                } catch (Exception $e) {
                    $this->logger->error('MLH0023 Error attaching encrypted image', ['error' => $e->getMessage()]);
                }
            }
        } else {
            // Agent is unencrypted. Attach images using normal loop devices.
            $volumes = $this->filesystem->glob("{$srcDir}/*.datto");
            foreach ($volumes as $volume) {
                try {
                    // Replace the extension on the volume file to get the UUID
                    $uuid = basename($volume, ".datto");

                    $imageFilename = $volume;
                    $this->logger->debug("MLH0021 Attaching image $uuid");//LAG0021, MAG0021
                    $loopFlags = LoopManager::LOOP_CREATE_PART_SCAN;
                    $loopInfo = $this->loopManager->create($imageFilename, $loopFlags);
                    $loopMap[$uuid] = $loopInfo;
                } catch (Exception $e) {
                    $this->logger->error('MLH0024 Error attaching image', ['error' => $e->getMessage()]);
                }
            }
        }

        return $loopMap;
    }

    /**
     * Detach underlying devices, based on image files in clone-dir, not mounted loops
     *
     * @param string $cloneDir
     */
    public function detachLoopDevices(string $cloneDir): void
    {
        $this->cloneManager->destroyLoops($cloneDir);
    }

    /**
     * Check whether the detto files have already been decrypted and symlinked
     *
     * @param string $srcDir The directory containing the detto/datto files
     * @return bool True if all detto files have a decrypted datto file symlink, otherwise false
     */
    private function areDettosDecrypted(string $srcDir): bool
    {
        $dettoFiles = $this->filesystem->glob("$srcDir/*.detto");

        foreach ($dettoFiles as $encryptedFile) {
            $dattoFile = substr($encryptedFile, 0, -6) . ".datto";

            if (!$this->filesystem->isLink($dattoFile)) {
                return false;
            }
        }
        return true;
    }
}
