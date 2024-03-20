<?php

namespace Datto\Reporting\Aggregated;

use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV generator for backup and screenshot reports.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CsvGenerator
{
    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateService;

    /** @var TranslatorInterface */
    private $translator;

    /**
     * @param Filesystem $filesystem
     * @param DateTimeService $dateService
     * @param TranslatorInterface $translator
     */
    public function __construct(
        Filesystem $filesystem,
        DateTimeService $dateService,
        TranslatorInterface $translator
    ) {
        $this->filesystem = $filesystem;
        $this->dateService = $dateService;
        $this->translator = $translator;
    }

    /**
     * Generate a CSV given a set of records.
     *
     * @param $handle
     * @param array $records
     */
    public function generate($handle, array $records)
    {
        $headers = [
            $this->translator->trans('report.export.csv.headers.agent'),
            $this->translator->trans('report.export.csv.headers.timestamp'),
            $this->translator->trans('report.export.csv.headers.type'),
            $this->translator->trans('report.export.csv.headers.successful')
        ];

        $this->filesystem->putCsv($handle, $headers);

        foreach ($records as $agentName => $record) {
            $entry = [];

            $entry[] = $record['name'];
            $entry[] = $this->dateService->format('m/d/Y g:i:sa', $record['start_time']);
            $entry[] = $this->translator->trans('report.export.csv.body.type.' . $record['type']);
            $entry[] = $record['success'] ? 1 : 0;

            $this->filesystem->putCsv($handle, $entry);
        }
    }
}
