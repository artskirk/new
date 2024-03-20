<?php

namespace Datto\Service\Storage;

use Datto\Core\Drives\AbstractDrive;
use Datto\Core\Drives\DriveError;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This class performs basic error checking on an AbstractDrive, and instruments the AbstractDrive objects with
 * error information using a visitor-style pattern.
 *
 * In its current form, this class does very little. Eventually, we should expand this class to query a device-web
 * API to get a list of thresholds for the specific drives (model, firmware version) on the system, and use those
 * thresholds/rules to check the drive attributes. Some stubbed methods for these future hooks have been left in
 * as a reminder of this plan.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class DriveErrorChecker implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Given the list of drives on the system, this will query device-web for the rules that pertain to
     * those drives. We can then use those rules to further specify the attributes and values that we want to check,
     * above and beyond what the vendor selftest results indicate.
     *
     * @param array $drives
     * @return void
     */
    public function getRulesForDrives(array $drives): void
    {
        // TODO: Phase 2
    }

    /**
     * Check a single drive for errors. This essentially implements a visitor pattern, where drive objects are
     * passed to this function, which will do the error checking and populate the errors list for a drive.
     * This error list is then serialized, and will be displayed in the device UI.
     *
     * @param AbstractDrive $drive The drive to check for errors
     */
    public function checkForErrors(AbstractDrive $drive): void
    {
        $this->checkSelfTest($drive);
        $this->checkManufacturerAttributes($drive);
        // TODO: Phase 2
        //$this->checkRules($drive);
    }

    /**
     * Just appends an error if the drive has failed its self-test
     * @param AbstractDrive $drive
     */
    private function checkSelfTest(AbstractDrive $drive): void
    {
        if (!$drive->isSelfTestPassed()) {
            $drive->addError(new DriveError(DriveError::TYPE_SELFTEST, DriveError::LEVEL_ERROR));
        }
    }

    /**
     * Looks for attributes with a manufaturer-defined threshold that has been exceeded
     * @param AbstractDrive $drive
     */
    private function checkManufacturerAttributes(AbstractDrive $drive): void
    {
        foreach ($drive->getHealthAttributes() as $attribute) {
            if ($attribute->isFailing()) {
                $drive->addError(
                    new DriveError(DriveError::TYPE_ATTRIBUTE, DriveError::LEVEL_WARN, $attribute->getName())
                );
            }
        }
    }

    private function checkRules(AbstractDrive $drive): void
    {
        // TODO: Phase 2
    }
}
