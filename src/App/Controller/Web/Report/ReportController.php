<?php

namespace Datto\App\Controller\Web\Report;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\Agent\AgentService;
use Datto\Reporting\Aggregated\CsvGenerator;
use Datto\Reporting\Aggregated\Report;
use Datto\Reporting\Aggregated\ReportService;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for managing backup and screenshot reports.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReportController extends AbstractBaseController
{
    const FORMAT_CSV = 'csv';
    const FORMAT_HTML = 'html';
    const FORMATS = [
        self::FORMAT_CSV,
        self::FORMAT_HTML
    ];

    private AgentService $agentService;
    private ReportService $reportService;
    private DateTimeService $dateService;
    private AgentConfigFactory $agentConfigFactory;
    private DateTimeZoneService $timezoneService;
    private Filesystem $filesystem;
    private CsvGenerator $csvGenerator;
    private TranslatorInterface $translator;

    public function __construct(
        NetworkService $networkService,
        AgentService $agentService,
        ReportService $reportService,
        DateTimeService $dateService,
        DateTimeZoneService $timezoneService,
        AgentConfigFactory $agentConfigFactory,
        Filesystem $filesystem,
        CsvGenerator $csvGenerator,
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->agentService = $agentService;
        $this->reportService = $reportService;
        $this->dateService = $dateService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->timezoneService = $timezoneService;
        $this->filesystem = $filesystem;
        $this->csvGenerator = $csvGenerator;
        $this->translator = $translator;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_BACKUP_REPORTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BACKUP_REPORT_READ")
     *
     * @param string $timeframe
     * @return Response
     */
    public function indexAction(string $timeframe): Response
    {
        $agents = $this->agentService->getAll();
        $earliestEpoch = $this->reportService->getEpochFromTimeframe($timeframe);
        $summaries = [];

        foreach ($agents as $agent) {
            if ($agent->getOriginDevice()->isReplicated()) {
                continue;
            }
            $summary = $this->reportService->getSummary($agent, $earliestEpoch);
            $agentInfo = $this->getAgentInfo($agent->getKeyName());

            $exportAllUrls = $this->generateExportUrls($timeframe, $agent->getKeyName());
            $exportScheduledUrls = $this->generateExportUrls(
                $timeframe,
                $agent->getKeyName(),
                Report::TYPE_SCHEDULED
            );
            $exportForcedUrls = $this->generateExportUrls($timeframe, $agent->getKeyName(), Report::TYPE_FORCED);
            $exportScreenshotsUrls = $this->generateExportUrls(
                $timeframe,
                $agent->getKeyName(),
                Report::TYPE_SCREENSHOTS
            );

            $summaries[] = [
                'keyName' => $agent->getKeyName(),
                'displayName' => $agent->getDisplayName(),
                'pairName' => $agent->getPairName(),
                'hostName' => $agentInfo['hostname'] ?? null,
                'osName' => $agentInfo['os_name'] ?? null,
                'arch' => $agentInfo['arch'] ?? null,
                'summary' => $summary->toArray(),
                'exportAllUrls' => $exportAllUrls,
                'exportScheduledUrls' => $exportScheduledUrls,
                'exportForcedUrls' => $exportForcedUrls,
                'exportScreenshotsUrls' => $exportScreenshotsUrls,
                'exportTypesUrls' => [
                    Report::TYPE_SCREENSHOTS => $exportScreenshotsUrls,
                    Report::TYPE_FORCED => $exportForcedUrls,
                    Report::TYPE_SCHEDULED => $exportScheduledUrls
                ]
            ];
        }

        $params = [
            'agents' => $summaries,
            'timeframeSelections' => $this->generateTimeframeSelections($timeframe),
            'timeframe' => $timeframe,
            'timezone' => $this->timezoneService->getTimeZone(),
            'exportUrls' => $this->generateExportUrls($timeframe)
        ];

        return $this->render('Report/Backup/index.html.twig', $params);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_BACKUP_REPORTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BACKUP_REPORT_READ")
     *
     * @param Request $request
     * @param string $timeframe
     * @return Response
     * @throw \Exception
     *      Thrown if a unsupported format was provided.
     */
    public function exportAction(Request $request, string $timeframe): Response
    {
        $assetKey = $request->query->get('assetKey');
        $type = $request->query->get('type');
        $format = $request->getRequestFormat(null);

        $earliestEpoch = $this->reportService->getEpochFromTimeframe($timeframe);

        if ($assetKey) {
            $agents = [$this->agentService->get($assetKey)];
            $fileName = $this->getExportFileName($earliestEpoch, $format, $agents[0]);
        } else {
            $agents = array_filter($this->agentService->getAll(), function ($k) {
                /** @var Agent $k */
                return !$k->getOriginDevice()->isReplicated();
            });
            $fileName = $this->getExportFileName($earliestEpoch, $format);
        }

        if ($format === self::FORMAT_CSV) {
            return $this->exportCsv($fileName, $agents, $earliestEpoch, $type);
        } elseif ($format === self::FORMAT_HTML) {
            return $this->exportHtml($agents, $earliestEpoch, $type);
        } else {
            throw new \Exception('Unsupported format: ' . $format);
        }
    }

    /**
     * For CSV exports, we can use a StreamedResponse since the generation can output directly to a file handle.
     *
     * @param string $filename
     * @param array $agents
     * @param int $earliestEpoch
     * @param string|null $type
     * @return Response
     */
    private function exportCsv(string $filename, array $agents, int $earliestEpoch, string $type = null): Response
    {
        $response = new StreamedResponse();
        $response->setCallback(function () use ($agents, $earliestEpoch, $type) {
            $handle = $this->filesystem->open('php://output', 'w+');

            $records = $this->reportService->getAllRecords($agents, $earliestEpoch, $type);
            $this->csvGenerator->generate($handle, $records);

            $this->filesystem->close($handle);
        });

        $response->setStatusCode(200);

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * For HTML exports, we simply display the page which can then be printed.
     *
     * @param array $agents
     * @param int $earliestEpoch
     * @param string|null $type
     * @return Response
     */
    private function exportHtml(array $agents, int $earliestEpoch, string $type = null): Response
    {
        $recordGroups = $this->prepareRecordGroups($agents, $earliestEpoch, $type);

        return $this->render('Report/Backup/print.html.twig', [
            'start' => $earliestEpoch,
            'end' => $this->dateService->getTime(),
            'recordGroups' => $recordGroups
        ]);
    }

    /**
     * @param string $timeframe
     * @param string|null $assetKey
     * @param string|null $type
     * @return array
     */
    private function generateExportUrls(string $timeframe, string $assetKey = null, string $type = null): array
    {
        $urls = [];
        foreach (self::FORMATS as $format) {
            $urls[$format] = $this->generateUrl('report_backup_export', [
                'timeframe' => $timeframe,
                'assetKey' => $assetKey,
                'type' => $type,
                '_format' => $format
            ]);
        }

        return $urls;
    }

    /**
     * @param string $requestedTimeframe
     * @return array
     */
    private function generateTimeframeSelections(string $requestedTimeframe): array
    {
        $timeframeSelections = [];
        foreach (ReportService::TIMEFRAMES as $timeframe) {
            $timeframeSelections = $this->addTimeframeSelection($timeframeSelections, $timeframe, $requestedTimeframe);
        }

        return $timeframeSelections;
    }

    /**
     * @param array $timeframeSelections
     * @param string $timeframe
     * @param string $requestedTimeframe
     * @return array
     */
    private function addTimeframeSelection(
        array $timeframeSelections,
        string $timeframe,
        string $requestedTimeframe
    ): array {
        $url = $this->generateUrl('report_backup', [
            'timeframe' => $timeframe
        ]);

        $timeframeSelections[$timeframe] = [
            'url' => $url,
            'selected' => $timeframe === $requestedTimeframe
        ];

        return $timeframeSelections;
    }

    /**
     * @param string $assetKey
     * @return array|null
     */
    private function getAgentInfo(string $assetKey)
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $agentInfo = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);

        return $agentInfo;
    }

    /**
     * @param int $earliestEpoch
     * @param string $extension
     * @param Agent|null $agent
     * @return string
     */
    private function getExportFileName(int $earliestEpoch, string $extension, Agent $agent = null): string
    {
        $format = str_replace('/', '-', $this->timezoneService->universalDateFormat('date'));
        $startString = $this->dateService->format($format, $earliestEpoch);
        $endString = $this->dateService->format($format);

        $params = [
            '%start%' => $startString,
            '%end%' => $endString,
            '%extension%' => $extension
        ];

        if ($agent) {
            $id = 'report.export.filename.agent';
            $params['%agent%'] = $agent->getFullyQualifiedDomainName() ?: $agent->getName();
        } else {
            $id = 'report.export.filename';
        }

        return $this->translator->trans($id, $params);
    }

    /**
     * Get all records grouped records together by agent.
     *
     * @param array $agents
     * @param int $earliestEpoch
     * @param string|null $type
     * @return array
     */
    private function prepareRecordGroups(array $agents, int $earliestEpoch, string $type = null): array
    {
        $recordGroups = [];
        $records = $this->reportService->getAllRecords($agents, $earliestEpoch, $type);

        foreach ($records as $record) {
            $assetKey = $record['keyName'];

            if (!isset($recordGroups[$assetKey])) {
                $agentInfo = $this->getAgentInfo($record['keyName']);

                $recordGroups[$assetKey] = [
                    'name' => $record['name'],
                    'hostname' => $agentInfo['hostname'] ?? '',
                    'os' => $agentInfo['os'] ?? '',
                    'osName' => $agentInfo['os_name'] ?? '',
                    'arch' => $agentInfo['arch'] ?? '',
                    'records' => [$record]
                ];
            } else {
                $recordGroups[$assetKey]['records'][] = $record;
            }
        }

        return $recordGroups;
    }
}
