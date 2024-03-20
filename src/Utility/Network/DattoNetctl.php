<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Util\RetryHandler;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * A thin wrapper around the datto-netctl tool that is used to interact with cloud networks
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class DattoNetctl implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const DATTO_NETCTL = 'datto-netctl';
    private ProcessFactory $processFactory;
    private RetryHandler $retryHandler;
    private Filesystem $filesystem;

    public function __construct(ProcessFactory $processFactory, RetryHandler $retryHandler, Filesystem $filesystem)
    {
        $this->processFactory = $processFactory;
        $this->retryHandler = $retryHandler;
        $this->filesystem = $filesystem;
    }

    public function connect(
        string $networkUuid,
        string $shortCode,
        string $parentFqdn,
        string $parentPort,
        string $clientKeyPath,
        string $clientCertificatePath,
        string $caCertificatePath
    ): void {
        try {
            $this->executeUntilCompletion('network:client:external:connect', [
                $networkUuid,
                $shortCode,
                $parentFqdn,
                $parentPort,
                '--client-key',
                $clientKeyPath,
                '--client-crt',
                $clientCertificatePath,
                '--ca-crt',
                $caCertificatePath
            ]);
        } finally {
            // datto-netctl copies the credentials into its own managed directory so clean up the old directory
            $this->filesystem->unlinkIfExists($clientKeyPath);
            $this->filesystem->unlinkIfExists($clientCertificatePath);
            $this->filesystem->unlinkIfExists($caCertificatePath);
        }
    }

    public function delete(string $networkUuid): void
    {
        $this->executeUntilCompletion('network:delete', [$networkUuid]);
    }

    public function networkExists(string $networkUuid): bool
    {
        $networkRecord = $this->executeUntilCompletion('network:list', [$networkUuid, '--json'], false);

        return !empty($networkRecord['networkUuid']);
    }

    public function stop(string $networkUuid): void
    {
        $this->executeUntilCompletion('network:stop', [$networkUuid]);
    }

    /**
     * Executes a datto-netctl command and wait for a full JSON object or array to be output.
     *
     * @param string $command the datto-netctl command to execute.
     * @param array $arguments arguments to the datto-netctl command.
     * @param bool $checkExitCode if true, a non-zero exit code in the JSON output will throw an exception.
     * @return array Contains the json_decoded output from the successfully executed datto-netctl command
     */
    private function executeUntilCompletion(string $command, array $arguments = [], bool $checkExitCode = true): array
    {
        $this->logger->info('CVN0001 datto-netctl invocation starting...', [
            'command' => $command,
            'arguments' => $arguments
        ]);
        // Create the process to run the command
        $dattoNetctlProcess = $this->processFactory->get(array_merge([
            self::DATTO_NETCTL,
            $command
        ], $arguments));

        // mustRun() was not detecting the end of the process causing a timeout exception
        $dattoNetctlProcess->start();
        $output = $this->retryHandler->executeAllowRetry(function () use ($dattoNetctlProcess, $command, $arguments) {
            // We know when the process has finished when json_decode successfully parses process output
            $output = json_decode($dattoNetctlProcess->getOutput(), true);
            if (is_null($output)) {
                $argsList = implode(' ', $arguments);
                throw new Exception("datto-netctl $command $argsList invocation still running...");
            }
            return $output;
        }, 12, 5);

        $context = [
            'command' => $command,
            'arguments' => $arguments
        ];
        if (!empty($output['result']['clientName'])) {
            $context['clientName'] = $output['result']['clientName'];
        }

        if (!empty($output['exitCode'])) {
            $context['exitCode'] = $output['exitCode'];
            if ($checkExitCode && $output['exitCode'] !== 0) {
                $context['errorMessage'] = $output['results']['errorMessage'];
                $this->logger->error('CVN0021 datto-netctl invocation errored', $context);
                throw new Exception("Error running datto-netctl $command");
            }
        }

        $this->logger->info('CVN0022 datto-netctl invocation completed successfully', $context);

        return $output;
    }
}
