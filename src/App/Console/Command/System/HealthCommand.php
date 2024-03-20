<?php

namespace Datto\App\Console\Command\System;

use Datto\System\Health;
use Datto\System\HealthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HealthCommand extends Command
{
    const COLOR_MAP = [
        Health::SCORE_READABLE_OK => 'info',
        Health::SCORE_READABLE_DEGRADED => 'comment',
        Health::SCORE_READABLE_DOWN => 'error'
    ];

    const STATE_RENDER_FORMAT = '<%s>%s</%1$s>';

    protected static $defaultName = 'system:health';

    /** @var HealthService */
    private $healthService;

    public function __construct(
        HealthService $healthService
    ) {
        parent::__construct();

        $this->healthService = $healthService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Outputs health of various system components.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output in json format.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $health = $this->healthService->calculateHealthScores();

        if ($json) {
            $this->renderJson($output, $health);
        } else {
            $this->renderTable($output, $health);
        }
        return 0;
    }

    private function renderTable(OutputInterface $output, Health $health): void
    {
        $table = new Table($output);
        $headers = [
            'Component',
            'Health State'
        ];
        $table->setHeaders($headers);

        foreach ($health->jsonSerialize() as $component => $state) {
            $table->addRow([
                $component,
                sprintf(self::STATE_RENDER_FORMAT, self::COLOR_MAP[$state], $state)
            ]);
        }

        $table->render();
    }

    private function renderJson(OutputInterface $output, Health $health): void
    {
        $output->write(json_encode($health->jsonSerialize()));
    }
}
