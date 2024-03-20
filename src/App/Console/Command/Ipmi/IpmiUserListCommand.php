<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Feature\FeatureService;
use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for listing IPMI users
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class IpmiUserListCommand extends Command
{
    protected static $defaultName = 'ipmi:user:list';

    private IpmiService $ipmiService;
    private FeatureService $featureService;

    public function __construct(
        IpmiService $ipmiService,
        FeatureService $featureService
    ) {
        parent::__construct();

        $this->ipmiService = $ipmiService;
        $this->featureService = $featureService;
    }

    protected function configure()
    {
        $this->setDescription('Returns a list of IPMI users on device');
        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output the user list in json format.'
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) : int {
        $isJson =  $input->getOption('json');
        $users = [];

        if ($this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            $users = $this->ipmiService->getUsers(true);
        }

        if ($isJson) {
            $output->writeln(json_encode($users));
        } else {
            $this->renderHuman($output, $users);
        }

        return 0;
    }

    private function renderHuman(OutputInterface $output, array $users): void
    {
        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'Name',
            'Callin',
            'Link Auth',
            'IPMI Msg',
            'Channel Priv Limit'
        ]);

        foreach ($users as $userId => $user) {
            $table->addRow([
                $userId,
                $user[IpmiService::USER_NAME],
                json_encode($user[IpmiService::USER_CAN_CALLIN]),
                json_encode($user[IpmiService::USER_CAN_LINK_AUTH]),
                json_encode($user[IpmiService::USER_CAN_IPMI_MSG]),
                $user[IpmiService::USER_CHANNEL_PRIV_LIMIT]
            ]);
        }

        $table->render();
    }
}
