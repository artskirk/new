<?php

namespace Datto\App\Console\Command\Diagnostics;

use Datto\Diagnostics\DiagnosticsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command that lists all encrypted agents and matches corresponding loops / devmappers.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class EncryptionListCommand extends Command
{
    protected static $defaultName = 'diagnostics:encryptionlist';

    const NO_MAPPERS_FOUND = 'No DevMappers found';
    const HEADER_PARAMETER = 'Parameter';
    const HEADER_VALUE = 'Value';
    const LABEL_DEVMAPPER = 'DevMapper';
    const LABEL_LOOP = 'Loop';
    const LABEL_MOUNT = 'Mount';
    const LABEL_DETTO = '.detto';
    const LABEL_DETTO_FOR = '.detto for';
    const NONE = 'None';

    /** @var DiagnosticsService $diagnosticsService */
    private $diagnosticsService;

    public function __construct(
        DiagnosticsService $diagnosticsService
    ) {
        parent::__construct();

        $this->diagnosticsService = $diagnosticsService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Lists all encrypted agents and matches corresponding loops / devmappers.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $encryptionList = $this->diagnosticsService->getEncryptionList();
        $output->writeln("\n\n");
        if (empty($encryptionList)) {
            $output->writeln(self::NO_MAPPERS_FOUND);
        } else {
            $headers = array(self::HEADER_PARAMETER, self::HEADER_VALUE);
            $rows = array();
            foreach ($encryptionList as $value) {
                array_push($rows, array(self::LABEL_DEVMAPPER, $value->getDevMapper()));
                $this->writeOut(self::LABEL_LOOP, $value->getLoop(), $rows);
                $this->writeDettoFile($value->getDettoFile(), $rows);
                $this->writeOut(self::LABEL_MOUNT, $value->getMountPoint(), $rows);
                array_push($rows, array("", ""));
            }
            $io = new SymfonyStyle($input, $output);
            $io->table($headers, $rows);
        }
        return 0;
    }

    /**
     * Format and write the output values for loops and mounts
     *
     * @param string $label The label for the output line
     * @param string[] $array The array to get the values from
     * @param  array $rows rows to be output for this DevMapper
     */
    private function writeOut(string $label, array $array, array &$rows): void
    {
        if (empty($array)) {
            array_push($rows, array($label, self::NONE));
        } else {
            foreach ($array as $value) {
                array_push($rows, array($label, $value));
            }
        }
    }

    /**
     * Format and write the output values for .detto files
     *
     * @param string[] $array The array to get the values from
     * @param  array $rows rows to be output for this DevMapper
     */
    private function writeDettoFile(array $array, array &$rows): void
    {
        if (empty($array)) {
            array_push($rows, array(self::LABEL_DETTO, self::NONE));
        } else {
            foreach ($array as $loop => $dettoFile) {
                array_push($rows, array(self::LABEL_DETTO_FOR . " $loop", $dettoFile));
            }
        }
    }
}
