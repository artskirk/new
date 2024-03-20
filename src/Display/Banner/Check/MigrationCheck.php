<?php

namespace Datto\Display\Banner\Check;

use Datto\Display\Banner\Banner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\System\Migration\AbstractMigration;
use Datto\System\Migration\Device\DeviceMigration;
use Datto\System\Migration\MigrationService;
use Datto\System\Migration\ZpoolReplace\ZpoolReplaceMigration;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Handle banners related to field-upgrade/migrations.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class MigrationCheck extends Check
{
    private MigrationService $migrationService;

    /**
     * @param Environment $twig
     * @param MigrationService $migrationService
     */
    public function __construct(
        Environment $twig,
        MigrationService $migrationService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);

        $this->migrationService = $migrationService;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return 'banner-migration';
    }

    /**
     * {@inheritdoc}
     */
    public function check(Context $context)
    {
        $migration = $this->getRunning();
        if ($migration) {
            return $this->buildRunning($migration);
        }

        $migration = $this->getScheduled();
        if ($migration) {
            return $this->buildScheduled($migration);
        }

        $migration = $this->getLatestCompleted();
        if ($migration) {
            return $this->buildCompleted($migration);
        }

        return null;
    }

    /**
     * @return AbstractMigration|null
     */
    private function getRunning()
    {
        $migration = null;

        if ($this->migrationService->isRunning()) {
            $migration = $this->migrationService->getScheduled();
        }

        return $migration;
    }

    /**
     * @return AbstractMigration|null
     */
    private function getScheduled()
    {
        $migration = $this->migrationService->getScheduled();

        if ($migration === null || $migration->isDismissed()) {
            $migration = null;
        }

        return $migration;
    }

    /**
     * @return AbstractMigration|null
     */
    private function getLatestCompleted()
    {
        $migration = $this->migrationService->getLatestCompleted();

        if ($migration === null || $migration->isDismissed()) {
            $migration = null;
        }

        return $migration;
    }

    /**
     * @param AbstractMigration $migration
     * @return Banner
     */
    private function buildRunning(AbstractMigration $migration)
    {
        $parameters = [
            'scheduledAt' => $migration->getScheduleAt()
        ];

        return $this->warning(
            'Banners/Migration/migration.running.html.twig',
            $parameters,
            Banner::CLOSE_SESSION
        );
    }

    /**
     * @param AbstractMigration $migration
     * @return Banner
     */
    private function buildScheduled(AbstractMigration $migration)
    {
        $parameters = [
            'scheduledAt' => $migration->getScheduleAt(),
            'bannerId' => $this->getId()
        ];

        return $this->warning(
            'Banners/Migration/migration.scheduled.html.twig',
            $parameters,
            Banner::CLOSE_SESSION
        );
    }

    /**
     * @param AbstractMigration $migration
     * @return Banner
     */
    private function buildCompleted(AbstractMigration $migration)
    {
        $error = $migration->hasErrorMessage();

        if (!$error) {
            return $this->buildCompletedSuccess();
        } else {
            $retryRoute = $this->getRetryRoute($migration);
            return $this->buildCompletedError($retryRoute);
        }
    }

    /**
     * @return Banner
     */
    private function buildCompletedSuccess()
    {
        return $this->success(
            'Banners/Migration/migration.completed.success.html.twig',
            [],
            Banner::CLOSE_SESSION
        );
    }

    /**
     * @param string $retryRoute
     * @return Banner
     */
    private function buildCompletedError(string $retryRoute)
    {
        return $this->danger(
            'Banners/Migration/migration.completed.error.html.twig',
            ['retryRoute' => $retryRoute],
            Banner::CLOSE_SESSION
        );
    }

    /**
     * @param AbstractMigration $migration
     * @return string
     */
    private function getRetryRoute(AbstractMigration $migration): string
    {
        switch ($migration->getType()) {
            case DeviceMigration::TYPE:
                return 'migrate_device';
            case ZpoolReplaceMigration::TYPE:
                return 'configure_device_migration_index';
            default:
                return '';
        }
    }
}
