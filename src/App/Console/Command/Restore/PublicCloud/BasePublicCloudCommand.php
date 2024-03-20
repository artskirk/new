<?php

namespace Datto\App\Console\Command\Restore\PublicCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\App\Security\Constraints\AssetExists;
use Datto\Asset\AssetType;
use Datto\Feature\FeatureService;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudExporter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base abstract class for configuration of public cloud commands.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
abstract class BasePublicCloudCommand extends AbstractCommand
{
    /** @var CommandValidator */
    protected $commandValidator;

    public function __construct(CommandValidator $commandValidator)
    {
        $this->commandValidator = $commandValidator;
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION_PUBLIC_CLOUD];
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->addArgument(
                'agent',
                InputArgument::REQUIRED,
                'Name of the agent on which to base the public cloud virtualization.'
            )
            ->addArgument(
                'snapshot',
                InputArgument::REQUIRED,
                'Snapshot of the agent on which to base the public cloud virtualization.'
            )
            ->addArgument(
                'vmGeneration',
                InputArgument::OPTIONAL,
                'HyperV VM generation.',
                PublicCloudExporter::VM_GENERATION_V2
            );
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $input->getArgument('agent'),
            new AssetExists(['type' => AssetType::AGENT]),
            'Agent must be specified'
        );
        $this->commandValidator->validateValue(
            $input->getArgument('snapshot'),
            new Assert\NotBlank(),
            'Snapshot must be specified'
        );
        $this->commandValidator->validateValue(
            $input->getArgument('snapshot'),
            new Assert\Regex(['pattern' => '~^[[:graph:]]+$~']),
            'Snapshot must be alphanumeric'
        );
        $vmGenerationChoice = new Assert\Choice([
            PublicCloudExporter::VM_GENERATION_V1,
            PublicCloudExporter::VM_GENERATION_V2
        ]);
        $this->commandValidator->validateValue(
            $input->getArgument('vmGeneration'),
            $vmGenerationChoice,
            'VM generation must be one of the following: ' . implode(', ', $vmGenerationChoice->choices)
        );
    }
}
