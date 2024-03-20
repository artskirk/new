<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\AssetFormatter;
use Datto\Asset\Share\ShareService;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShareShowCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:show';

    /** @var AssetFormatter */
    private $formatter;

    public function __construct(
        AssetFormatter $formatter,
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->formatter = $formatter;
    }

    protected function configure()
    {
        $this
            ->setDescription('Show given shares')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to show.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all configured shares.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            $table = new Table($output);
            $table->setStyle('borderless');

            $this
                ->addBasics($table, $share)
                ->addLocal($table, $share)
                ->addOffsite($table, $share)
                ->addAccess($table, $share)
                ->addUsers($table, $share)
                ->addChap($table, $share)
                ->addOther($table, $share)
                ->addReporting($table, $share);

            $table->render();
        }
        return 0;
    }

    private function addBasics(Table $table, Share $share): self
    {
        $table
            ->addRow(array('KeyName', $share->getKeyName()))
            ->addRow(array('Name', $share->getName()))
            ->addRow(array('Type', strtoupper($share->getType())))
            ->addRow(array('', ''));

        return $this;
    }

    private function addLocal(Table $table, Share $share): self
    {
        $table
            ->addRow(array(new TableCell('Local Settings', array('colspan' => 2))))
            ->addRow(array('- Paused', $this->formatter->formatBool($share->getLocal()->isPaused())))
            ->addRow(array('- Backup Interval', $share->getLocal()->getInterval()))
            ->addRow(array(new TableCell('- Retention', array('colspan' => 2))))
            ->addRow(array('  + Daily', $share->getLocal()->getRetention()->getDaily()))
            ->addRow(array('  + Weekly', $share->getLocal()->getRetention()->getWeekly()))
            ->addRow(array('  + Monthly', $share->getLocal()->getRetention()->getMonthly()))
            ->addRow(array('  + Maximum', $share->getLocal()->getRetention()->getMaximum()))
            ->addRow(array('- Backup Schedule', $this->formatter->formatSchedule($share->getLocal()->getSchedule())))
            ->addRow(array('', ''));

        return $this;
    }

    private function addOffsite(Table $table, Share $share): self
    {
        $table
            ->addRow(array(new TableCell('Offsite Settings', array('colspan' => 2))))
            ->addRow(array('- Agent Priority', $share->getOffsite()->getPriority()))
            ->addRow(array('- Replication Interval', $share->getOffsite()->getReplication()))
            ->addRow(array(new TableCell('- Retention', array('colspan' => 2))))
            ->addRow(array('  + Daily', $share->getOffsite()->getRetention()->getDaily()))
            ->addRow(array('  + Weekly', $share->getOffsite()->getRetention()->getWeekly()))
            ->addRow(array('  + Monthly', $share->getOffsite()->getRetention()->getMonthly()))
            ->addRow(array('  + Maximum', $share->getOffsite()->getRetention()->getMaximum()))
            ->addRow(array('  + Limit (On Demand)', $share->getOffsite()->getOnDemandRetentionLimit()))
            ->addRow(array('  + Limit (Nightly)', $share->getOffsite()->getNightlyRetentionLimit()))
            ->addRow(array('- Offsite Schedule', $this->formatter->formatSchedule($share->getOffsite()->getSchedule())))
            ->addRow(array('', ''));

        return $this;
    }


    private function addAccess(Table $table, Share $share): self
    {
        if ($share instanceof NasShare) {
            $table
                ->addRow(array(new TableCell('Access', array('colspan' => 2))))
                ->addRow(array('- Level', $share->getAccess()->getLevel()))
                ->addRow(array('- Write Level', $share->getAccess()->getWriteLevel()))
                ->addRow(array('', ''));
        }

        return $this;
    }

    private function addUsers(Table $table, Share $share): self
    {
        if ($share instanceof NasShare) {
            $table
                ->addRow(array(new TableCell('Users and Groups', array('colspan' => 2))))
                ->addRow(array('- All Users', join(", ", $share->getUsers()->getAll())))
                ->addRow(array('- Admin Users', join(", ", $share->getUsers()->getAdminUsers())))
                ->addRow(array('- Authorized Restore User',  $share->getAccess()->getAuthorizedUser()))
                ->addRow(array('', ''));
        }

        return $this;
    }


    private function addChap(Table $table, Share $share): self
    {
        if ($share instanceof IscsiShare) {
            if ($share->getChap()->getAuthentication() === ChapSettings::CHAP_ONE_WAY) {
                $authentication = "One-way";
            } elseif ($share->getChap()->getAuthentication() === ChapSettings::CHAP_MUTUAL) {
                $authentication = "Mutual";
            } else {
                $authentication = "Disabled";
            }

            $table
                ->addRow(array(new TableCell('Chap Authentication', array('colspan' => 2))))
                ->addRow(array('- Type', $authentication))
                ->addRow(array('- Username', $share->getChap()->getUser()))
                ->addRow(array('- Mutual Username', $share->getChap()->getMutualUser()))
                ->addRow(array('', ''));
        }

        return $this;
    }

    private function addOther(Table $table, Share $share): self
    {
        if ($share instanceof NasShare) {
            $table
                ->addRow(array(new TableCell('Protocols', array('colspan' => 2))))
                ->addRow(array('- AFP', $this->formatter->formatBool($share->getAfp()->isEnabled())))
                ->addRow(array('- NFS', $this->formatter->formatBool($share->getNfs()->isEnabled())))
                ->addRow(array('- SFTP', $this->formatter->formatBool($share->getSftp()->isEnabled())))
                ->addRow(array('', ''));
        }

        return $this;
    }


    private function addReporting(Table $table, Share $share): self
    {
        $table
            ->addRow(array(new TableCell('Reporting & Alerts', array('colspan' => 2))));

        if ($share instanceof NasShare) {
            $table
                ->addRow(array(new TableCell('- Growth Report', array('colspan' => 2))))
                ->addRow(array('  + Frequency', $share->getGrowthReport()->getFrequency()))
                ->addRow(array('  + Email List', $share->getGrowthReport()->getEmailList()));
        }

        $table
            ->addRow(array(new TableCell('- Alert Addresses', array('colspan' => 2))))
            ->addRow(array('  + Critical Error Alerts', join(", ", $share->getEmailAddresses()->getCritical())))
            ->addRow(array('  + Warning Notices', join(", ", $share->getEmailAddresses()->getWarning())))
            ->addRow(array('  + Log Digests', join(", ", $share->getEmailAddresses()->getLog())))
            ->addRow(array('', ''));

        return $this;
    }
}
