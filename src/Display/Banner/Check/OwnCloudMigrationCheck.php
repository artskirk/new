<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class OwnCloudMigrationCheck extends Check
{
    const OWNCLOUD_MOUNT_PATH = '/datto/owncloud';
    const MIGRATED_NAS_BASE_PATH = '/datto/mounts';

    private Filesystem $filesystem;

    public function __construct(
        Environment $twig,
        Filesystem $filesystem,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->clf ? 'bannerDattodriveMigrationFailed' : 'banner-dattodrive-migration-failed';
    }

    public function check(Context $context): ?Banner
    {
        //If DattoDrive migrated NasShare paths exists and OwnCloud files exists, migration has failed.
        $migratedNasSharePaths = $this->filesystem->glob(self::MIGRATED_NAS_BASE_PATH . "/dattodrv*") ?: [];
        $ownCloudFiles = $this->filesystem->glob(self::OWNCLOUD_MOUNT_PATH . "/data/*/files") ?: [];

        if (count($migratedNasSharePaths) && count($ownCloudFiles)) {
            $userNames = array_map(
            //returns 'user' from /datto/owncloud/data/user/files
                fn($fileName) => preg_replace('/\\/datto\/owncloud\/data\/([^\/]+)\/files.*/', "$1", $fileName, 1),
                $ownCloudFiles
            );
            $parameters = $this->clf ? $this->getDattoDriveMigrationFailedBanner($userNames)->toArray(
            ) : ['failedusers' => $userNames];

            return $this->danger(
                'Banners/DattoDrive/dattodrive-migration-failed.html.twig',
                $parameters,
                Banner::CLOSE_SESSION
            );
        }
        return null;
    }


    private function getDattoDriveMigrationFailedBanner(array $userNames): ClfBanner
    {
        $users = implode(', ', $userNames);
        return $this
            ->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageText($this->translator->trans('banner.dattodrivemigration.failed', ['%failedUsers%' => $users]))
            ->setIsDismissible(true);
    }
}
