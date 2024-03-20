<?php

namespace Datto\Backup\Transport;

use Datto\AppKernel;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Log\DeviceLoggerInterface;
use Datto\Samba\SambaManager;
use Datto\Samba\UserService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;

/**
 * Handles Samba data transfer from the agent to the device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SambaTransport extends BackupTransport implements LoggerAwareInterface
{
    private string $assetKeyName;
    private SambaManager $sambaManager;
    private AgentConfig $agentConfig;
    private DeviceAddress $deviceAddress;
    private UuidGenerator $uuidGenerator;
    private string $shareName;
    private string $sharePath;
    private array $volumes;

    public function __construct(
        string $assetKeyName,
        SambaManager $sambaManager = null,
        AgentConfig $agentConfig = null,
        DeviceAddress $deviceAddress = null,
        UuidGenerator $uuidGenerator = null
    ) {
        $this->assetKeyName = $assetKeyName;
        $this->sambaManager = $sambaManager ?? AppKernel::getBootedInstance()->getContainer()->get(SambaManager::class);
        $this->agentConfig = $agentConfig ?: new AgentConfig($this->assetKeyName);
        $this->deviceAddress = $deviceAddress ?:
            AppKernel::getBootedInstance()->getContainer()->get(DeviceAddress::class);
        $this->uuidGenerator = $uuidGenerator ?: new UuidGenerator();

        $this->shareName = $this->uuidGenerator->get();
        $this->sharePath = '/home/agents/' . $this->assetKeyName;
        $this->volumes = [];
    }

    public function setLogger(LoggerInterface $logger): void
    {
        if (!($logger instanceof DeviceLoggerInterface)) {
            throw new InvalidTypeException('setLogger expected type ' . DeviceLoggerInterface::class . ', received type ' . get_class($logger));
        }
        $this->sambaManager->setLogger($logger);
    }

    /**
     * @inheritdoc
     */
    public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes)
    {
        $this->cleanup();

        $guids = array_keys($imageLoopsOrFiles);
        foreach ($guids as $guid) {
            $this->addVolume($guid);
        }

        $this->createSambaShare();
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedName(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getPort(): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function getVolumeParameters(): array
    {
        return $this->volumes;
    }

    /**
     * @inheritdoc
     */
    public function getApiParameters(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->sambaManager->removeShareByPath($this->sharePath);
    }

    /**
     * Add volume information to Samba settings
     *
     * @param string $volumeGuid
     */
    private function addVolume(string $volumeGuid)
    {
        $exportPath = $this->getExportPath();
        $imageSambaFile = $exportPath . '\\' . $volumeGuid . '.datto';
        $this->volumes[$volumeGuid] = [
            "image" => $imageSambaFile
        ];
    }

    /**
     * Create the Samba share
     */
    private function createSambaShare()
    {
        $sambaShare = $this->sambaManager->createShare($this->shareName, $this->sharePath);

        $shareProperties = [
            'public' => 'yes',
            'guest ok' => 'yes',
            'valid users' => '',
            'admin users' => '',
            'writable' => 'yes',
            'force user' => 'root',
            'force group' => 'root',
            'create mask' => '777',
            'directory mask' => '777',
            'write cache size' => '1048576',
            'browsable' => 'no'
        ];

        $sambaShare->setProperties($shareProperties);
        $this->sambaManager->sync();
    }

    /**
     * Get the Samba share export path
     *
     * @return string
     */
    private function getExportPath(): string
    {
        if ($this->agentConfig->has('hostOverride')) {
            $targetHost = $this->agentConfig->get('hostOverride');
        } else {
            $targetHost = $this->deviceAddress->getLocalIp($this->agentConfig->getFullyQualifiedDomainName());
        }
        $exportPath = sprintf('\\\\%s\\%s', $targetHost, $this->shareName);

        return $exportPath;
    }
}
