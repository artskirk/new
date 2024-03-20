<?php

namespace Datto\App\Console\Command\Asset\Verification;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\App\Security\Constraints\AssetExists;
use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Verification\VerificationFactory;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class VerificationRunCommand
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class RunCommand extends AbstractVerificationCommand
{
    protected static $defaultName = 'asset:verification:run';

    /** @var VerificationFactory */
    private $verificationFactory;

    public function __construct(
        VerificationFactory $verificationFactory,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->verificationFactory = $verificationFactory;
    }

    protected function configure()
    {
        $this
            ->setDescription('Run verification on an asset.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Name of the asset.')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot epoch as an integer. Will use the latest snapshot if left blank.')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Number of seconds to delay running the verification.', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $asset = $input->getArgument('asset');
        $snapshot = intval($input->getArgument('snapshot'));
        $delay = intval($input->getOption('delay'));
        $agent = $this->assetService->get($asset);

        // use latest point if it wasn't set
        if ($snapshot === 0) {
            $snapshot = $agent->getLocal()->getRecoveryPoints()->getLast()->getEpoch();
        }

        if ($agent instanceof Agent && !$agent->isRescueAgent()) {
            $verificationProcess = $this->verificationFactory->create($asset, $snapshot, $delay);
            $verificationProcess->execute();
        } else {
            throw new Exception("Rescue agents do not support screenshots at this time.");
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validator->validateValue(
            $input->getArgument('asset'),
            new AssetExists(array('type' => AssetType::AGENT)),
            'Asset must exist'
        );
        $this->validator->validateValue(
            $input->getArgument('snapshot'),
            new Assert\Regex(array('pattern' => '/^\d+$/')),
            'Snapshot epoch time must be an integer'
        );
        $this->validator->validateValue(
            $input->getOption('delay'),
            new Assert\Regex(array('pattern' => '/^\d+$/')),
            'Delay time must be an integer'
        );
    }
}
