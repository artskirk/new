<?php

namespace Datto\App\Controller\Web\Advanced;

use Datto\App\Controller\Web\File\AbstractBrowseController;
use Datto\Asset\Agent\AgentService;
use Datto\Common\Resource\ProcessFactory;
use Datto\File\FileEntryService;
use Datto\Filesystem\SearchService;
use Datto\Restore\Insight\InsightResult;
use Datto\Restore\Insight\InsightsService;
use Datto\Restore\Insight\VolumeInsightResult;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;
use Datto\Restore\Insight\InsightsResultsService;
use Symfony\Component\Mime\MimeTypesInterface;

/**
 * Compare Controller
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class CompareController extends AbstractBrowseController
{
    const MOUNT_POINT_FORMAT = "/datto/mounts/%s/%s/%s/%s";
    const INSIGHT_FILE_DOWNLOAD_FORMAT = '/advanced/insight/download/%hostname%/%guid%/%epoch%/%path%';

    private InsightsService $insightService;
    private AgentService $agentService;
    private InsightsResultsService $insightsResultsService;

    public function __construct(
        InsightsService $insightService,
        AgentService $agentService,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        FileEntryService $fileEntryService,
        SearchService $searchService,
        ProcessFactory $processFactory,
        MimeTypesInterface $mimeTypesInterface,
        InsightsResultsService $insightsResultsService,
        NetworkService $networkService,
        ClfService $clfService
    ) {
        parent::__construct(
            $networkService,
            $logger,
            $filesystem,
            $fileEntryService,
            $searchService,
            $processFactory,
            $mimeTypesInterface,
            $clfService
        );

        $this->insightService = $insightService;
        $this->agentService = $agentService;
        $this->insightsResultsService = $insightsResultsService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     *
     * @param string $agentKey
     * @param int $firstPoint
     * @param int $secondPoint
     * @return Response
     */
    public function indexAction(string $agentKey, int $firstPoint, int $secondPoint): Response
    {
        $agent = $this->agentService->get($agentKey);

        return $this->render(
            'Advanced/Insights/Compare/load.html.twig',
            [
                'agentKey' => $agentKey,
                'firstPoint' => $firstPoint,
                'secondPoint' => $secondPoint,
                'agentDisplayName' => $agent->getDisplayName(),
                'volumeMap' => $this->generateVolumeMap($agentKey)
            ]
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     *
     * @param string $agentKey
     * @param int $firstPoint
     * @param int $secondPoint
     * @return Response
     */
    public function browseAction(string $agentKey, int $firstPoint, int $secondPoint)
    {
        $agent = $this->agentService->get($agentKey);

        $results = $this->insightService->getResults($agentKey, $firstPoint, $secondPoint);
        $results->loadResults();

        $results = $this->normalizeResults($results);
        $results = $results->toArray();
        $hasReFsVols = count($this->insightsResultsService->getReFsVols($agent, $firstPoint, $secondPoint)) > 0;

        return $this->render(
            'Advanced/Insights/Compare/browse.html.twig',
            [
                'assetType' => $agent->getType(),
                'volumeMap' => $this->generateVolumeMap($agentKey),
                'agentDisplayName' => $agent->getDisplayName(),
                'agentKey' => $agentKey,
                'format' => static::INSIGHT_FILE_DOWNLOAD_FORMAT,
                'displayName' => $agent->getDisplayName(),
                'states' => [
                    'modified' => VolumeInsightResult::STATE_MODIFIED,
                    'created' => VolumeInsightResult::STATE_CREATED,
                    'deleted' => VolumeInsightResult::STATE_DELETED,
                ],
                'files' => $results,
                'firstPoint' => $firstPoint,
                'secondPoint' => $secondPoint,
                'containsRefsVolumes' => $hasReFsVols
            ]
        );
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BACKUP_INSIGHTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BACKUP_INSIGHTS_WRITE")
     *
     * @param string $agentKey
     * @param string $path
     * @param string $guid
     * @param int $point
     * @return Response
     */
    public function downloadAction(string $agentKey, string $guid, int $point, string $path): Response
    {
        $path = sprintf(static::MOUNT_POINT_FORMAT, $agentKey, $point, $guid, $path);

        return $this->download($path);
    }

    /**
     * Maps the volume mount points to the guids
     *
     * @param string $agentKey
     * @return string[]
     */
    private function generateVolumeMap(string $agentKey): array
    {
        $agent = $this->agentService->get($agentKey);
        $volumes = $agent->getVolumes();
        $map = [];
        foreach ($volumes as $volume) {
            $map[$volume->getMountpoint()] = $volume->getGuid();
        }

        return $map;
    }

    /**
     * Translate the insight results into a format that is more useful for the UI.
     *
     * Current format:
     * [
     *      'created': [[<file information array>], ...]
     *      'deleted': []
     *      'modified': []
     *      'unchanged': []
     * ]
     * Desired format:
     * [
     *  {
     *      'C:\':'Windows':{[<file information array>], <other folders>...}, 'Another Folder': {[...]}
     *  }
     * ]
     *
     * @param InsightResult $results
     * @return InsightResult
     */
    private function normalizeResults(InsightResult $results): InsightResult
    {
        foreach ($results->getResults() as $result) {
            $this->normalizeResult($result);
        }

        return $results;
    }

    /**
     * @param VolumeInsightResult $result
     */
    private function normalizeResult(VolumeInsightResult $result): void
    {
        $tmp = $result->getResults();

        $mount = $result->getVolume()->getMountpoint();

        $newArray = ['isDir'=> true, 'name' => $mount];

        foreach ($tmp as $status => $files) {
            if ($status != 'unchanged') {
                foreach ($files as $file) {
                    $file['status'] = $status;
                    $file['path'] = $file['path'] . $file['filename'];
                    $file['isDir'] = false;
                    $file['changedTime'] = $file['creation_time'];
                    $file['modifiedTime'] = $file['modification_time'];
                    $file['size'] = $file['file_size'];
                    $file['name'] = $file['filename'];

                    $this->filePathToNestedArray($newArray, $file['path'], $file);
                }
            }
        }

        $result->setResults($newArray);
    }

    /**
     * @param $array
     * @param $path
     * @param $value
     * @param string $delimiter
     */
    private function filePathToNestedArray(&$array, $path, $value, $delimiter = '/'): void
    {
        $pathParts = explode($delimiter, $path);

        $current = &$array;
        foreach ($pathParts as $key) {
            if ($key !== '') {
                if (!is_array($current)) {
                    $current = [];
                }
                $current = &$current[$key];
                $current['isDir'] = true;
                $current['name'] = $key;
            }
        }

        $current = $value;
    }
}
