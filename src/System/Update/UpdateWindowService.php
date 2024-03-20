<?php

namespace Datto\System\Update;

use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\System\Update\Serializer\HourlyUpdateWindowSerializer;
use Exception;
use InvalidArgumentException;

/**
 * Manage the update window for image-based upgrades.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class UpdateWindowService
{
    const UPDATE_WINDOW_KEY = 'updateWindow';
    const MINIMUM_WINDOW_DURATION_IN_HOURS = 4;

    /** @var DeviceConfig|null */
    private $deviceConfig;

    /** @var HourlyUpdateWindowSerializer */
    private $hourlyUpdateWindowSerializer;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        DeviceConfig $deviceConfig = null,
        HourlyUpdateWindowSerializer $hourlyUpdateWindowSerializer = null,
        FeatureService $featureService = null
    ) {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->hourlyUpdateWindowSerializer = $hourlyUpdateWindowSerializer ?: new HourlyUpdateWindowSerializer();
        $this->featureService = $featureService ?: new FeatureService();
    }

    /**
     * Set the update window for the device.
     *
     * @param int $startHour
     * @param int $endHour
     */
    public function setWindow($startHour, $endHour)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_UPGRADES)) {
            throw new Exception('Setting the update window is not supported');
        }

        // validate
        if (!$this->isValidHour($startHour)) {
            throw new InvalidArgumentException('Start hour must be an integer between 0-24 (inclusive)');
        }

        if (!$this->isValidHour($endHour)) {
            throw new InvalidArgumentException('End hour must be an integer between 0-24 (inclusive)');
        }

        if (!$this->isValidDuration($startHour, $endHour)) {
            throw new InvalidArgumentException('Window must be at least 4 hours long');
        }

        $window = new HourlyUpdateWindow($startHour, $endHour);

        // serialize object
        $serialized = $this->hourlyUpdateWindowSerializer->serialize($window);

        $encoded = json_encode($serialized);

        // write
        $this->deviceConfig->set(self::UPDATE_WINDOW_KEY, $encoded);
    }

    /**
     * Get the devices update window.
     *
     * @return HourlyUpdateWindow
     */
    public function getWindow()
    {
        // read file
        if (!$this->deviceConfig->has(self::UPDATE_WINDOW_KEY)) {
            return self::getDefault();
        }

        $decoded = @json_decode($this->deviceConfig->get(self::UPDATE_WINDOW_KEY), true);
        if (!$decoded) {
            return self::getDefault();
        }

        // unserialize object
        return $this->hourlyUpdateWindowSerializer->unserialize($decoded);
    }

    /**
     * @param mixed $hour
     * @return bool
     */
    private function isValidHour($hour)
    {
        return isset($hour) && is_int($hour) && $hour >= 0 && $hour <= 24;
    }

    /**
     * @param int $startHour
     * @param int $endHour
     * @return bool
     */
    private function isValidDuration($startHour, $endHour)
    {
        $crossesMidnight = $startHour > $endHour;

        if ($crossesMidnight) {
            $duration = (24 - $startHour) + $endHour;
        } else {
            $duration = $endHour - $startHour;
        }

        return $duration >= self::MINIMUM_WINDOW_DURATION_IN_HOURS;
    }

    /**
     * @return HourlyUpdateWindow
     */
    public static function getDefault()
    {
        return new HourlyUpdateWindow(
            HourlyUpdateWindowSerializer::DEFAULT_START_HOUR,
            HourlyUpdateWindowSerializer::DEFAULT_END_HOUR
        );
    }
}
