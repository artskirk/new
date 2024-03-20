<?php

namespace Datto\App\Console\Command\Agent\Volumes;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Serializer\AgentSerializer;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VolumesCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:volumes';

    const DEFAULT_VOLUME_FIELDS = [
        'guid',
        'included',
        'mountpoints',
        'spaceTotal',
        'spaceUsed',
        'OSVolume',
        'realPartScheme',
        'volumeType'
    ];

    /** @var agentSerializer */
    private $agentSerializer;

    public function __construct(
        AgentService $agentService,
        AgentSerializer $agentSerializer
    ) {
        parent::__construct($agentService);

        $this->agentSerializer = $agentSerializer;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENTS];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('View agent volumes information.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the volumes list in json format.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent for which to list volumes for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List volumes for all agents.')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Field(s) to output in table output (may be repeated).');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        if ($json) {
            $this->renderVolumesJson($input, $output);
        } else {
            $this->renderVolumesTable($input, $output);
        }
        return 0;
    }

    private function renderVolumesJson(InputInterface $input, OutputInterface $output): void
    {
        $result = [];
        foreach ($this->getAgents($input) as $agent) {
            $agentData = $this->agentSerializer->serialize($agent);
            $volumeData = $agentData['volumes'];
            $result[$agent->getKeyName()] = $volumeData;
        }

        $output->writeln(json_encode($result));
    }

    private function renderVolumesTable(InputInterface $input, OutputInterface $output): void
    {
        $includedHeaders = self::DEFAULT_VOLUME_FIELDS;
        $selectedHeaders = $input->getOption('output');
        if (count($selectedHeaders) > 0) {
            $includedHeaders = $selectedHeaders;
        }

        foreach ($this->getAgents($input) as $agent) {
            $volumeData = $agent->getVolumes()->toArray();
            $table = new Table($output);

            if (count($volumeData) > 0) {
                $headers = array_keys(array_intersect_key(
                    $volumeData[array_key_first($volumeData)],
                    array_flip($includedHeaders)
                ));
                $title = 'Agent ' . $agent->getKeyName();
                $titleCell = new TableCell($title, ['colspan' => count($headers)]);
                $table->setHeaders([
                    [$titleCell], $headers
                ]);

                foreach ($volumeData as $volumeValues) {
                    $rowValues = [];
                    foreach ($headers as $key) {
                        $rowValues[$key] = $volumeValues[$key];
                    }

                    $table->addRow($rowValues);
                }

                $table->render();
            } else {
                $output->writeln('Agent ' . $agent->getKeyName() . ' has not reported volume information yet.');
            }
        }
    }
}
