<?php

namespace Datto\App\Console\Command;

use Datto\Asset\Share\ShareService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Validator\Constraints as Assert;

abstract class AbstractShareCommand extends AbstractCommand
{
    /** @var ShareService  */
    protected $shareService;

    /** @var  CommandValidator */
    protected $commandValidator;

    public function __construct(
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct();

        $this->commandValidator = $commandValidator;
        $this->shareService = $shareService;
    }

    /**
     * TODO: Remove this and force implementation on every Datto\App\Console\Command\Share command
     *
     * {@inheritdoc}
     */
    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARES
        ];
    }

    /**
     * @param InputInterface $input
     * @return \Datto\Asset\Share\Share[]
     */
    protected function getShares(InputInterface $input)
    {
        $shareName = $input->getOption('share');
        if (isset($shareName)) {
            $shares = [$this->shareService->get($shareName)];
        } else {
            $shares = $this->shareService->getAll();
        }
        return $shares;
    }

    /**
     * @param InputInterface $input
     */
    protected function validateShare(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $this->hasSingleShareOption($input),
            new Assert\IsTrue(),
            'Either --all or --share must be set, but not both. If --all is not present then you can only perform the action on one share.'
        );

        if ($input->hasOption('share')) {
            $this->commandValidator->validateValue(
                $input->getOption('share'),
                new Assert\Regex(['pattern' => "~^[[:alnum:]]+$~"]),
                'Name must be alphanumeric'
            );
        }
    }

    private function hasSingleShareOption(InputInterface $input): bool
    {
        $shareName = $input->getOption('share');
        if ($input->hasOption('all')) {
            $all = $input->getOption('all');
        } else {
            $all = false;
        }

        $shareOnly = isset($shareName) && !$all;
        $allOnly = $all && !isset($shareName);

        return $shareOnly || $allOnly;
    }
}
