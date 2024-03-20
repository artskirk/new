<?php

namespace Datto\Service\Device;

use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Config\ServerNameConfig;
use Datto\Service\Networking\NetworkService;

/**
 * This class is responsible for creating the device environment file.
 * This environment file can then be sourced for systemd services and other processes.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class EnvironmentService
{
    private DeviceConfig $deviceConfig;
    private DeviceState $deviceState;
    private Filesystem $filesystem;
    private NetworkService $networkService;
    private ServerNameConfig $serverNameConfig;
    private CertificateSetStore $certificateSetStore;

    public function __construct(
        DeviceConfig $deviceConfig,
        DeviceState $deviceState,
        Filesystem $filesystem,
        NetworkService $networkService,
        ServerNameConfig $serverNameConfig,
        CertificateSetStore $certificateSetStore
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->deviceState = $deviceState;
        $this->filesystem = $filesystem;
        $this->networkService = $networkService;
        $this->serverNameConfig = $serverNameConfig;
        $this->certificateSetStore = $certificateSetStore;
    }

    /**
     * Write the environment values to an environment file
     */
    public function writeEnvironment()
    {
        $env = [];

        $env[] = "DEVICE_ID=" . $this->deviceConfig->getDeviceId();
        $env[] = "DEVICE_IMAGE_VERSION=" . $this->deviceConfig->getImageVersion();
        $env[] = "DEVICE_SECRET_KEY=" . $this->deviceConfig->getSecretKey();
        $env[] = "DEVICE_HOSTNAME=" . $this->networkService->getShortHostname();
        $env[] = "DEVICE_ROLE=" . $this->deviceConfig->getRole();
        $env[] = "DEVICE_MODEL=" . $this->deviceConfig->get(DeviceConfig::KEY_HARDWARE_MODEL, 'Unknown');
        $env[] = "DEVICE_DEPLOYMENT_ENVIRONMENT=" . $this->deviceConfig->getDeploymentEnvironment();
        $env[] = "DEVICE_DATTOBACKUP_COM=" . $this->serverNameConfig->getServer(
            ServerNameConfig::DEVICE_DATTOBACKUP_COM
        );

        $certificateSets = $this->certificateSetStore->getCertificateSets();
        // Grab the first certificate set, which is the newest
        if (isset($certificateSets[0])) {
            $certificateSet = $certificateSets[0];
            $env[] = "DEVICE_CERT=" . $certificateSet->getDeviceCertPath();
            $env[] = "DEVICE_KEY=" . $certificateSet->getDeviceKeyPath();
        }

        // value is potentially empty, this file format should not have empty values
        if ($this->deviceConfig->hasDatacenterRegion()) {
            $env[] = "DEVICE_DATACENTER_REGION=" . $this->deviceConfig->getDatacenterRegion();
        }

        $this->deviceState->set(DeviceState::ENV_FILE, implode("\n", $env) . "\n");
        $file = $this->deviceState->getKeyFilePath(DeviceState::ENV_FILE);
        $this->filesystem->chmod($file, 0600);
    }
}
