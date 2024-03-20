<?php

namespace Datto\App\Console\Command\Share;

use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\Zfs\ZfsShare;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShareListCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:list';

    protected function configure()
    {
        $this
            ->setDescription('List existing shares in a table')
            ->addOption('show-mount', null, InputOption::VALUE_NONE, 'Optionally display where the share is mounted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $showMount = $input->getOption('show-mount');
        $shares = $this->shareService->getAll();

        $headers = [
            'Name',
            'Key',
            'UUID',
            'Type',
            'Access Level',
            'Write Level',
            'AFP',
            'NFS',
            'CHAP'
        ];

        if ($input->getOption('show-mount')) {
            $headers[] = 'Mount';
        }

        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($shares as $share) {
            if ($share instanceof NasShare) {
                $accessLevel = $share->getAccess()->getLevel();
                $writeLevel = $share->getAccess()->getWriteLevel();
                $afpEnabled = ($share->getAfp()->isEnabled()) ? 'on' : 'off';
                $nfsEnabled = ($share->getNfs()->isEnabled()) ? 'on' : 'off';
                $chapEnabled = 'n/a';
            } elseif ($share instanceof IscsiShare) {
                $accessLevel = 'n/a';
                $writeLevel = 'n/a';
                $afpEnabled = 'n/a';
                $nfsEnabled = 'n/a';
                $chapEnabled = ($share->getChap()->getAuthentication() !== ChapSettings::CHAP_DISABLED) ? 'on' : 'off';
            } elseif ($share instanceof ExternalNasShare) {
                $accessLevel = 'n/a';
                $writeLevel = 'n/a';
                $afpEnabled = 'n/a';
                $nfsEnabled = 'n/a';
                $chapEnabled = 'n/a';
            } elseif ($share instanceof ZfsShare) {
                $accessLevel = $share->getAccess()->getLevel();
                $writeLevel = $share->getAccess()->getWriteLevel();
                $afpEnabled = $share->getAfp()->isEnabled() ? 'on' : 'off';
                $nfsEnabled = $share->getNfs()->isEnabled() ? 'on' : 'off';
                $chapEnabled = 'n/a';
            } else {
                continue;
            }

            $row = [
                $share->getName(),
                $share->getKeyName(),
                $share->getUuid(),
                $share->getType(),
                $accessLevel,
                $writeLevel,
                $afpEnabled,
                $nfsEnabled,
                $chapEnabled
            ];

            if ($showMount) {
                try {
                    $row[] = $share->getMountPath() ?: '-';
                } catch (\Throwable $e) {
                    $row[] = 'unknown';
                }
            }

            $table->addRow($row);
        }

        $table->render();
        return 0;
    }
}
