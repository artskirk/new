<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Audit\AgentTypeAuditor;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send a request to an agent via its API.
 *
 * @author Andrew Cope <acope@datto.com>
 */
class AgentRequestCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:request';

    /** @var AgentTypeAuditor */
    private $agentTypeAuditor;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    public function __construct(
        AgentService $agentService,
        AgentTypeAuditor $agentTypeAuditor,
        AgentApiFactory $agentApiFactory
    ) {
        parent::__construct($agentService);

        $this->agentTypeAuditor = $agentTypeAuditor;
        $this->agentApiFactory = $agentApiFactory;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Communicate with agent API')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent to communicate with')
            ->addArgument('endpoint', InputArgument::REQUIRED, 'The endpoint to hit')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Pretty print the response')
            ->addOption('post', null, InputOption::VALUE_NONE, 'The request is a POST request')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'The request is a DELETE request')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'The JSON-formatted field data')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'The number of log lines to return (for "event")')
            ->addOption('severity', null, InputOption::VALUE_REQUIRED, 'The severity of log lines to return (for "event")');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeyName = $input->getArgument('agent');
        $endpoint = $input->getArgument('endpoint');
        $lines = $input->getOption('lines') ?? AgentApi::DEFAULT_LOG_LINES;
        $severity = $input->getOption('severity') ?? AgentApi::DEFAULT_LOG_SEVERITY;
        $isPost = $input->getOption('post');
        $isDelete = $input->getOption('delete');
        $data = $input->getOption('data') ?? '';
        $dataArray = json_decode($data, true) ?? [];
        $verboseOutput = $output->isVerbose();
        $prettyPrint = $input->getOption('pretty');

        if ($isPost && $isDelete) {
            throw new Exception('Either a POST or DELETE call must be made');
        }

        if ($isPost && !$data) {
            throw new Exception('POST request requires data');
        }

        $agent = $this->agentService->get($agentKeyName);
        if ($agent->getPlatform()->isAgentless()) {
            throw new Exception('Agentless systems are not supported');
        }

        if ($agent->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents are not supported');
        }

        $agentApi = $this->agentApiFactory->createFromAgent($agent);
        $agentApi->initialize();

        // For ShadowSnapAgentApi, we need to set the credentials based on the endpoint
        if ($agentApi instanceof ShadowSnapAgentApi) {
            $agentApi->setCredentials($endpoint);
        }
        $agentRequest = $agentApi->getAgentRequest();

        if ($endpoint === 'event') {
            $dataArray = ['lines' => $lines, 'severity' => $severity];
        }

        if ($isPost) {
            $response = $agentRequest->post($endpoint, $data, $prettyPrint, $verboseOutput);
        } elseif ($isDelete) {
            $response = $agentRequest->delete($endpoint, $data, $prettyPrint, $verboseOutput);
        } else {
            $response = $agentRequest->get($endpoint, $dataArray, $prettyPrint, $verboseOutput);
        }

        if ($prettyPrint && is_array($response)) {
            $output->writeln(json_encode($response, JSON_PRETTY_PRINT));
        } else {
            $output->writeln($response);
        }
        return 0;
    }
}
