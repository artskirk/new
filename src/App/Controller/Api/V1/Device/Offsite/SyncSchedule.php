<?php

namespace Datto\App\Controller\Api\V1\Device\Offsite;

use Datto\Cloud\OffsiteSyncScheduleService;

/**
 * API Endpoint that adds, retrieves and deletes offsite synchronization schedules.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class SyncSchedule
{
    /** @var OffsiteSyncScheduleService  */
    private $offsiteSyncScheduleService;

    public function __construct(OffsiteSyncScheduleService $offsiteSyncScheduleService)
    {
        $this->offsiteSyncScheduleService = $offsiteSyncScheduleService;
    }

    /**
     * Gets the device offsite schedule specified by schedule id.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "schedId" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Type(type = "int")
     *   },
     * })
     * @param int $scheduleId
     * @return array
     */
    public function get(int $scheduleId)
    {
        return $this->offsiteSyncScheduleService->get($scheduleId);
    }

    /**
     * List all offsite schedules related with the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getAll()
    {
        return $this->offsiteSyncScheduleService->getAll();
    }

    /**
     * Return the ideal maximum amount of data that can be transferred each
     * day of the week depending on the configured schedules.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getWeeklyAverages()
    {
        return $this->offsiteSyncScheduleService->getWeeklyAverages();
    }

    /**
     * Returns all the data of getAll and getWeeklyAverages combined in an array.
     * {
     * "schedules" => getAll,
     * "averages" => getWeeklyAverages
     * }
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getWeeklyAveragesAndSchedules()
    {
        return $this->offsiteSyncScheduleService->getWeeklyAveragesAndSchedules();
    }

    /**
     * Adds an offsite sync schedule configuration for the device.
     * And returns the created entry on success.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "secondsWeekStart" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Type(type = "int")
     *   },
     *   "secondsWeekEnd" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Type(type = "int")
     *   },
     *   "speed" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Type(type = "int")
     *   }
     * })
     * @param int $secondsWeekStart start point measured in number of seconds since Monday at 00:00:00
     * @param int $secondsWeekEnd end point measured in number of seconds since Monday at 00:00:00
     * @param int $speed in Kilobytes per second
     * @return array schedule entry just created.
     */
    public function add(int $secondsWeekStart, int $secondsWeekEnd, int $speed)
    {
        return $this->offsiteSyncScheduleService->add(
            $secondsWeekStart,
            $secondsWeekEnd,
            $speed
        );
    }

    /**
     * Deletes the device offsite schedule specified by schedule id.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "schedId" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Type(type = "int")
     *   }
     * })
     * @param int $scheduleId
     * @return bool
     */
    public function delete(int $scheduleId)
    {
        $this->offsiteSyncScheduleService->delete($scheduleId);
        return true;
    }
}
