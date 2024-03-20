<?php

namespace Datto\App\Console\Command\Security\Suricata;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Security\Suricata;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to configure Suricata. Suricata is the IDS used for devices hosted in Azure.
 * This will be run on boot if the feature is enabled via systemd.
 *
 * @author Huan-Yu Yih <hyih@datto.com>
 */
class ConfigureCommand extends AbstractCommand
{
    protected static $defaultName = 'security:suricata:configure';

    /** @var Suricata */
    private $suricata;

    public function __construct(Suricata $suricata)
    {
        parent::__construct();

        $this->suricata = $suricata;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SURICATA
        ];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->suricata->configure();

        return 0;
    }
}
