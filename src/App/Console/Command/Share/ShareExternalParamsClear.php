<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShareExternalParamsClear extends AbstractShareCommand
{
    protected static $defaultName = 'share:external:params:clear';

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARES,
            FeatureService::FEATURE_SHARES_EXTERNAL
        ];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clear saved authentication parameters for share(s)')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Apply to all');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateShare($input);
        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            if ($share instanceof ExternalNasShare) {
                $share->setSmbVersion(null);
                $share->setNtlmAuthentication(null);
                
                $this->shareService->save($share);
            }
        }

        return 0;
    }
}
