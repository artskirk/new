<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\Agent;
use Datto\App\Console\Command\AssetFormatter;
use Datto\Asset\Agent\AgentService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AgentShowCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:show';

    /** @var AssetFormatter */
    private $formatter;

    public function __construct(
        AssetFormatter $formatter,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->formatter = $formatter;
    }

    protected function configure()
    {
        $this
            ->setDescription('Show given agents')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to show')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all agents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        foreach ($agents as $agent) {
            $table = new Table($output);
            $table->setStyle('borderless');

            $this
                ->addBasics($table, $agent)
                ->addOperatingSystem($table, $agent)
                ->addAgentSoftware($table, $agent)
                ->addHardware($table, $agent)
                ->addVolumes($table, $agent)
                ->addLocal($table, $agent)
                ->addOffsite($table, $agent);

            $table->render();
        }
        return 0;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addVolumes(Table $table, Agent $agent)
    {
        $table
            ->addRow(array(new TableCell('Volumes', array('colspan' => 2))));

        foreach ($agent->getVolumes() as $volume) {
            if ($volume->getLabel() && $volume->getMountpoint()) {
                $description = sprintf('%s (%s)', $volume->getMountpoint(), $volume->getLabel());
            } elseif ($volume->getLabel() && !$volume->getMountpoint()) {
                $description = $volume->getLabel();
            } elseif (!$volume->getLabel() && $volume->getMountpoint()) {
                $description = $volume->getMountpoint();
            } else {
                $description = $volume->getGuid();
            }

            $table
                ->addRow(array(new TableCell(sprintf('- %s', $description), array('colspan' => 2))))
                ->addRow(array('  + GUID', $volume->getGuid()))
                ->addRow(array('  + Serial number', $volume->getSerialNumber()))
                ->addRow(array('  + Mountpoint(s)', implode(', ', $volume->getMountpointsArray())))
                ->addRow(array('  + Label', $volume->getLabel()))
                ->addRow(array('  + Volume type', $volume->getVolumeType()))
                ->addRow(array('  + Filesystem', $volume->getFilesystem()))
                ->addRow(array('  + Partition scheme', $volume->getPartScheme()))
                ->addRow(array('  + Partition scheme (real)', $volume->getRealPartScheme()))
                ->addRow(array('  + Hidden sectors', $volume->getHiddenSectors()))
                ->addRow(array('  + Sector size', sprintf('%d bytes', $volume->getSectorSize())))
                ->addRow(array('  + Cluster size', sprintf('%d bytes', $volume->getClusterSize())))
                ->addRow(array('  + Space total', $this->formatter->formatBytes($volume->getSpaceTotal())))
                ->addRow(array('  + Space used', $this->formatter->formatBytes($volume->getSpaceUsed())))
                ->addRow(array('  + Space free', $this->formatter->formatBytes($volume->getSpaceFree())));
        }

        $table
            ->addRow(array('', ''));

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addBasics(Table $table, Agent $agent)
    {
        $table
            ->addRow(array('Key Name', $agent->getKeyName()))
            ->addRow(array('Hostname', $agent->getHostname()))
            ->addRow(array('Paired By', $agent->getFullyQualifiedDomainName()))
            ->addRow(array('UUID', $agent->getUuid()))
            ->addRow(array('ZFS Path', $agent->getDataset()->getZfsPath()))
            // Todo: Modify legacy code to populate 'generate' field for Linux agents, and move it to Agent class
            //->addRow(array('Date added', date('r', $agent->getGenerated())))
            ->addRow(array('', ''));

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addOperatingSystem(Table $table, Agent $agent)
    {
        $table
            ->addRow(array(new TableCell('Operating system', array('colspan' => 2))))
            ->addRow(array('- Name', sprintf('%s (%d bits)', $agent->getOperatingSystem()->getName(), $agent->getOperatingSystem()->getBits())))
            ->addRow(array('- Version', $agent->getOperatingSystem()->getVersion()))
            ->addRow(array('- Architecture', $agent->getOperatingSystem()->getArchitecture()))
            ->addRow(array('- Service Pack', $agent->getOperatingSystem()->getServicePack()))
            ->addRow(array('', ''));

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addAgentSoftware(Table $table, Agent $agent)
    {
        $table
            ->addRow(array(new TableCell('Agent software', array('colspan' => 2))))
            ->addRow(array('- Version', $agent->getDriver()->getAgentVersion()))
            ->addRow(array('- Serial', $agent->getDriver()->getSerialNumber()))
            ->addRow(array('', ''));

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addHardware(Table $table, Agent $agent)
    {
        $table
            ->addRow(array(new TableCell('Hardware', array('colspan' => 2))))
            ->addRow(array('- CPUs', $agent->getCpuCount()))
            ->addRow(array('- Memory', sprintf('%2.2f GB (%d bytes)', $agent->getMemory() / 1024 / 1024 / 1024, $agent->getMemory())))
            ->addRow(array('', ''));

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addLocal(Table $table, Agent $agent)
    {
        if (!$agent->getOriginDevice()->isReplicated()) {
            $table
                ->addRow(array(new TableCell('Local Settings', array('colspan' => 2))))
                ->addRow(array('- Paused', $this->formatter->formatBool($agent->getLocal()->isPaused())))
                ->addRow(array('- Backup Interval', $agent->getLocal()->getInterval()))
                ->addRow(array(new TableCell('- Retention', array('colspan' => 2))))
                ->addRow(array('  + Daily', $agent->getLocal()->getRetention()->getDaily()))
                ->addRow(array('  + Weekly', $agent->getLocal()->getRetention()->getWeekly()))
                ->addRow(array('  + Monthly', $agent->getLocal()->getRetention()->getMonthly()))
                ->addRow(array('  + Maximum', $agent->getLocal()->getRetention()->getMaximum()))
                ->addRow(array('- Backup Schedule', $this->formatter->formatSchedule($agent->getLocal()->getSchedule())))
                ->addRow(array('', ''));
        }

        return $this;
    }

    /**
     * @param Table $table
     * @param Agent $agent
     * @return $this
     */
    private function addOffsite(Table $table, Agent $agent)
    {
        if (!$agent->getOriginDevice()->isReplicated()) {
            $table
                ->addRow(array(new TableCell('Offsite Settings', array('colspan' => 2))))
                ->addRow(array('- Agent Priority', $agent->getOffsite()->getPriority()))
                ->addRow(array('- Replication Interval', $agent->getOffsite()->getReplication()))
                ->addRow(array(new TableCell('- Retention', array('colspan' => 2))))
                ->addRow(array('  + Daily', $agent->getOffsite()->getRetention()->getDaily()))
                ->addRow(array('  + Weekly', $agent->getOffsite()->getRetention()->getWeekly()))
                ->addRow(array('  + Monthly', $agent->getOffsite()->getRetention()->getMonthly()))
                ->addRow(array('  + Maximum', $agent->getOffsite()->getRetention()->getMaximum()))
                ->addRow(array('  + Limit (On Demand)', $agent->getOffsite()->getOnDemandRetentionLimit()))
                ->addRow(array('  + Limit (Nightly)', $agent->getOffsite()->getNightlyRetentionLimit()))
                ->addRow(array('- Offsite Schedule', $this->formatter->formatSchedule($agent->getOffsite()->getSchedule())))
                ->addRow(array('', ''));
        }

        return $this;
    }
}
