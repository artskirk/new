<?php

namespace Datto\Asset;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Share\ShareService;

/**
 * Generic service to retrieve and list assets.
 *
 * This service uses the AgentService and ShareService to list and check for agents and shares.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AssetService
{
    /** @var AgentService */
    private $agentService;

    /** @var ShareService */
    private $shareService;

    /**
     * Create an asset service object
     *
     * @param AgentService $agentService
     * @param ShareService $shareService
     */
    public function __construct(
        AgentService $agentService = null,
        ShareService $shareService = null
    ) {
        $this->agentService = $agentService ?: new AgentService();
        $this->shareService = $shareService ?: new ShareService();
    }

    /**
     * Checks to see if an asset exists
     *
     * @return bool True if it exists, false otherwise
     */
    public function exists($name)
    {
        return $this->agentService->exists($name)
            || $this->shareService->exists($name);
    }

    /**
     * Retrieve an existing Asset
     *
     * @param string $name Name of the asset
     * @return Asset The requested asset
     */
    public function get($name)
    {
        if ($this->agentService->exists($name)) {
            return $this->agentService->get($name);
        } elseif ($this->shareService->exists($name)) {
            return $this->shareService->get($name);
        } else {
            throw new AssetException("Cannot find asset '$name'");
        }
    }

    /**
     * Retrieve all Asset objects of the relevant asset type configured on this device.
     *
     * Note:
     *   Invalid assets will be ignored by this method.
     *
     * @return Asset[] List of Asset objects
     */
    public function getAll(string $type = null)
    {
        return array_merge($this->agentService->getAll($type), $this->shareService->getAll($type));
    }

    /**
     * Retrieve all local Asset objects (not including replicated) of the relevant asset type configured on this device.
     *
     * Note: Invalid assets will be ignored by this method.
     *
     * @return Asset[] List of Asset objects
     */
    public function getAllLocal(string $type = null)
    {
        return array_merge(
            $this->agentService->getAllLocal($type),
            $this->shareService->getAllLocal($type)
        );
    }

    /**
     * Retrieve all active Asset objects (not including archived) of the relevant asset type configured on this device.
     *
     * Note: Invalid assets will be ignored by this method.
     *
     * @return Asset[] List of Asset objects
     */
    public function getAllActive(string $type = null)
    {
        return array_merge(
            $this->agentService->getAllActive($type),
            $this->shareService->getAll($type)
        );
    }

    /**
     * Retrieve all active and local Asset objects (not including archived nor replicated) of the relevant asset type
     * configured on this device.
     *
     * Note: Invalid assets will be ignored by this method.
     *
     * @return Asset[] List of Asset objects
     */
    public function getAllActiveLocal(string $type = null)
    {
        return array_merge(
            $this->agentService->getAllActiveLocal($type),
            $this->shareService->getAllLocal($type) // shares can't be archived
        );
    }

    /**
     * Get an array of asset keyNames.
     * This is significantly faster than calling getAll()
     *
     * @param string|null $type AssetType
     * @return string[]
     */
    public function getAllKeyNames(string $type = null): array
    {
        return array_merge(
            $this->agentService->getAllKeyNames($type),
            $this->shareService->getAllKeyNames($type)
        );
    }

    /**
     * Get an array of active asset keyNames.
     * This is significantly faster than calling getAll()
     *
     * @param string|null $type AssetType
     * @return string[]
     */
    public function getAllActiveKeyNames(string $type = null): array
    {
        return array_merge(
            $this->agentService->getAllActiveKeyNames($type),
            $this->shareService->getAllKeyNames($type) // shares can't be archived so all are considered active
        );
    }

    /**
     * Get an array of active replicated assets.
     * @return Asset[]
     */
    public function getAllActiveReplicated(): array
    {
        return array_filter($this->getAllActive(), function (Asset $asset) {
            return $asset->getOriginDevice()->isReplicated();
        });
    }

    /**
     * Save the asset using the correct service
     *
     * @param Asset $asset Asset to be saved
     */
    public function save(Asset $asset): void
    {
        if ($asset->isType(AssetType::AGENT)) {
            $this->agentService->save($asset);
        } elseif ($asset->isType(AssetType::SHARE)) {
            $this->shareService->save($asset);
        } else {
            throw new AssetException('No associated asset service for this type of asset.');
        }
    }
}
