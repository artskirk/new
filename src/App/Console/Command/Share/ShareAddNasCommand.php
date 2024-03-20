<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Nas\NasShareBuilderFactory;
use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAddNasCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:add:nas';

    private CreateShareService $createShareService;
    private NasShareBuilderFactory $nasShareBuilderFactory;

    public function __construct(
        CreateShareService $createShareService,
        CommandValidator $commandValidator,
        ShareService $shareService,
        NasShareBuilderFactory $nasShareBuilderFactory
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->createShareService = $createShareService;
        $this->nasShareBuilderFactory = $nasShareBuilderFactory;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARE_BACKUPS,
            FeatureService::FEATURE_SHARES,
            FeatureService::FEATURE_SHARES_NAS
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Create a new NAS share.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the NAS share to be created.')
            ->addOption('format', 'F', InputArgument::OPTIONAL, 'Format for this share', NasShare::DEFAULT_FORMAT)
            ->addOption('size', 'S', InputArgument::OPTIONAL, 'Size of this share', Share::DEFAULT_MAX_SIZE)
            ->addOption('template', 'T', InputArgument::OPTIONAL, 'The name of a share on which to base the configuration of the new one.')
            ->addOption('offsiteTarget', 'o', InputOption::VALUE_REQUIRED, 'This specifies the target for offsiting. Can be "cloud", "noOffsite", or a device ID for peer to peer.', SpeedSync::TARGET_CLOUD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $name = $input->getOption('share');
        $format = $input->getOption('format');
        $size = $input->getOption('size');
        $template = $input->getOption('template');
        $offsiteTarget = $input->getOption('offsiteTarget');

        if ($template) {
            $templateShare = $this->shareService->get($template);
            if ($templateShare instanceof NasShare) {
                $createdShare = $this->createNasShareFromTemplate($name, $size, $templateShare);
            } else {
                throw new InvalidArgumentException('Only other NAS share can be used as a template');
            }
        } else {
            $createdShare = $this->createNasShareFromParams($name, $format, $size, $offsiteTarget);
        }
        $output->writeln($createdShare->getKeyName());
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue($input->getOption('template'), new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")), 'Template must be alphanumeric');
        $this->commandValidator->validateValue($input->getOption('format'), new Assert\Choice(array('choices' => array('ext4'))));
        $this->commandValidator->validateValue($input->getOption('size'), new Assert\Regex(array('pattern' => '/^\d+[MGT]$/')), 'Size must be be in the format <number>(M|G|T), e.g. 16T');
    }

    private function createNasShareFromParams(string $name, string $format, string $size, string $offsiteTarget): Share
    {
        $builder = $this->nasShareBuilderFactory->create($name);
        $share = $builder
            ->format($format)
            ->offsite($this->createShareService->createDefaultOffsiteSettings())
            ->originDevice($this->createShareService->createOriginDevice())
            ->offsiteTarget($offsiteTarget)
            ->build();

        return $this->createShareService->create($share, $size);
    }

    private function createNasShareFromTemplate(string $name, string $size, NasShare $templateShare): Share
    {
        $builder = $this->nasShareBuilderFactory->create($name);
        $share = $builder
            ->originDevice($this->createShareService->createOriginDevice())
            ->build();

        return $this->createShareService->create($share, $size, $templateShare);
    }
}
