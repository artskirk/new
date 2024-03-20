<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share\Nas\Reports;

use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\Share\ShareService;
use Datto\Reporting\NasShareGrowthReportGenerator;
use Datto\Asset\Share\Nas\NasShare;
use Exception;

/**
 * Endpoint to manage growth report section on configure settings
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class Growth extends AbstractShareEndpoint
{
    private NasShareGrowthReportGenerator $growthReportGenerator;

    public function __construct(
        ShareService $shareService,
        NasShareGrowthReportGenerator $growthReportGenerator
    ) {
        parent::__construct($shareService);
        $this->growthReportGenerator = $growthReportGenerator;
    }

    /**
     * Update a specific shares growth report
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *     "interval" = @Symfony\Component\Validator\Constraints\Type(type="alpha")
     * })
     *
     * @param string $shareName Name of the share
     * @param string $emails string representation of emails
     * @param string $interval never, daily, weekly, monthly
     * @return array
     */
    public function update($shareName, $emails, $interval)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getGrowthReport()->setEmailList($emails);
        $share->getGrowthReport()->setFrequency($interval);
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'growthReport' => $share->getGrowthReport()
        );
    }

    /**
     * Update all share growth reports
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "interval" = @Symfony\Component\Validator\Constraints\Type(type="alpha")
     * })
     *
     * @param string $emails string representation of emails
     * @param string $interval never, daily, weekly, monthly
     * @return array of success/failures
     */
    public function updateAll($emails, $interval)
    {
        /** @var NasShare[] $shares */
        $shares = $this->shareService->getAllLocal();
        $status = array();

        foreach ($shares as $share) {
            if ($share instanceof NasShare) {
                $share->getGrowthReport()->setEmailList($emails);
                $share->getGrowthReport()->setFrequency($interval);

                $this->shareService->save($share);

                $status[] = array(
                    'shareName' => $share->getKeyName(),
                    'growthReport' => $share->getGrowthReport()
                );
            }
        }

        return $status;
    }

    /**
     * Send a test growth report to a customer
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *     "interval" = @Symfony\Component\Validator\Constraints\Type(type="alpha")
     * })
     *
     * @param string $shareName Name of the share
     * @param string $emails string representation of emails
     * @param string $interval never, daily, weekly, monthly
     * @return bool Always true if successful
     */
    public function test($shareName, $emails, $interval)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);
        $originalGrowthReport = $share->getGrowthReport();

        // temporarily set new values (for test email only)
        $this->update($shareName, $emails, $interval);

        $this->growthReportGenerator->load($shareName);
        $response = $this->growthReportGenerator->sendGrowthReport(true);

        // assign back original values
        $this->update($shareName, $originalGrowthReport->getEmailList(), $originalGrowthReport->getFrequency());

        return $response;
    }
}
