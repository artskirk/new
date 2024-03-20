<?php
namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\ImageExport\BootType;
use Datto\Restore\AgentHir;
use Datto\Restore\HIR\MachineType as HirMachineType;
use Datto\Common\Utility\Filesystem;
use Eloquent\Enumeration\Exception\UndefinedMemberException;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AgentHirCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:hir';

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Filesystem $filesystem,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Executes HIR on the given directory.')
            ->addArgument('dir', InputArgument::REQUIRED, 'Directory to run HIR on (ex. path to ZFS clone)')
            ->addOption('machine-type', 'm', InputOption::VALUE_REQUIRED, 'Target machine type (generic, virtual, hyperv, xen)', 'virtual')
            ->addOption('boot-type', 'b', InputOption::VALUE_REQUIRED, 'Target boot type (auto, bios, uefi)', 'bios');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Sanity check directory
        $datasetDir = $input->getArgument('dir');
        if (!$this->filesystem->isDir($datasetDir)) {
            throw new InvalidArgumentException('The given path is not a directory.');
        }

        // Get machine type
        try {
            $machineType = HirMachineType::memberByKey(
                strtoupper($input->getOption('machine-type'))
            );
        } catch (UndefinedMemberException $ex) {
            throw new InvalidArgumentException('Invalid machine type.');
        }

        // Get boot type
        try {
            $bootType = BootType::memberByKey(
                strtoupper($input->getOption('boot-type'))
            );
        } catch (UndefinedMemberException $ex) {
            throw new InvalidArgumentException('Invalid boot type.');
        }

        // Find the agentInfo file and deduce the asset key from it
        $agentInfoPaths = $this->filesystem->glob($datasetDir . '/*.agentInfo');
        $agentInfoPathsCount = count($agentInfoPaths);
        if ($agentInfoPathsCount > 1) {
            throw new Exception('More than one agentInfo was found inside of the dataset.');
        } elseif ($agentInfoPathsCount <= 0) {
            throw new Exception('Failed to locate agentInfo.');
        }
        $assetKey = pathinfo($agentInfoPaths[0], PATHINFO_FILENAME);

        if ($this->agentService->exists($assetKey)) {
            $agent = $this->agentService->get($assetKey);
            if (!$agent->isSupportedOperatingSystem()) {
                throw new Exception('This agent has an unsupported operating system, HIR will not work.');
            }
        }
        // Do it! :)
        $agentHir = new AgentHir($assetKey, $datasetDir, null, $machineType, $bootType);
        $agentHir->execute();
        return 0;
    }
}
