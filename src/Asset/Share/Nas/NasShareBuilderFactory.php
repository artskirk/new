<?php

namespace Datto\Asset\Share\Nas;

use Datto\Afp\AfpVolumeManager;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Dataset\DatasetFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Nfs\NfsExportManager;
use Datto\Resource\DateTimeService;
use Datto\Samba\SambaManager;
use Datto\Sftp\SftpManager;
use Datto\Utility\Network\Zeroconf\Avahi;
use Psr\Log\LoggerAwareInterface;

/**
 * A factory to create a NasShareBuilder
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class NasShareBuilderFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DatasetFactory $datasetFactory;
    private ProcessFactory $processFactory;
    private DateTimeService $dateTimeService;
    private Avahi $avahi;
    private SambaManager $sambaManager;
    private OffsiteSettings $offsiteSettings;
    private EmailAddressSettings $emailAddresses;
    private UuidGenerator $uuidGenerator;
    private GrowthReportSettings $growthReportSettings;
    private OriginDevice $originDevice;
    private AfpVolumeManager $afpVolumeManager;
    private Filesystem $filesystem;
    private NfsExportManager $nfsExportManager;
    private SftpManager $sftpManager;

    public function __construct(
        DatasetFactory $datasetFactory,
        ProcessFactory $processFactory,
        DateTimeService $dateTimeService,
        Avahi $avahi,
        SambaManager $sambaManager,
        EmailAddressSettings $emailAddresses,
        UuidGenerator $uuidGenerator,
        GrowthReportSettings $growthReportSettings,
        OriginDevice $originDevice,
        AfpVolumeManager $afpVolumeManager,
        Filesystem $filesystem,
        NfsExportManager $nfsExportManager,
        SftpManager $sftpManager
    ) {
        $this->datasetFactory = $datasetFactory;
        $this->processFactory = $processFactory;
        $this->dateTimeService = $dateTimeService;
        $this->avahi = $avahi;
        $this->sambaManager = $sambaManager;
        $this->emailAddresses = $emailAddresses;
        $this->uuidGenerator = $uuidGenerator;
        $this->growthReportSettings = $growthReportSettings;
        $this->originDevice = $originDevice;
        $this->afpVolumeManager = $afpVolumeManager;
        $this->filesystem = $filesystem;
        $this->nfsExportManager = $nfsExportManager;
        $this->sftpManager = $sftpManager;
    }

    /**
     * Create a NasShareBuilder
     *
     * @param string $name Name of the NAS Share
     * @return NasShareBuilder Builder for a NasShare object
     */
    public function create(string $name): NasShareBuilder
    {
        return new NasShareBuilder(
            $name,
            $this->logger,
            $this->datasetFactory,
            $this->processFactory,
            $this->dateTimeService,
            $this->avahi,
            $this->sambaManager,
            $this->emailAddresses,
            $this->uuidGenerator,
            $this->growthReportSettings,
            $this->originDevice,
            $this->afpVolumeManager,
            $this->filesystem,
            $this->nfsExportManager,
            $this->sftpManager
        );
    }
}
