<?php

namespace Datto\App\Console\Command\System\Migrate;

use Datto\Config\LocalConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate the "/home/_config" directory to a symbolic link instead of a
 * mirrored directory.  This does nothing if "/home/_config" is already a
 * symbolic link.  This deletes the contents of the old "/home/_config"
 * directory.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class ConfigCommand extends Command
{
    protected static $defaultName = 'system:migrate:config';

    /** @var LocalConfig */
    private $localConfig;

    public function __construct(
        LocalConfig $localConfig
    ) {
        parent::__construct();

        $this->localConfig = $localConfig;
    }

    protected function configure()
    {
        $this
            ->setDescription('Migrate the "/home/_config" directory to a symbolic link.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln($this->localConfig->migrate());
            return 0;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            return 1;
        }
    }
}
