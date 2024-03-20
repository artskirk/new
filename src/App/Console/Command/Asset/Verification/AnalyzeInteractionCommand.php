<?php
namespace Datto\App\Console\Command\Asset\Verification;

use Datto\Asset\AssetService;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\Common\Utility\Filesystem;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Analyze a recorded Lakitu interaction
 *
 * Examines a CSV file containing a recorded Lakitu interaction (created with
 * `snapctl asset:verification:debug:record`) and outputs whether or not...
 *   • Lakitu responded to every request.
 *   • the interval exceeded 5 seconds.
 *   • the system is affected by CP-9899.
 */
class AnalyzeInteractionCommand extends AbstractVerificationCommand
{
    protected static $defaultName = 'asset:verification:debug:analyze';

    /**
     * Request/response intervals beyond this will be considered too long.
     *
     * The query code has a 5 second interval, but a few milliseconds may be
     * added due to the overhead of executing the PHP.
     *
     * Affected systems have intervals much larger than 5 seconds, so this
     * won't hide any results from us.
     */
    const INTERVAL_THRESHOLD = 5.1;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Filesystem $filesystem,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Analyze a Lakitu interaction from a CSV file')
            ->addArgument(
                'logPath',
                InputArgument::REQUIRED,
                'CSV file path'
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logPath = $input->getArgument('logPath');
        if (!$this->filesystem->exists($logPath)) {
            throw new Exception('File not found: ' . $logPath);
        }

        $rawCsvData = explode("\n", trim($this->filesystem->fileGetContents($logPath)));

        $splitLinesCallable = function ($line) {
            return explode(',', trim($line));
        };

        $log = array_map($splitLinesCallable, $rawCsvData);
        $requestsAndResponses = $this->getRequestsAndResponses($log);

        $affected = false;

        if (!$this->respondedToFinalRequest($requestsAndResponses)) {
            $output->writeln('Lakitu did not respond to the final request.');
            $affected = true;
        } else {
            $output->writeln('Lakitu responded to all requests.');
        }

        $maxInterval = max($this->getIntervals($requestsAndResponses));
        if ($maxInterval > static::INTERVAL_THRESHOLD) {
            $output->writeln('Maximum interval: ' . $maxInterval);
            $affected = true;
        } else {
            $output->writeln('Request/response interval never exceeded 5 seconds.');
        }

        $message = 'CSV file indicates that the system is ' . ($affected ? '' : 'not ') . 'affected.';
        $output->writeln($message);
        return 0;
    }

    /**
     * Filter the log data to only contain the requests and responses
     *
     * @param $log
     *   The log data to analyze.
     *
     * @return array
     *   Only the requests and responses from the log data.
     */
    private function getRequestsAndResponses($log)
    {
        $requestsAndResponsesCallable = function ($entry) {
            return $entry[1] === 'send.success' || $entry[1] === 'recv.message';
        };

        return array_filter($log, $requestsAndResponsesCallable);
    }

    /**
     * Check if the last log entry is a received message
     *
     * @param $log
     *   Filtered log data only containing requests and responses.
     *
     * @return bool
     *   TRUE if the last log entry is a recevied message.
     */
    private function respondedToFinalRequest($log)
    {
        $lastEntry = end($log);

        return $lastEntry[1] === 'recv.message';
    }

    /**
     * Get the intervals between requests and responses
     *
     * @param $log
     *   Filtered log data only containing requests and responses.
     *
     * @return float[]
     *   An array of the intervals between request and response.
     *   A request that never was responded to will be set to PHP_INT_MAX.
     */
    private function getIntervals($log)
    {
        $intervals = array();

        foreach ($log as $entry) {
            if ($entry[1] === 'send.success') {
                $sentTime = $entry[0];
            } elseif ($entry[1] === 'recv.message') {
                $recvTime = $entry[0];

                if (isset($sentTime)) {
                    $intervals[] = $recvTime - $sentTime;
                }

                unset($sentTime, $recvTime);
            }
        }

        return $intervals;
    }
}
