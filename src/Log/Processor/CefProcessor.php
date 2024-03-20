<?php

namespace Datto\Log\Processor;

use Datto\AppKernel;
use Datto\Config\DeviceConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Log\CefCounter;
use Datto\Log\LogRecord;
use Datto\Resource\DateTimeService;
use Throwable;

/**
 * Adds the user to log records.
 * Required if using an Asset* handler or formatter.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CefProcessor
{
    private DateTimeService $dateTimeService;
    private DeviceConfig $deviceConfig;
    private CefCounter $cefCounter;
    private ?DeviceAddress $deviceAddress = null;

    public function __construct(
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig
    ) {
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Processes the given record.
     * todo: since some of the this information is static, set it once outside of this method
     *
     * @param array $record
     * @return array
     */
    public function __invoke($record)
    {
        $logRecord = new LogRecord($record);
        try {
            if (!$logRecord->hasAsset() ||
                !$this->deviceConfig->has('cefActive')) {
                return $record;
            }

            $this->cefCounter = new CefCounter($logRecord->getAsset());

            $extensions = $this->getCefExtensions($logRecord);
            $deviceModel = $this->deviceConfig->get('model', 'BackupDevice');
            $packageVersion = $this->deviceConfig->getOs2Version();

            $logRecord->setCefExtensions($extensions);
            $logRecord->setDeviceModel($deviceModel);
            $logRecord->setPackageVersion($packageVersion);

            // Track alert occurrences
            $this->cefCounter->incrementCount($logRecord->getAlertCode());
        } catch (Throwable $e) {
            // don't allow exception here to prevent logging
        }

        return $logRecord->toArray();
    }

    private function getCefExtensions(LogRecord $logRecord)
    {
        $extensions = [];
        $extensions['start'] = $this->dateTimeService->getTime();
        $extensions['act'] = $logRecord->getAlertCategory();
        $extensions['cnt'] = $this->cefCounter->getCount($logRecord->getAlertCode());

        // Device network information
        $deviceAddress = $this->getDeviceAddress();
        if ($deviceAddress !== null) {
            $deviceIP = $deviceAddress->getLocalIp();
            $extensions['dvc'] = $deviceIP;
            $extensions['dvchost'] = @gethostbyaddr($deviceIP);
        } else {
            $extensions['dvc'] = '';
            $extensions['dvchost'] = '';
        }

        return $extensions;
    }

    /**
     * This is bad but it prevents a few circular dependencies.
     * @return DeviceAddress
     *@todo Inject DeviceAddress instead of creating a new instance. This requires DeviceAddress and its
     *       dependencies not use constructor injection for logging. Currently (Oct. 2021) the InstanceMetadata
     *       service uses a RetryHandler that performs constructor logger injection, which causes a circular
     *       dependency.
     *
     */
    private function getDeviceAddress(): DeviceAddress
    {
        if ($this->deviceAddress === null) {
            $this->deviceAddress = AppKernel::getBootedInstance()->getContainer()->get(DeviceAddress::class);
        }

        return $this->deviceAddress;
    }
}
