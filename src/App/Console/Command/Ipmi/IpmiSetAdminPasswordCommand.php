<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Datto\Ipmi\IpmiService;
use Datto\Common\Utility\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for setting admin ipmi password
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class IpmiSetAdminPasswordCommand extends AbstractCommand
{
    protected static $defaultName = 'ipmi:admin:password:set';

    private IpmiService $ipmiService;
    private Filesystem $filesystem;

    public function __construct(
        IpmiService $ipmiService,
        Filesystem $filesystem
    ) {
        parent::__construct();

        $this->ipmiService = $ipmiService;
        $this->filesystem = $filesystem;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_IPMI_ROTATE_ADMIN_PASSWORD
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Sets IPMI Password for admin user')
            ->addArgument('path', InputArgument::REQUIRED, 'Full path to the secret file where password was saved');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!$this->filesystem->exists($path)) {
            throw new InvalidArgumentException('The given path does not exist');
        }
        $this->ipmiService->setAdminPasswordViaFile($path);
        return 0;
    }
}
