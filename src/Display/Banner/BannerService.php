<?php

namespace Datto\Display\Banner;

use Datto\App\Container\ServiceCollection;
use Datto\Config\ShmConfig;
use Datto\Display\Banner\Check\Check;

/**
 * Service class to determine which banners to display
 * on a page. The service allows adding *Check instances
 * which may return a Banner object.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class BannerService
{
    const CACHE_KEY = 'bannerCache';

    /** @var ShmConfig */
    private $shmConfig;

    /** @var Check[] */
    private $checks;

    /**
     * Create new service object
     *
     * @param ShmConfig $shmConfig
     * @param Check[] $checks
     */
    public function __construct(ShmConfig $shmConfig, array $checks = [])
    {
        $this->shmConfig = $shmConfig;
        $this->checks = $checks;
    }

    /**
     * Add a check to the service.
     *
     * @param Check $check
     * @return BannerService
     */
    public function add(Check $check): BannerService
    {
        $this->checks[] = $check;

        return $this;
    }

    /**
     * Returns all of the checks that have been added to the service
     *
     * @return Check[]
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Returns a new BannerService skipping the *Check objects that match the ids passed in
     *
     * @param $checkIds
     * @return BannerService
     */
    public function skip(array $checkIds): BannerService
    {
        $filteredChecks = array_filter($this->checks, function (Check $check) use ($checkIds) {
            return !in_array($check->getId(), $checkIds);
        });

        return new self($this->shmConfig, $filteredChecks);
    }

    /**
     * Returns a new BannerService containing only the *Check objects that match the ids passed in
     *
     * @param array $checkIds
     * @return BannerService
     */
    public function only(array $checkIds): BannerService
    {
        $filteredChecks = array_filter($this->checks, function (Check $check) use ($checkIds) {
            return in_array($check->getId(), $checkIds);
        });

        return new self($this->shmConfig, $filteredChecks);
    }

    /**
     * Iterates over our *Check objects and returns any banners that need to be shown
     *
     * @param Context $context
     * @return Banner[]
     */
    public function checkAll(Context $context): array
    {
        $banners = array();

        foreach ($this->checks as $check) {
            $banner = $check->check($context);

            if ($banner) {
                $banners[] = $banner;
            }
        }

        return $banners;
    }

    /**
     * Iterates over our *Check objects and returns updated banners
     *
     * @param Context $context
     * @return Banner[]
     */
    public function updateAll(Context $context): array
    {
        $banners = array();

        foreach ($this->checks as $check) {
            $banner = $check->update($context);

            if ($banner) {
                $banners[] = $banner;
            }
        }

        return $banners;
    }

    /**
     * @param Banner[] $banners
     * @return array
     */
    public function toArray(array $banners): array
    {
        $bannersArray = [];
        foreach ($banners as $banner) {
            $bannersArray[] = $banner->toArray();
        }

        return $bannersArray;
    }

    /**
     * @param array $banners
     */
    public function cacheBannerArray(array $banners)
    {
        $this->shmConfig->set(self::CACHE_KEY, json_encode($banners));
    }

    /**
     * Get the cached banner array.
     * This is significantly faster than calling checkAll() then toArray().
     *
     * @return array banners
     */
    public static function getCachedBannerArray(): array
    {
        $shmConfig = new ShmConfig();
        $bannersArray = json_decode($shmConfig->get(self::CACHE_KEY, '[]'), true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $bannersArray;
        } else {
            return [];
        }
    }

    /**
     * Construct a banner service given a collection of checks.
     *
     * @param ServiceCollection $checks
     * @return BannerService
     */
    public static function fromServiceCollection(ServiceCollection $checks, ShmConfig $shmConfig): BannerService
    {
        return new self($shmConfig, $checks->getAll());
    }
}
