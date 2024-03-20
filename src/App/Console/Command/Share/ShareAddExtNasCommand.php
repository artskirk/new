<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\ExternalNas\ExternalNasService;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\ExternalNas\ExternalNasShareBuilder;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Feature\FeatureService;
use Datto\System\SambaMount;
use Datto\Util\ScriptInputHandler;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAddExtNasCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:add:extnas';

    /** @var ScriptInputHandler */
    private $scriptInputHandler;

    /** @var ExternalNasService */
    private $externalNasService;
    
    /** @var CreateShareService */
    private $createShareService;

    public function __construct(
        ScriptInputHandler $scriptInputHandler,
        ExternalNasService $externalNasService,
        CreateShareService $createShareService,
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->scriptInputHandler = $scriptInputHandler;
        $this->externalNasService = $externalNasService;
        $this->createShareService = $createShareService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARE_BACKUPS,
            FeatureService::FEATURE_SHARES,
            FeatureService::FEATURE_SHARES_EXTERNAL
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Create a new External NAS share.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the External NAS share to be created.')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Host of the external share to backup')
            ->addOption('folder', 'f', InputOption::VALUE_REQUIRED, 'Folder path on the host to backup')
            ->addOption('username', 'u', InputOption::VALUE_OPTIONAL, 'The username for the external share to backup. This will prompt for a password.')
            ->addOption('domain', 'd', InputOption::VALUE_OPTIONAL, 'The domain for the external share to backup.')
            ->addOption('acls', null, InputOption::VALUE_NONE, 'Back up NTFS permissions on this share.')
            ->addOption('template', 'T', InputOption::VALUE_OPTIONAL, 'The name of a share on which to base the configuration of the new one.')
            ->addOption('offsiteTarget', 'o', InputOption::VALUE_REQUIRED, 'This specifies the target for offsiting. Can be "cloud", "noOffsite", or a device ID for peer to peer.', SpeedSync::TARGET_CLOUD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $name = trim($input->getOption('share'));
        $host = trim($input->getOption('host'));
        $folder = trim($input->getOption('folder'));
        $username = $input->getOption('username');
        $username = $username === null ? null : trim($username);
        $domain = $input->getOption('domain');
        $domain = $domain === null ? null : trim($domain);
        $backupAcls = $input->getOption('acls');
        $template = $input->getOption('template');
        $template = $template === null ? null : trim($template);
        $offsiteTarget = $input->getOption('offsiteTarget');

        $password = null;
        if ($username) {
            echo 'Password:';
            $password = $this->scriptInputHandler->readHiddenInput();
            echo "\n"; //since the user's return press is swallowed by readHiddenInput
        }

        $templateShare = null;
        if ($template) {
            $templateShare = $this->shareService->get($template);
            if (!($templateShare instanceof ExternalNasShare)) {
                throw new InvalidArgumentException('Only other External NAS shares can be used as a template');
            }
        }

        $sambaMount = new SambaMount($host, $folder, $username, $password, $domain, true, $backupAcls);

        $createdShare = $this->createExternalNasShareFromParams($name, $sambaMount, $backupAcls, $offsiteTarget, $templateShare);
        $output->writeln($createdShare->getKeyName());
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue($input->getOption('template'), new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")), 'Template must be alphanumeric');
        $this->commandValidator->validateValue($input->getOption('host'), new Assert\Regex(array('pattern' => "~^\P{Cc}\S{1,256}$~")), 'Host must not use unprintable characters');
        $this->commandValidator->validateValue($input->getOption('domain'), new Assert\Regex(array('pattern' => '~\P{Cc}{1,256}$~')), 'Domain must not use unprintable characters');
        $this->commandValidator->validateValue($input->getOption('folder'), new Assert\Regex(array('pattern' => "~^\P{Cc}{1,4096}$~")), 'Folder must not use unprintable characters');
        $this->commandValidator->validateValue($input->getOption('username'), new Assert\Regex(array('pattern' => "~^\P{Cc}{1,256}$~")), 'Username must not use unprintable characters');
    }

    /**
     * @param string $name
     * @param SambaMount $sambaMount
     * @param bool $backupAcls
     * @param string $offsiteTarget
     * @param ExternalNasShare|null $templateShare
     * @return Share $share
     */
    private function createExternalNasShareFromParams(
        $name,
        SambaMount $sambaMount,
        bool $backupAcls,
        string $offsiteTarget,
        ExternalNasShare $templateShare = null
    ): Share {
        $builder = new ExternalNasShareBuilder($name, $sambaMount, $this->logger);
        $builder->offsite($this->createShareService->createDefaultOffsiteSettings());
        $builder->backupAcls($backupAcls);

        /** @var ExternalNasShare $share */
        $share = $builder
            ->originDevice($this->createShareService->createOriginDevice())
            ->offsiteTarget($offsiteTarget)
            ->build();

        if (!$this->externalNasService->isMountable($share, $sambaMount)) {
            throw new \Exception('Cannot mount shared folder. Is it currently accessible?');
        }

        return $this->createShareService->create($share, Share::DEFAULT_MAX_SIZE, $templateShare);
    }
}
