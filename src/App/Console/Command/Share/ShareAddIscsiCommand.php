<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Iscsi\IscsiShareBuilder;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAddIscsiCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:add:iscsi';

    /** @var CreateShareService */
    private $createShareService;

    public function __construct(
        CreateShareService $createShareService,
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->createShareService = $createShareService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARE_BACKUPS,
            FeatureService::FEATURE_SHARES,
            FeatureService::FEATURE_SHARES_ISCSI
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Create a new iSCSI share.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to be created.')
            ->addOption('size', 'S', InputArgument::OPTIONAL, 'Size of this share', Share::DEFAULT_MAX_SIZE)
            ->addOption('blockSize', 'B', InputArgument::OPTIONAL, 'Block size of this share', IscsiShare::DEFAULT_BLOCK_SIZE)
            ->addOption('template', 'T', InputArgument::OPTIONAL, 'The name of a share on which to base the configuration of the new one.')
            ->addOption('offsiteTarget', 'o', InputOption::VALUE_REQUIRED, 'This specifies the target for offsiting. Can be "cloud", "noOffsite", or a device ID for peer to peer.', SpeedSync::TARGET_CLOUD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $name = $input->getOption('share');
        $size = $input->getOption('size');
        $blockSize = $input->getOption('blockSize');
        $template = $input->getOption('template');
        $offsiteTarget = $input->getOption('offsiteTarget');

        if ($template) {
            $templateShare = $this->shareService->get($template);
            if ($templateShare instanceof IscsiShare) {
                $createdShare = $this->createIscsiShareFromTemplate($name, $size, $templateShare);
            } else {
                throw new InvalidArgumentException('Only other iSCSI share can be used as a template');
            }
        } else {
            $createdShare = $this->createIscsiShareFromParams($name, $size, $blockSize, $offsiteTarget);
        }
        $output->writeln($createdShare->getKeyName());
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue($input->getOption('template'), new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")), 'Template must be alphanumeric');
        $this->commandValidator->validateValue($input->getOption('size'), new Assert\Regex(array('pattern' => '/^\d+[MGT]$/')), 'Size must be be in the format <number>(M|G|T), e.g. 16T');
        $this->commandValidator->validateValue($input->getOption('blockSize'), new Assert\Choice(array('choices' => array(IscsiShare::BLOCK_SIZE_SMALL, IscsiShare::BLOCK_SIZE_LARGE))));
    }

    /**
     * @param string $name
     * @param string $size
     * @param int $blockSize
     * @param string $offsiteTarget
     * @return Share $share
     */
    private function createIscsiShareFromParams($name, $size, $blockSize, string $offsiteTarget): Share
    {
        $builder = new IscsiShareBuilder($name, $this->logger);
        $share = $builder
            ->blockSize($blockSize)
            ->offsite($this->createShareService->createDefaultOffsiteSettings())
            ->originDevice($this->createShareService->createOriginDevice())
            ->offsiteTarget($offsiteTarget)
            ->build();

        return $this->createShareService->create($share, $size);
    }

    /**
     * @param string $name
     * @param string $size
     * @param IscsiShare $templateShare
     * @return Share $share
     */
    private function createIscsiShareFromTemplate($name, $size, $templateShare): Share
    {
        $builder = new IscsiShareBuilder($name, $this->logger);
        $share = $builder
            ->originDevice($this->createShareService->createOriginDevice())
            ->build();

        return $this->createShareService->create($share, $size, $templateShare);
    }
}
