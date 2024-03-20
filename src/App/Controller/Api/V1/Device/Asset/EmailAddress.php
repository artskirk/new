<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;
use Datto\Util\Email\TestEmailService;

/**
 * API endpoint query and change email alert settings
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class EmailAddress extends AbstractAssetEndpoint
{
    /** @var TestEmailService */
    private $testEmailService;

    public function __construct(
        AssetService $assetService,
        TestEmailService $testEmailService
    ) {
        parent::__construct($assetService);
        $this->testEmailService = $testEmailService;
    }

    /**
     * Get e-mail addresses for a certain asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @param string $name Name of the asset
     * @return array Structures list of email lists
     */
    public function getAll($name)
    {
        $asset = $this->assetService->get($name);

        return array(
            'critical' => $asset->getEmailAddresses()->getCritical(),
            'warning' => $asset->getEmailAddresses()->getWarning(),
            'getLog' => $asset->getEmailAddresses()->getLog()
        );
    }

    /**
     * Set warning email alert address list for a asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @param string[] $emails email address list
     * @return bool true if successful
     */
    public function setWarning($name, array $emails)
    {
        $asset = $this->assetService->get($name);

        $asset->getEmailAddresses()->setWarning($emails);
        $this->assetService->save($asset);

        return true;
    }

    /**
     * Set warning email alert address list for all assets
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string[] $emails email address list
     * @param string|null $type asset type
     * @return array Structures list of asset name and emails
     */
    public function setWarningAll(array $emails, $type = null)
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($assets as $asset) {
            $asset->getEmailAddresses()->setWarning($emails);
            $this->assetService->save($asset);

            $status[] = array(
                'name' => $asset->getName(),
                'retention' => $emails
            );
        }

        return $status;
    }

    /**
     * Send a test email for warning alerts
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @return bool true if successful
     */
    public function testWarning(string $name)
    {
        $this->testEmailService->sendTestWarning($name);
        return true;
    }

    /**
     * Set critical error email alert address list for a asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @param string[] $emails email address list
     * @return bool true if successful
     */
    public function setCritical($name, array $emails)
    {
        $asset = $this->assetService->get($name);

        $asset->getEmailAddresses()->setCritical($emails);
        $this->assetService->save($asset);

        return true;
    }

    /**
     * Set critical email alert address list for all assets
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string[] $emails email address list
     * @param string|null $type asset type
     * @return array Structures list of asset name and emails
     */
    public function setCriticalAll(array $emails, $type = null)
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($assets as $asset) {
            $asset->getEmailAddresses()->setCritical($emails);
            $this->assetService->save($asset);

            $status[] = array(
                'name' => $asset->getName(),
                'retention' => $emails
            );
        }

        return $status;
    }

    /**
     * Send a test email for critical alerts
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @return bool true if successful
     */
    public function testCritical(string $name)
    {
        $this->testEmailService->sendTestCritical($name);
        return true;
    }

    /**
     * Set log digest email alert address list for a asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @param string[] $emails email address list
     * @return bool true if successful
     */
    public function setLog($name, array $emails)
    {
        $asset = $this->assetService->get($name);

        $asset->getEmailAddresses()->setLog($emails);
        $this->assetService->save($asset);

        return true;
    }

    /**
     * Set log digest email address list for all assets
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string[] $emails email address list
     * @param string|null $type asset type
     * @return array Structures list of asset name and emails
     */
    public function setLogAll(array $emails, $type = null)
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($assets as $asset) {
            $asset->getEmailAddresses()->setLog($emails);
            $this->assetService->save($asset);

            $status[] = array(
                'name' => $asset->getName(),
                'retention' => $emails
            );
        }

        return $status;
    }

    /**
     * Send a test email for log digests
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset
     * @return bool true if successful
     */
    public function testLog(string $name)
    {
        $this->testEmailService->sendTestLogReport($name);
        return true;
    }

    /**
     * Set emails for all alert settings.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset.
     * @param string[] $weekly Weekly backup reports.
     * @param string[] $warning Warning notices.
     * @param string[] $critical Critical error alerts.
     * @param string[] $log Log digests.
     * @param string[] $screenshotSuccess Screenshot success notifications.
     * @param string[] $screenshotFailed Screenshot failure notifications.
     * @return bool true if successful
     */
    public function setEmails(
        $name,
        array $weekly,
        array $warning,
        array $critical,
        array $log,
        array $screenshotSuccess,
        array $screenshotFailed
    ) {
        $asset = $this->assetService->get($name);

        $asset->getEmailAddresses()->setWeekly($weekly);
        $asset->getEmailAddresses()->setWarning($warning);
        $asset->getEmailAddresses()->setCritical($critical);
        $asset->getEmailAddresses()->setLog($log);
        $asset->getEmailAddresses()->setScreenshotSuccess($screenshotSuccess);
        $asset->getEmailAddresses()->setScreenshotFailed($screenshotFailed);

        $this->assetService->save($asset);

        return true;
    }

    /**
     * Sets email addresses for all alert settings in all assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string[] $weekly Weekly backup reports.
     * @param string[] $warning Warning notices.
     * @param string[] $critical Critical error alerts.
     * @param string[] $log Log digests.
     * @param string[] $screenshotSuccess Screenshot success notifications.
     * @param string[] $screenshotFailed Screenshot failure notifications.
     * @param string|null $type Asset type
     * @return int The number of assets the emails were applied to.
     */
    public function setEmailsAll(
        array $weekly,
        array $warning,
        array $critical,
        array $log,
        array $screenshotSuccess,
        array $screenshotFailed,
        $type = null
    ) {
        $assets = $this->assetService->getAllActiveLocal($type);
        $appliedTo = 0;

        foreach ($assets as $asset) {
            $asset->getEmailAddresses()->setWeekly($weekly);
            $asset->getEmailAddresses()->setWarning($warning);
            $asset->getEmailAddresses()->setCritical($critical);
            $asset->getEmailAddresses()->setLog($log);
            $asset->getEmailAddresses()->setScreenshotSuccess($screenshotSuccess);
            $asset->getEmailAddresses()->setScreenshotFailed($screenshotFailed);

            $this->assetService->save($asset);

            $appliedTo++;
        }

        return $appliedTo;
    }
}
