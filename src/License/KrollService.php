<?php

namespace Datto\License;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Curl\CurlHelper;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service class for dealing with Kroll licenses.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class KrollService
{
    const LICENSE_TYPE = 'exsp';
    const LICENSE_DIRECTORY = 'krollLicenses';

    const MKDIR_MODE = 0777;

    /** @var DeviceConfig */
    private DeviceConfig $deviceConfig;

    /** @var Filesystem */
    private Filesystem $filesystem;

    /** @var CurlHelper */
    private CurlHelper $curlHelper;

    /** @var DeviceLoggerInterface */
    private DeviceLoggerInterface $logger;

    /**
     * @param DeviceConfig|null $deviceConfig
     * @param Filesystem|null $filesystem
     * @param CurlHelper|null $curlHelper
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        DeviceConfig $deviceConfig = null,
        Filesystem $filesystem = null,
        CurlHelper $curlHelper = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->curlHelper = $curlHelper ?: new CurlHelper();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * Download the Exchange/SharePoint/SQL Server license to the device.
     */
    public function logAndDownloadLicense(): void
    {
        $this->logger->info('KSV0001 Downloading Kroll license for Exchange/SharePoint/SQL Server');
        $this->downloadLicense();
    }

    /**
     * Get the path to the license file
     *
     * @return string|null path of the license file if it exists, or null
     */
    public function getLicensePath(): ?string
    {
        $licenseFiles = $this->filesystem->glob($this->getLicenseDirectoryPath() . '/*');
        if (!empty($licenseFiles)) {
            return $licenseFiles[0];
        }
        return null;
    }

    /**
     * Download the license file to the device and save it
     */
    private function downloadLicense(): void
    {
        $response = $this->curlHelper->send(
            'getKrollLicense',
            array(
                'deviceID' => $this->deviceConfig->get('deviceID'),
                'type' => self::LICENSE_TYPE
            )
        );

        $data = json_decode($response, true);
        if (!empty($data['success'])) {
            $this->purgeOldLicenses();
            $this->saveLicense($data['licenseFile'], $data['license']);
        } else {
            $errorMessage = isset($data['error']) ? $data['error'] : 'No error message in response data';
            $this->logger->error('KSV0003 Could not retrieve license data', ['errorMessage' => $errorMessage]);
            throw new Exception('Could not retrieve license data: ' . $errorMessage);
        }
    }

    /**
     * Save the license file in the kroll licenses directory
     *
     * @param string $filename file name of the license to save
     * @param string $encodedData license file contents, base 64 encoded
     */
    private function saveLicense(string $filename, string $encodedData): void
    {
        $licenseDirectory = $this->getLicenseDirectoryPath();

        if (!$this->filesystem->exists($licenseDirectory)) {
            $this->filesystem->mkdir($licenseDirectory, true, self::MKDIR_MODE);
        }

        $this->filesystem->filePutContents("$licenseDirectory/$filename", base64_decode($encodedData));
    }

    /**
     * Remove all old licenses (typically done before saving new ones)
     */
    private function purgeOldLicenses(): void
    {
        $licenseDirectory = $this->getLicenseDirectoryPath();
        if ($this->filesystem->exists($licenseDirectory)) {
            $licenses = $this->filesystem->glob("$licenseDirectory/*");
            foreach ($licenses as $license) {
                $this->filesystem->unlink($license);
            }
        }
    }

    /**
     * @return string directory path that the license should be saved in
     */
    private function getLicenseDirectoryPath(): string
    {
        return $this->deviceConfig->getBasePath() . self::LICENSE_DIRECTORY . DIRECTORY_SEPARATOR . self::LICENSE_TYPE;
    }
}
