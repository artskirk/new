<?php

namespace Datto\Asset\Offsite;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\Retention;
use Datto\Billing;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service to manage the offsite settings for assets.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class OffsiteSettingsService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AssetService $assetService;

    private Billing\Service $billingService;

    private EncryptionService $encryptionService;

    public function __construct(
        AssetService $assetService,
        Billing\Service $billingService,
        EncryptionService $encryptionService
    ) {
        $this->assetService = $assetService;
        $this->billingService = $billingService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Configure the offsite retention for a given asset.
     *
     * @param string $assetKey
     * @param int $daily
     * @param int $weekly
     * @param int $monthly
     * @param int $maximum
     */
    public function setRetention(string $assetKey, int $daily, int $weekly, int $monthly, int $maximum): void
    {
        $asset = $this->assetService->get($assetKey);
        $this->validateAsset($asset);

        $retention = new Retention($daily, $weekly, $monthly, $maximum);
        $this->validateRetention($retention);

        $this->setRetentionForAsset($asset, $retention);
    }

    /**
     * Configure the offsite retention for all assets of a given type.
     *
     * @param int $daily
     * @param int $weekly
     * @param int $monthly
     * @param int $maximum
     * @param string|null $type
     */
    public function setRetentionAll(int $daily, int $weekly, int $monthly, int $maximum, string $type = null): void
    {
        $retention = new Retention($daily, $weekly, $monthly, $maximum);
        $this->validateRetention($retention);

        $assets = $this->assetService->getAllLocal($type);
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("RET0003 Retention not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            $this->setRetentionForAsset($asset, $retention);
        }
    }

    /**
     * Validate offsite retention values.
     *
     * @param Retention $retention
     */
    private function validateRetention(Retention $retention): void
    {
        $isTimeBasedRetention = $this->billingService->isTimeBasedRetention();
        if ($isTimeBasedRetention) {
            throw new Exception('This device has Time Based Retention; cannot change retention settings.');
        }

        $isInfiniteRetention = $this->billingService->isInfiniteRetention();
        $hasInfiniteRetentionGracePeriodExpired = $this->billingService->hasInfiniteRetentionGracePeriodExpired();
        if ($isInfiniteRetention) {
            if ($hasInfiniteRetentionGracePeriodExpired) {
                $allowedMaximum = Retention::INFINITE_MAXIMUM_AFTER_GRACE;
            } else {
                $allowedMaximum = Retention::INFINITE_MAXIMUM;
            }

            $valid = $retention->getDaily() === Retention::INFINITE_DAILY
                && $retention->getWeekly() === Retention::INFINITE_WEEKLY
                && $retention->getMonthly() === Retention::INFINITE_MONTHLY;
            if (!$valid) {
                $message = 'This device has Infinite Cloud Retention and is not permitted to modify its '
                         . 'daily, weekly, and monthly retention values (they are required to be %s, %s,'
                         . ' and %s respectively).';

                throw new Exception(sprintf(
                    $message,
                    Retention::INFINITE_DAILY,
                    Retention::INFINITE_WEEKLY,
                    Retention::INFINITE_MONTHLY
                ));
            }

            $maximum = $retention->getMaximum();
            $validMaximum = $maximum <= $allowedMaximum;
            if (!$validMaximum) {
                $message = 'The maximum retention value provided %s exceeds the allowed maximum %s.';

                throw new Exception(sprintf(
                    $message,
                    $maximum,
                    $allowedMaximum
                ));
            }
        }
    }

    /**
     * Check if given asset supports changing of offsite settings.
     *
     * @param Asset $asset
     */
    private function validateAsset(Asset $asset): void
    {
        if ($asset->getOriginDevice()->isReplicated()) {
            throw new Exception('Changing of offsite setting for replicated agents is not allowed.');
        }
    }

    private function setRetentionForAsset(Asset $asset, Retention $retention): void
    {
        $asset->getOffsite()->setRetention($retention);
        $this->assetService->save($asset);

        $this->logger->setAssetContext($asset->getKeyName());
        $this->logger->info('RET0002 Changed offsite retention', [ // log code is used by device-web see DWI-2252
            'retention' => [
                'daily' => $retention->getDaily(),
                'weekly' => $retention->getWeekly(),
                'monthly' => $retention->getMonthly(),
                'keep' => $retention->getMaximum()
            ]
        ]);
    }
}
