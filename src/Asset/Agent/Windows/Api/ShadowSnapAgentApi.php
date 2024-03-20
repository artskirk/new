<?php

namespace Datto\Asset\Agent\Windows\Api;

use Datto\Asset\Agent\AgentNotPairedException;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiException;
use Datto\Asset\Agent\Api\AgentCertificateException;
use Datto\Asset\Agent\Api\AgentRequest;
use Datto\Asset\Agent\Api\AgentTransferResult;
use Datto\Asset\Agent\Api\AgentTransferState;
use Datto\Asset\Agent\Api\BackupApiContext;
use Datto\Asset\Agent\Api\BaseAgentApi;
use Datto\Asset\Agent\Certificate\CertificateSet;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Asset\Agent\Job\BackupJobStatus;
use Datto\Asset\Agent\Job\BackupJobVolumeDetails;
use Datto\Asset\Agent\PairingDeniedException;
use Datto\Asset\Agent\RecoverablePairingFailureException;
use Datto\Backup\File\BackupImageFile;
use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Util\ArraySanitizer;
use Datto\Util\RetryHandler;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Interfaces with the Windows ShadowSnap Agent API
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class ShadowSnapAgentApi extends BaseAgentApi
{
    const CREDENTIALS_USER = 'datto';
    const CREDENTIALS_DEFAULT_PASSWORD = '5t0rag3cr@fT!503';
    const MAGIC_PASS_PHRASE_STRING = 'e36cb6eff811bcd901fde510506e0ab122346c86';
    const SHADOWSNAP_CA_CERT_FILE = 'shadowsnapAgentCaCertV1.crt';

    /** Default Windows ShadowSnap agent port */
    const AGENT_PORT = 25566;

    const NEW_API_VERSION = '4.0.0';

    /** @var string First version of ShadowSnap supporting TLSv1.2 */
    const TLS_V1_2_VERSION = '3.4.1';

    /**
     * These API versions require some extra processing to normalize them to use UTC timestamps
     */
    const NORMALIZE_TO_UTC_API_VERSIONS = ['0.2.5', '0.2.5b'];

    /** Cancel status request attempt, 24 retries * 5 seconds = 2 minutes */
    const CANCEL_STATUS_RETRIES = 24;
    const CANCEL_STATUS_RETRY_WAIT_SECONDS = 5;
    const CANCELLED_STATUS = 'aborted';

    /**
     * Shadow snap allowed commands list version and file path format
     */
    const ALLOWED_COMMANDS_LIST_VERSION = 5;
    const ALLOWED_COMMANDS_LIST_PATH_FORMAT = "/datto/config/shadowsnapCommandWhitelistV%s";
    /**
     * @var string This file has an allowed commands list signed by the original device-web cert.
     * To be able to update the cert on the agent, we need this version of the allowed commands list (or above) on the agent.
     * We have this hard coded allowed commands list because existing agents will only accept a list of allowed commands signed by that original
     * cert and they may not have updated before we release the new cert. Once the agent has the
     * list of allowed commands, we can run the command to update the cert on the agent. At this point the agent will now be able
     * to accept a list of allowed commands signed by the new cert.
     */
    const ALLOWED_COMMANDS_LIST_WITH_CERT_COMMAND_FILENAME = '/datto/config/shadowsnapCommandWhitelistWithCertCommand';

    /** Shadow Snap license types  */
    const LICENSE_TYPE_DESKTOP = 'SPDesktop';
    const LICENSE_TYPE_SERVER = 'SPServer';

    /**
     * These endpoints use the default password when communicating with the agent
     */
    const ENDPOINTS_USING_DEFAULT_PASSWORD = ['basichost', 'pair', 'agentpairticket', 'register'];

    /** @var string */
    private $apiVersion;

    /** @var string[] */
    private $key;

    /** @var Sleep */
    private $sleep;

    /** @var ArraySanitizer */
    private $arraySanitizer;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var array Cached basic host info */
    private $basicHostInfo;

    /** @var string|null Cached default directory to run commands in */
    private $defaultDirectory;

    public function __construct(
        string $agentFqdn,
        DeviceLoggerInterface $logger,
        string $apiVersion = null,
        array $key = [],
        AgentRequest $agentRequest = null,
        RetryHandler $retryHandler = null,
        Sleep $sleep = null,
        ArraySanitizer $arraySanitizer = null,
        Filesystem $filesystem = null,
        JsonRpcClient $cloudClient = null,
        DeviceConfig $deviceConfig = null,
        DateTimeService $dateTimeService = null,
        CertificateSetStore $certificateSetStore = null
    ) {
        $this->apiVersion = $apiVersion;
        $this->key = $key;
        $this->sleep = $sleep ?: new Sleep();
        $this->arraySanitizer = $arraySanitizer ?: new ArraySanitizer();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();

        parent::__construct($agentFqdn, $logger, $agentRequest, $cloudClient, $certificateSetStore, $retryHandler);
    }

    public function getPlatform(): AgentPlatform
    {
        return AgentPlatform::SHADOWSNAP();
    }

    /**
     * Sets the ShadowSnap SSL certs for new agents.
     * Include the default chosen credentials for basic authorization.
     */
    public function initialize()
    {
        // Disable SSL Session Caching, which tends to break ShadowSnap which uses different
        // authentication for different endpoints
        $this->agentRequest->setSslSessionCache(false);

        // Initialize the security level to 1, including TLSv1.0 and TLSv1.1,
        // which are required to get any connection to ShadowSnap agents before v3.4.1
        // Later versions of ShadowSnap will continue to negotiate TLSv1.2 even
        // with a cipher list including these insecure protocols
        $this->agentRequest->setSslCipherList('DEFAULT@SECLEVEL=1');

        if ($this->isNewApiVersion()) {
            $certificateSets = $this->certificateSetStore->getCertificateSets(self::SHADOWSNAP_CA_CERT_FILE);
            $this->agentRequest->includeCertificateSet($certificateSets);
        }

        // If the version supports it, return to the system default cipher list
        if (version_compare($this->apiVersion, self::TLS_V1_2_VERSION) >= 0) {
            $this->agentRequest->setSslCipherList();
        }

        $this->setCredentials();
    }

    public function cleanup()
    {
        // Close open curl session
        $this->agentRequest->closeSession();
    }

    /**
     * Repair agent communication.
     *
     * @param string|null $agentKeyName
     * @param string|null $storedSerialNumber
     * @return array
     */
    public function repairAgent(string $agentKeyName = null, string $storedSerialNumber = null): array
    {
        if ($storedSerialNumber === null) {
            throw new RecoverablePairingFailureException("Serial number registration failed");
        }
        $this->agentRequest->setFreshConnect(1);
        $pairResult = $this->performPairOperation($agentKeyName, $storedSerialNumber);
        $this->agentRequest->setFreshConnect(0);
        return $pairResult;
    }

    public function pairAgent(string $agentKeyName = null)
    {
        $this->agentRequest->setFreshConnect(1);
        $pairResult = $this->performPairOperation($agentKeyName, null);
        $this->agentRequest->setFreshConnect(0);
        return $pairResult;
    }

    public function startBackup(BackupApiContext $backupContext)
    {
        $requestParams = $this->getBackupRequestParams($backupContext);
        $backupTransportParams = $backupContext->getBackupTransport()->getApiParameters();
        $requestParams = array_merge($requestParams, $backupTransportParams);

        $sanitizedRequestParams = $this->arraySanitizer->sanitizeParams($requestParams);
        $this->logger->debug(
            'SSA1020 ShadowSnap API request: Start backup: ' .
            json_encode($sanitizedRequestParams)
        );

        $jsonEncodedRequestParams = json_encode($requestParams);
        $response = $this->attemptRequestAndAddSslCertsIfNeeded(
            AgentRequest::POST,
            'backup',
            $jsonEncodedRequestParams,
            false
        );

        if ($response) {
            $thisJob = trim($response);
            $thisJob = str_replace("/", "", str_replace("backup", "", $thisJob));
            $this->validateJobId($thisJob);
            return $thisJob;
        }

        return null;
    }

    /**
     * Cancel the specified backup job and poll its status to confirm the cancellation
     *
     * The DELETE request to /backup/{jobID} returns the following response:
     * {
     *   "status": "aborting",
     *   "success": true
     * }
     *
     * The GET request to /backup/{jobID} returns the following response:
     * {
     *   "status": "aborted" // when fully cancelled, "aborting" while being cancelled
     *   "details": [
     *     ... // individual volume backup job details
     *   ]
     * }
     */
    public function cancelBackup(string $jobID)
    {
        $cancelRequestSuccess = false;
        $cancelStatusAttempt = 1;
        while ($cancelStatusAttempt <= self::CANCEL_STATUS_RETRIES) {
            try {
                // Attempt to cancel the backup job if we haven't already, this can throw an exception
                if (!$cancelRequestSuccess) {
                    $this->logger->debug(
                        'SSA1021 ShadowSnap API request: Cancel backup for job ID: ' .
                        $jobID
                    );
                    $cancelResponse = $this->attemptRequestAndAddSslCertsIfNeeded(
                        AgentRequest::DELETE,
                        "backup/$jobID",
                        '',
                        true
                    );
                    $cancelRequestSuccess = $cancelResponse['success'] ?? false;
                }

                // Sleep then get the backup job status
                $this->sleep->sleep(self::CANCEL_STATUS_RETRY_WAIT_SECONDS);
                $response = $this->attemptRequestAndAddSslCertsIfNeeded(
                    AgentRequest::GET,
                    "backup/$jobID"
                );

                $isCancelledStatus = ($response['status'] ?? false) === self::CANCELLED_STATUS;
                if ($isCancelledStatus) {
                    // Return a successful response like other agent APIs
                    return ['success' => true];
                }
            } catch (Throwable $e) {
                // Log the message, keep attempting
                $this->logger->warning('SSA1006 Backup job cancel warning', ['exception' => $e]);
            } finally {
                $cancelStatusAttempt++;
            }
        }

        $this->logger->error('SSA1008 Backup job not cancelled after 2 minutes');
        return null;
    }

    public function updateBackupStatus(string $jobID, BackupJobStatus $backupJobStatus = null)
    {
        try {
            $response = $this->attemptRequestAndAddSslCertsIfNeeded(
                AgentRequest::GET,
                "backup/$jobID"
            );

            if ($response) {
                if (empty($jobID)) {
                    return $response;
                }
                $backupJobStatus = $backupJobStatus ?: new BackupJobStatus();
                $this->processBackupStatus($backupJobStatus, $response);
                $backupJobStatus->setJobID($jobID);
                return $backupJobStatus;
            }
        } catch (Throwable $e) {
            $this->logger->error('SSA1004 get backup status request failed', ['exception' => $e]);
            throw $e;
        }

        return null;
    }

    public function getHost()
    {
        try {
            $response = $this->retryHandler->executeAllowRetry(
                function () {
                    $this->logger->debug('SSA1022 ShadowSnap API request: Get host');
                    return $this->attemptRequestAndAddSslCertsIfNeeded(
                        AgentRequest::GET,
                        'host'
                    );
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS,
                $quiet = true
            );

            return $response;
        } catch (Throwable $e) {
            $this->logger->error('SSA1002 host failed', ['exception' => $e]);
            throw $e;
        }
    }

    public function getBasicHost(bool $forceUpdate = false)
    {
        try {
            if (is_null($this->basicHostInfo) || $forceUpdate) {
                $this->logger->debug('SSA1023 ShadowSnap API request: Get basichost');
                // It appears that '/basicHost' is returning unauthorized unless we use the default credentials.
                $this->agentRequest->includeBasicAuthorization(
                    self::CREDENTIALS_USER,
                    self::CREDENTIALS_DEFAULT_PASSWORD
                );
                $this->basicHostInfo = $this->attemptRequestAndAddSslCertsIfNeeded(
                    AgentRequest::GET,
                    'basichost'
                );
            }

            return $this->basicHostInfo;
        } catch (Throwable $e) {
            $this->logger->error('SSA1012 basichost failed', ['exception' => $e]);
        } finally {
            $this->setCredentials();
        }

        return false;
    }

    public function getAgentLogs(int $severity = self::DEFAULT_LOG_SEVERITY, ?int $limit = self::DEFAULT_LOG_LINES)
    {
        $agentLogs = $this->agentRequest->get('event', ['lines' => $limit, 'severity' => $severity]);

        if (is_array($agentLogs)) {
            $version = $this->apiVersion;
            if (in_array($version, self::NORMALIZE_TO_UTC_API_VERSIONS)) {
                // Correct for UTC
                $correction = intval($this->dateTimeService->format('Z'));
            } else {
                // Correct for agent discrepancies
                $response = $this->runCommand('echo', ['%DATE%', '%TIME%']);
                list(, $date, $time) = explode(' ', trim($response['output'][0]));

                // Assume its in us format [ mm/dd/yyyy ]
                list($month, $day, $year) = explode("/", $date);
                if (intval($month) !== intval($this->dateTimeService->format('m'))) {
                    // Whoops it's not lets swap the values
                    list($day, $month) = [$month, $day];
                }
                // If we get a bad value this should convert to 0
                $correction = intval(strtotime("$month/$day/$year $time"));
                if ($correction != 0) {
                    $correction = $this->dateTimeService->getTime() - $correction;
                }
            }

            array_walk($agentLogs['log'], function (&$entry) use ($correction) {
                $microseconds = strstr($entry['created'], '.');
                $time = strtotime($entry['created']) + $correction;
                $entry['created'] = $this->dateTimeService->format('Y-m-d\TH:i:s', $time) . $microseconds;
            });
            return $agentLogs;
        } else {
            return false;
        }
    }

    public function getLatestWorkingCert(): CertificateSet
    {
        $certificateSets = $this->certificateSetStore->getCertificateSets(self::SHADOWSNAP_CA_CERT_FILE);
        $caPathX64 = 'C:\Program Files (x86)\StorageCraft\ShadowProtect\ShadowSnap\ssl\ca.crt';
        $caPathX86 = 'C:\Program Files\StorageCraft\ShadowProtect\ShadowSnap\ssl\ca.crt';
        $caPaths = [$caPathX64, $caPathX86];

        try {
            return $this->retryHandler->executeAllowRetry(
                function () use ($caPaths, $certificateSets) {
                    foreach ($caPaths as $caPath) {
                        $response = $this->runCommand('TYPE', [$caPath], 'C:\\');

                        // When the cert is outputted from the `type` windows command, it has extra stuff in it like \r,
                        //  ' ' and blank lines. This removes the garbage so the md5 will match the certs on the device
                        $cert = implode('', $response['output'] ?? []);
                        if (preg_match('~.*(-----BEGIN CERTIFICATE-----[^-]+-----END CERTIFICATE-----).*~', $cert, $matches)) {
                            $cert = $matches[1];
                        }
                        $cert = trim(preg_replace("~[^-a-zA-Z0-9+=\n/]+~", '', $cert)) . "\n";
                        $cert = str_replace(['BEGINCERTIFICATE', 'ENDCERTIFICATE'], ['BEGIN CERTIFICATE', 'END CERTIFICATE'], $cert);

                        $certHash = md5($cert);

                        foreach ($certificateSets as $certificateSet) {
                            if ($certHash === $certificateSet->getHash()) {
                                return $certificateSet;
                            }
                        }

                        $this->logger->error('SSA0003 Response from agent didn\'t match any of the certificate sets.', ['response' => $response]);
                    }

                    throw new AgentApiException('Can\'t determine latest working cert, ca.crt file on the agent does not match known certs.');
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS,
                $quiet = true
            );
        } catch (Exception $e) {
            // Users of this function expect to get back an AgentCertificateException if there is one
            throw $e->getPrevious() instanceof AgentCertificateException ? $e->getPrevious() : $e;
        }
    }

    public function runCommand(
        string $command,
        array $commandArguments = [],
        string $directory = null
    ) {
        if ($directory === null && $this->defaultDirectory === null) {
            $info = $this->getHost();
            foreach ($info['volumes'] as $volume) {
                if (!empty($volume['mountpoints'])) {
                    $this->defaultDirectory = $volume['mountpoints'];
                    break;
                }
            }
        }

        $directory = $directory ?: $this->defaultDirectory;

        $jsonData = json_encode([
            'executable' => $command,
            'working_dir' => $directory,
            'parameters' => $commandArguments,
            'need_output' => true
        ]);

        $this->logger->debug("CMD0006 Running command against Shadowsnap agent: \"$command " . implode(' ', $commandArguments) . "\" in directory:\"$directory\"");
        return $this->agentRequest->post('command', $jsonData, true);
    }

    public function needsReboot(): bool
    {
        // ShadowSnap does not support checking if the system needs to reboot. Assume it doesn't need to.
        return false;
    }

    public function wantsReboot(): bool
    {
        return false;
    }

    /**
     * Set the agent request credentials based on the action taking place.
     * Multiple Basic Authorization headers may be included, only the last one is used.
     *
     * @param string $action
     */
    public function setCredentials(string $action = ''): void
    {
        $credentials = $this->getCredentials($action);
        $this->agentRequest->includeBasicAuthorization($credentials['user'], $credentials['pass']);
    }

    /**
     * Determine if the agent is using the new api version.
     * If the the apiVersion is not set in the constructor, this will query the agent for the apiVersion.
     *
     * @return bool True if the api version is new
     */
    public function isNewApiVersion(): bool
    {
        if ($this->apiVersion === null) {
            $response = $this->getBasicHost();
            $this->apiVersion = $response['apiVersion'] ?? null;
        }

        return version_compare($this->apiVersion, self::NEW_API_VERSION) >= 0;
    }

    /**
     * Ported from snapFunctions.  Queries the agent for updated VSS writers
     * and returns them in a normalized, usable format.
     * @return array|null
     */
    public function getVssWriters()
    {
        // example run of vssadmin list writers:
        // Writer name: 'VSS Metadata Store Writer'
        //    Writer Id: {0bada1de-01a9-4526-8569-85e756a39dd2}
        //    Writer Instance Id: {f0064dda-936a-3564-a53e-c94b034ade9}
        //    State: [1] Stable
        //    Last error: No error
        // Writer name: 'Performance Counters Writer'
        //    Writer Id: {0bada1de-01a9-4625-8278-69e735f39dd2}
        //    Writer Instance Id: {41db4dbf-6046-470e-8ad5-d5081dfb1b70}
        //    State: [5] Waiting for completion
        //    Last error: No error
        $data = $this->runCommand('vssadmin', ['list', 'writers']);
        if (is_array($data)) {
            $data = explode(PHP_EOL, $data['output'][0]);
            $currentWriter = null;
            $thisWriter = null;
            foreach ($data as $line) {
                $line = str_replace("'", '', $line);

                if (strpos($line, 'Writer name: ') !== false) {
                    if ($thisWriter !== null) {
                        $vssArray[] = $thisWriter;
                    }

                    $currentWriter = trim(str_replace('Writer name: ', '', $line));
                    $thisWriter['name'] = $currentWriter;
                } elseif (strpos($line, 'Writer Id:') !== false) {
                    $currentWriter = trim(str_replace('Writer Id:', '', $line));
                    $thisWriter['id'] = $currentWriter;
                } elseif (strpos($line, 'Writer Instance Id:') !== false) {
                    $currentWriter = trim(str_replace('Writer Instance Id:', '', $line));
                    $thisWriter['instanceId'] = $currentWriter;
                } elseif (strpos($line, 'State:') !== false) {
                    $currentWriter = trim(str_replace('State:', '', $line));
                    $thisWriter['state'] = $currentWriter;
                } elseif (strpos($line, 'Last error:') !== false) {
                    $currentWriter = trim(str_replace('Last error:', '', $line));
                    $thisWriter['state'] = $currentWriter;
                }
            }
            if ($thisWriter !== null) {
                $vssArray[] = $thisWriter;
                return $vssArray;
            }
        }

        $this->logger->error('AGT0175 Querying VSS Writers Failed.. ', [$data]);
        return null;
    }

    /**
     * While ShadowSnap is for Windows, it does not provide the functionality
     * for checking Windows Update status.
     */
    public function needsOsUpdate(): bool
    {
        return false;
    }

    /**
     * Update the root CA that shadowsnap uses.
     *
     * Shadowsnap stores one root CA that it uses for TLS communication, for validating the list of allowed commands, and
     * for validating secure pairing tokens. We have the corresponding private key in device-web. If this expires or
     * gets breached (and we have to start using a new cert), then this function can be used to update the root CA that
     * shadowsnap uses on the protected system.
     *
     * If the cert is expiring, a new one must be issued and this function must be run before the original cert
     *   expires. Otherwise we will be unable to communicate with the agent.
     * If the cert is breached, a new one must be issued and this function can be run anytime after that (since
     *   communication did not break).
     *
     * @param string $certificatePath The path to the certificate we want to give to shadowsnap
     */
    public function updateCertificate(string $certificatePath): void
    {
        $certificateContents = trim($this->filesystem->fileGetContents($certificatePath));
        if (empty($certificateContents)) {
            throw new \Exception('Passed certificate is empty! Path:' . $certificatePath);
        }

        $this->pushHardcodedAllowedCommandsList();

        $injectionCommand = $this->getCertInjectionCommand($certificateContents);
        $cmdArguments = ['/c', $injectionCommand];
        $workingDirectoryX64 = 'C:\Program Files (x86)\StorageCraft\ShadowProtect\ShadowSnap\ssl';
        $workingDirectoryX86 = 'C:\Program Files\StorageCraft\ShadowProtect\ShadowSnap\ssl';

        foreach ([$workingDirectoryX64, $workingDirectoryX86] as $workingDirectory) {
            try {
                $this->logger->info('SSP0001 Attempting to update the certificate for this shadowsnap agent.', ['workingDirectory' => $workingDirectory]);
                $this->runCommand('cmd', $cmdArguments, $workingDirectory);
            } catch (AgentApiException $e) {
                if ($e->getHttpCode() > 300) {
                    $this->logger->error('SSP0002 Failure updating the certificate.', ['exception' => $e]);
                    continue; // try again with the other working directory if we haven't already
                }
            } catch (AgentCertificateException $e) {
                //Acceptable disconnect, check if it worked below
            }

            $this->sleep->sleep(5); // Wait for the shadowsnap server to restart

            // Test an endpoint that requires the certificate for authentication
            if (is_array($this->getHost())) {
                $this->logger->info('SSP0006 Can communicate with shadowsnap after certificate injection.');
                break;
            } else {
                $this->logger->error('SSP0007 Cannot communicate with shadowsnap after certificate injection. Failure.');
                continue; // try again with other working directory if we haven't already
            }
        }
    }

    /**
     * Update allowed commands
     *
     * @param bool $forceRetrieve Set to true to force retrieval of a new list of allowed commands from device web
     * @return bool
     */
    public function updateAllowedCommandsList(bool $forceRetrieve = false)
    {
        $allowedCommands = $this->getCurrentListOfAllowedCommands($forceRetrieve);
        if ($allowedCommands) {
            // Shadowsnap verifies the allowed commands list then stores it in plaintext on the protected system here:
            // C:\Program Files (x86)\StorageCraft\ShadowProtect\ShadowSnap\log\wl.txt
            // After this point, the allowed commands list is never re-verified so we can modify it manually for testing purposes
            $pushResponse = $this->pushAllowedCommandsList(
                $allowedCommands['nonce'],
                $allowedCommands['whitelist'],
                $allowedCommands['__signature']
            );
            return $pushResponse === 'OK';
        }
        return false;
    }

    /**
     * Attempt to get the current list of allowed commands. If one does not exist,
     * download the latest version from device web and store it as the
     * current version. Older version lists are not used.
     *
     * @param bool $forceRetrieve Set to true to force retrieval of a new list of allowed commands from device web
     * @return array|null
     */
    public function getCurrentListOfAllowedCommands(bool $forceRetrieve = false)
    {
        $allowedCommandsList = null;
        $allowedCommandsListPath = sprintf(self::ALLOWED_COMMANDS_LIST_PATH_FORMAT, self::ALLOWED_COMMANDS_LIST_VERSION);

        if (!$forceRetrieve && $this->filesystem->exists($allowedCommandsListPath)) {
            $data = $this->filesystem->fileGetContents($allowedCommandsListPath);
            $allowedCommandsList = json_decode($data, true);
        } else {
            $response = $this->cloudClient->queryWithId('v1/device/asset/agent/commandWhitelist');

            if (!empty($response['success'])) {
                $allowedCommandsList = $response['commandWhitelist'];
                $this->filesystem->filePutContents($allowedCommandsListPath, json_encode($allowedCommandsList));
            }
        }
        return $allowedCommandsList;
    }

    /**
     * This cmd.exe command injects the passed certificate into shadowsnap. Then the command restarts shadowsnap to
     * make it use the new certificate. This command assumes the working directory contains shadowsnap's ca.crt file.
     *
     * To inject the cert, we need to transfer it over to the protected system. We include it inline in the command
     * so we don't have to worry about setting up a samba share or downloading it via http. The problem with including
     * it inline is how to handle newline characters (since cmd.exe does not have a good way to escape them). To handle
     * this, we echo each line individually since echo adds a newline for us.
     */
    private function getCertInjectionCommand(string $certificateContents): string
    {
        $certificateLines = explode("\n", $certificateContents);

        // Old versions of windows have smaller limits on the length of a single command line. For windows 2000 that
        // limit is 2047, while xp is 8191. To conserve characters, our temp file is called 'c' (for certificate).
        $firstLine = array_shift($certificateLines);
        $certEcho = "echo $firstLine>c"; // First line overwrites the file with > instead of appending with >>

        foreach ($certificateLines as $line) {
            $certEcho .= " & echo $line>>c";
        }

        $certInjectionCommand = "($certEcho) && " .
            'move /y c ca.crt && ' .
            'net stop stc_raw_agent && ' .
            'net start stc_raw_agent';

        return $certInjectionCommand;
    }

    /**
     * Update the list of allowed commands using a hardcoded copy (signed by the old cert) that contains the cert injection command.
     * This is in case shadowsnap does not get an updated allowed commands list before we push out the new certificate.
     * Once that happens, shadowsnap won't be able to verify the new allowed commands list signature because shadowsnap is still
     * using the old cert.
     *
     * @todo After 2020/05/03 this code will stop working because the cert that signed this allowed commands list has expired.
     *   Remove this function after this date.
     */
    private function pushHardcodedAllowedCommandsList(): void
    {
        try {
            $allowedCommandsList = json_decode($this->filesystem->fileGetContents(self::ALLOWED_COMMANDS_LIST_WITH_CERT_COMMAND_FILENAME), true);
            if (!isset($allowedCommandsList['nonce'], $allowedCommandsList['whitelist'], $allowedCommandsList['__signature'])) {
                throw new \Exception('Saved list of allowed commands on disk is malformed');
            }

            $this->logger->info('SSP0003 Pushing list of allowed commands');
            $this->pushAllowedCommandsList($allowedCommandsList['nonce'], $allowedCommandsList['whitelist'], $allowedCommandsList['__signature']);
        } catch (Throwable $e) {
            $this->logger->error('SSP0008 Error pushing the hardcoded list of allowed commands', ['exception' => $e]);
            // Shadowsnap may already have a version of the allowed commands list that allows the cert injection command to succeed
            // or it may be an old version of shadowsnap that doesn't need an allowed commands list so we don't need to rethrow.
        }
    }

    /**
     * @param string $agentKeyName
     * @param string|null $storedSerialNumberForRepair Set this to the stored serial number for repair operations
     * @return array The pairing response containing 'code', 'dattoKey', 'message', and 'success' keys
     */
    private function performPairOperation(string $agentKeyName, string $storedSerialNumberForRepair = null): array
    {
        if (empty($agentKeyName)) {
            throw new RecoverablePairingFailureException("SSA0001 Required agent key name not provided for pairing");
        }

        $isRepair = isset($storedSerialNumberForRepair);

        $basicAgentInfo = $this->getBasicHost();
        if (!$basicAgentInfo) {
            throw new RecoverablePairingFailureException("SSA0002 Unable to retrieve basic host information");
        }
        $licenseType = $this->getLicenseTypeFromBasicHost($basicAgentInfo);
        $this->logger->info('SSP2009 Using license type', ['licenseType' => $licenseType]);

        // Serial number will be null after initial agent installation.
        $serialNumber = $basicAgentInfo['agentSerialNumber'] ?? '';
        $this->apiVersion = $basicAgentInfo['apiVersion'] ?? '';
        $agentState = $basicAgentInfo['agentState'] ?? '';
        $ticket = [];

        if ($isRepair) {
            $serialNumbersMatch = $serialNumber === $storedSerialNumberForRepair;
            if ($serialNumbersMatch) {
                $this->logger->debug("SSP2006 Stored serial number matches agent serial ($serialNumber)");
            } else {
                $this->logger->debug("SSP2007 Stored serial number $storedSerialNumberForRepair does not match agent serial $serialNumber");
            }
        }

        $isAgentPaired = $agentState === 'paired';

        if ($isRepair && !$isAgentPaired) {
            $this->logger->warning('SSP2008 Repair attempt on agent that reports it is not currently paired', ['agentState' => $agentState]);
            throw new AgentNotPairedException("Agent not paired");
        }

        $securePairing = $this->isNewApiVersion() && $isAgentPaired;
        $deviceID = $this->deviceConfig->getDeviceId();

        if (!$isRepair && $securePairing) {
            // The only way to tell if the agent thinks it's paired locally is to pair
            $response = $this->devicePair('garbage', $deviceID);
            if ($response['httpCode'] === 201) {
                $securePairing = false;
            } elseif (is_array(json_decode($response['response'], true))) {
                $challenge = json_decode($response['response'], true);
                if (!isset($challenge['deviceID'])) {
                    $this->logger->error('SSP2211 Invalid response format for agent pairing request', ['response' => $response]);
                    throw new RecoverablePairingFailureException("Invalid pairing response from agent");
                }
                $ticket = $this->getSecurePairingTicket($challenge['deviceID']);
            }
        }

        $deviceWebRegistrationResponse = $this->registerAgentWithDeviceWeb($agentKeyName, $licenseType, $serialNumber);
        $registerDattoKey = $deviceWebRegistrationResponse['dattoKey'] ?? '';
        $registerSerialNumber = $deviceWebRegistrationResponse['code'] ?? '';

        // We have all our secrets, time to register with StorageCraft
        $registerSuccess = $this->registerSerialNumberWithAgent($agentKeyName, $registerSerialNumber, $serialNumber);

        if ($registerSuccess) {
            $this->logger->info('SSP2003 Registration with StorageCraft succeeded', ['deviceKey' => $registerDattoKey]);
        } else {
            $this->logger->info('SSP2010 Using existing agent key', ['agentKey' => $serialNumber, 'deviceKey' => $registerDattoKey]);
        }

        // Pairing the agent to the device
        if (!$isRepair && $securePairing) {
            $ticket = array_merge(
                ['deviceID' => $registerDattoKey],
                $ticket
            );
            $agentPairResponse = $this->sendAgentPairTicket($ticket);
        } else {
            // Older agents, new agents not already paired, and agents being re-paired
            $agentPairResponse = $this->devicePair($registerDattoKey, $deviceID);
        }

        $pairSuccessful = $agentPairResponse['httpCode'] === 201;
        if (!$pairSuccessful) {
            $this->logger->error('SSP2005 Failed to pair', ['deviceKey' => $registerDattoKey, 'response' => $agentPairResponse]);
            throw new RecoverablePairingFailureException('Pairing request failed');
        }

        $this->key = $deviceWebRegistrationResponse;
        $this->setCredentials();

        // Return the response to the caller (so they can persist it if necessary)
        return $deviceWebRegistrationResponse;
    }

    /**
     * Pair the agent using ticket information
     *
     * @param array $ticket containing deviceIDHash and ticket info
     * @return array Containing information about the response
     */
    public function sendAgentPairTicket(array $ticket): array
    {
        return $this->agentRequest->post('agentpairticket', json_encode($ticket), false, false, true);
    }

    /**
     * Determine if the ShadowSnap agent version is supported
     *
     * We simply call out to /basichost, which will return the api (agent) version. Then
     * we will compare that against the minimum secured-pairing agent version.
     */
    public function isAgentVersionSupported(): bool
    {
        try {
            $basicHost = $this->getBasicHost();
            if (is_array($basicHost)) {
                $apiVersion = $basicHost['apiVersion'] ?? '0'; // Assume 0 if no apiVersion found
                return version_compare($apiVersion, self::NEW_API_VERSION) >= 0;
            }
            // Unable to determine API version, or it is not supported
            return false;
        } catch (\Throwable $e) {
            // A CURL error occurred, the message was previously logged
            return false;
        }
    }

    /**
     * Determine the licence type from the returned basic host info
     * @param array $basicHost
     * @return string
     */
    private function getLicenseTypeFromBasicHost(array $basicHost): string
    {
        $osInfo = explode('-', $basicHost['os'] ?? '');
        $osVersion = isset($osInfo[1]) ? $osInfo[1] : null;
        // FIXME: This should be updated to account for new Windows Server versions, but make sure to understand the impact on device-web, portal, billing, etc
        $useServerLicense = preg_match('/2003|2008/', $osVersion);
        return $useServerLicense ? self::LICENSE_TYPE_SERVER : self::LICENSE_TYPE_DESKTOP;
    }

    /**
     * Get the backup request parameters
     *
     * @param BackupApiContext $backupContext
     * @return array
     */
    private function getBackupRequestParams(BackupApiContext $backupContext): array
    {
        $writeSize = $backupContext->getWriteSize() !== 0 ? $backupContext->getWriteSize() : null;

        $requestParams = [
            'snapshotMethod' => $backupContext->getBackupEngineUsed(),
            'VSSExclusions' => $backupContext->getVssExclusions(),
            'waitBetweenVols' => false,
            'rollbackOnFailure' => $backupContext->isRollbackOnFailure(),
            'crashTestDummy' => false,
            'forceDiffMerge' => $backupContext->isForceDiffMerge(),
            'cacheWrites' => $backupContext->useCacheWrites(),
            'writeSize' => $writeSize,
            'volumes' => $this->getVolumeArray($backupContext)
        ];

        $this->addUsernameAndPasswordToVolumes($requestParams['volumes'], $backupContext->getOsVersion());
        return $requestParams;
    }

    /**
     * Attempt to send a request. If an SSL error is encountered, add the SSL certs and try again.
     *
     * @param string $requestType
     * @param string $action
     * @param mixed $data
     * @param bool $asJson
     * @return mixed
     */
    private function attemptRequestAndAddSslCertsIfNeeded(
        string $requestType,
        string $action,
        $data = [],
        bool $asJson = true
    ) {
        try {
            $response = $this->sendRequest($requestType, $action, $data, $asJson);
        } catch (AgentApiException $exception) {
            if ($exception->getCode() !== CURLE_SSL_CONNECT_ERROR) {
                throw $exception;
            }

            // include the ssl certs and try again
            $certificateSets = $this->certificateSetStore->getCertificateSets(self::SHADOWSNAP_CA_CERT_FILE);
            $this->agentRequest->includeCertificateSet($certificateSets);
            $response = $this->sendRequest($requestType, $action, $data, $asJson);
        }

        return $response;
    }

    /**
     * Send the request to the agent
     *
     * @param string $requestType
     * @param string $action
     * @param $data
     * @param bool $asJson
     * @return mixed
     */
    private function sendRequest(
        string $requestType,
        string $action,
        $data = [],
        bool $asJson = true
    ) {
        switch ($requestType) {
            case AgentRequest::GET:
                $response = $this->agentRequest->get($action, $data, $asJson);
                break;
            case AgentRequest::POST:
                $response = $this->agentRequest->post($action, $data, $asJson);
                break;
            case AgentRequest::DELETE:
                $response = $this->agentRequest->delete($action, $data, $asJson);
                break;
            default:
                throw new AgentApiException('Invalid request type');
        }

        return $response;
    }

    /**
     * Translate the volume information into the agent api request format.
     *
     * @param BackupApiContext $backupContext
     * @return array
     */
    private function getVolumeArray(BackupApiContext $backupContext): array
    {
        $backupTransport = $backupContext->getBackupTransport();
        $volumeParameters = $backupTransport->getVolumeParameters();

        $volumes = [];
        foreach ($volumeParameters as $guid => $parameters) {
            $imageSamba = $parameters['image'];

            if (isset($parameters['offset'])) {
                $offset = (string)$parameters['offset'];
            } else {
                $sectorSize = $this->getSectorSizeForVolume($backupContext, $guid);
                // todo: revisit this
                // The image files are created starting at sector 63.
                // For 4K volumes, that puts the start offset at 4096 * 63 which for some reason allows
                // ShadowSnap to work. The preferred sector offset is 2048.
                $offset = (string)($sectorSize * $backupContext->getBackupImageFile()->getBaseSectorOffset());
            }

            $volume = [
                "guid" => $guid,
                "image" => $imageSamba,
                "offset" => $offset
            ];
            if (isset($parameters['blockDevice'])) {
                $volume['blockDevice'] = $parameters['blockDevice'];
            }

            $volumes[] = $volume;
        }

        return $volumes;
    }

    /**
     * Return either the saved password credentials for the agent, or the default credentials
     *
     * @param string $action the endpoint determines which password is used
     * @return array
     */
    private function getCredentials(string $action = ''): array
    {
        if ($this->key && !in_array($action, self::ENDPOINTS_USING_DEFAULT_PASSWORD)) {
            $passwordString = sha1($this->key['code'] . $this->key['dattoKey'] . self::MAGIC_PASS_PHRASE_STRING);
        } else {
            $passwordString = self::CREDENTIALS_DEFAULT_PASSWORD;
        }

        $credentials['user'] = self::CREDENTIALS_USER;
        $credentials['pass'] = $passwordString;
        return $credentials;
    }

    /**
     * Process the response from the backup status request.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function processBackupStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        switch ($response['status']) {
            case "new":
            case "active":
                $this->processActiveStatus($backupJobStatus, $response);
                break;

            case "failed":
            case "aborted":
                $this->processFailedStatus($backupJobStatus, $response);
                break;

            case "complete":
                $this->processFinishedStatus($backupJobStatus, $response);
                break;

            case "rollback":
                $this->processRollbackStatus($backupJobStatus, $response);
                break;

            default:
                throw new AgentApiException('Invalid response from agent!');
        }
    }

    /**
     * Process the active status response from the backup status request.
     * Update the transfer state and transfer amounts.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function processActiveStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::ACTIVE());
        $this->updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::NONE());
    }

    /**
     * Process the failed status response from the backup status request.
     * Update the transfer state and set the transfer result.
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function processFailedStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::FAILED());
        $this->updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::FAILURE_UNKNOWN());
    }

    /**
     * Process the completed status response from the backup status request.
     * Update the transfer state and set the transfer result.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function processFinishedStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::COMPLETE());
        $this->updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::SUCCESS());
    }

    /**
     * Process the rollback status response from the backup status request.
     * Update the transfer state and set the transfer result.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function processRollbackStatus(BackupJobStatus $backupJobStatus, array $response): void
    {
        $backupJobStatus->setTransferState(AgentTransferState::ROLLBACK());
        $this->updateVolumeDetails($backupJobStatus, $response);
        $backupJobStatus->setTransferResult(AgentTransferResult::FAILURE_UNKNOWN());
    }

    /**
     * Parse the contents of the response and pull out the volume details.  Add them to the Backup Job Status.
     *
     * @param BackupJobStatus $backupJobStatus
     * @param array $response
     */
    private function updateVolumeDetails(BackupJobStatus $backupJobStatus, array $response): void
    {
        $sent = 0;
        $total = 0;
        $volumeGuids = [];

        if ($response['details'] && is_array($response['details'])) {
            foreach ($response['details'] as $volume) {
                $volumeGuid = $volume['volume'];
                $volumeGuids[] = $volumeGuid;
                $bytesSent = $volume['bytes_sent'] ?? 0;
                $bytesTotal = $volume['bytes_total'] ?? 0;
                $sent += $bytesSent;
                $total += $bytesTotal;
                $volumeUpdateTime = strtotime($volume['updated']);
                $volumeDetails = new BackupJobVolumeDetails(
                    $volumeUpdateTime,
                    $volume['status'],
                    $volumeGuid,
                    null,
                    null,
                    null,
                    $bytesTotal,
                    $bytesSent,
                    null,
                    null,
                    null
                );
                $backupJobStatus->setVolumeDetails($volumeDetails);
            }
        }

        $backupJobStatus->setVolumeGuids($volumeGuids);
        $backupJobStatus->updateAmountsSent($sent, $total);
    }

    /**
     * Add the username and password to the volumes
     *
     * @param array $volumes
     * @param string $osVersion
     */
    private function addUsernameAndPasswordToVolumes(array &$volumes, string $osVersion): void
    {
        /**
         * The following block of code was written to handle Windows 10
         * backups. When connecting to a samba share, we had to pass a
         * username and password. For some reason, passing a random
         * username and password works. Yeah...???
         */
        if (version_compare($osVersion, '10') >= 0) {
            foreach ($volumes as &$volume) {
                if (!isset($volume['username'])) {
                    $volume['username'] = 'datto100';
                    $volume['password'] = 'datto100';
                }
            }
        }
    }

    /**
     * Ensure the job ID is valid.
     *
     * @param string $jobId
     */
    private function validateJobId(string $jobId): void
    {
        if (strlen($jobId) !== 32) {
            throw new AgentApiException('Invalid job ID ' . $jobId, AgentApiException::INVALID_JOB_ID);
        }
    }

    /**
     * Get the sector size for a given volume
     *
     * @param BackupApiContext $backupContext
     * @param string $volumeGuid
     * @return int
     */
    private function getSectorSizeForVolume(BackupApiContext $backupContext, string $volumeGuid): int
    {
        $sectorSize = BackupImageFile::SECTOR_SIZE_IN_BYTES;
        foreach ($backupContext->getVolumes() as $volume) {
            if ($volume->getGuid() === $volumeGuid) {
                $sectorSize = $volume->getSectorSize();
                break;
            }
        }
        return $sectorSize;
    }

    /**
     * Push out list of allowed commands
     *
     * @param string $nonce
     * @param string $allowedCommandsList
     * @param string $signature
     * @return bool|int|array|string
     */
    private function pushAllowedCommandsList(string $nonce, string $allowedCommandsList, string $signature)
    {
        if (empty($nonce) || empty($allowedCommandsList) || empty($signature)) {
            return false;
        }

        $data = json_encode([
            'nonce' => $nonce,
            'whitelist' => $allowedCommandsList,
            '__signature' => $signature
        ]);
        return $this->agentRequest->post('whitelist', $data, false);
    }

    /**
     * @param string $agentKeyName
     * @param string $licenseType
     * @param string $serialNumber
     * @return array
     */
    private function registerAgentWithDeviceWeb(string $agentKeyName, string $licenseType, string $serialNumber): array
    {
        $this->logger->info('SSP2000 Requesting shadowSnapKeys from Datto server');

        $response = $this->cloudClient->queryWithId(
            'v1/device/asset/agent/shadowSnap/registration/register',
            [
                'agent' => $agentKeyName,
                'licenseType' => $licenseType,
                'serialNumber' => $serialNumber
            ]
        );

        $registerSuccess = $response['success'] ?? 0;
        $registerMessage = $response['message'] ?? 'Invalid response format';

        if (!$registerSuccess) {
            $this->logger->error('SSP2002 Registering agent with device web did not succeed', ['responseMessage' => $registerMessage]);
            $value = str_replace("\n", '', $registerMessage);
            throw new RecoverablePairingFailureException("Call to register ShadowSnap agent failed - [ $value ]");
        }

        $this->logger->info('SSP2001 Registering agent with device web succeeded', ['responseMessage' => $registerMessage]);

        return $response;
    }

    /**
     * @param string $agentKeyName
     * @param string $registerSerialNumber
     * @param string $previousSerialNumber
     * @return bool True if registration was successful, false if it failed
     */
    private function registerSerialNumberWithAgent(
        string $agentKeyName,
        string $registerSerialNumber,
        string $previousSerialNumber
    ): bool {
        $registerResponse = $this->registerMsp($registerSerialNumber, 'Datto Inc', $agentKeyName);
        $registerSuccess = $registerResponse['httpCode'] === 201;

        $agentSerialRegistered = $registerSuccess || $previousSerialNumber;
        if (!$agentSerialRegistered) {
            $this->logger->error('SSP2004 Registration with StorageCraft failed', ['registerResponse' => $registerResponse]);
            throw new RecoverablePairingFailureException('Registration with StorageCraft failed');
        }

        return $registerSuccess;
    }

    /**
     * Register the agent
     *
     * @param string $serialNumber
     * @param string $customerName
     * @param string $computerName
     * @return array Contains information about the response, including httpCode and response body
     */
    private function registerMsp(string $serialNumber, string $customerName, string $computerName = ''): array
    {
        $data = json_encode([
            'serial_number' => $serialNumber,
            'user_name' => 'Datto',
            'customer' => $customerName,
            'computer' => $computerName
        ]);

        return $this->agentRequest->post('register', $data, false, false, true);
    }

    /**
     * Pair the agent using the deviceID
     *
     * @param string $deviceIDHash
     * @param string $deviceID
     * @return array Contains information about the response, including httpCode and response body
     */
    private function devicePair(string $deviceIDHash, string $deviceID): array
    {
        $data = json_encode([
            'deviceID' => $deviceIDHash,
            'rawDeviceID' => $deviceID
        ]);

        return $this->agentRequest->post('pair', $data, false, false, true);
    }

    /**
     * Gets the signed pairing ticket from device web to be used for secure pairing
     * @param int $oldDeviceID
     * @return array
     */
    private function getSecurePairingTicket(int $oldDeviceID): array
    {
        $this->logger->info('SSP2210 Agent already paired, requesting pairing ticket');
        $response = $this->cloudClient->queryWithId(
            'v1/device/asset/agent/pair',
            [
                'oldDeviceId' => $oldDeviceID
            ]
        );

        if (isset($response["success"]) && !$response["success"]) {
            $message = "Add Agent failed: pairing not allowed to this device";
            throw new PairingDeniedException($message);
        } elseif (!isset($response["success"]) || empty($response['ticket']) || !is_array($response['ticket'])) {
            $message = "Add Agent failed: pairing validation could not be completed";
            throw new PairingDeniedException($message);
        }

        return $response["ticket"];
    }
}
