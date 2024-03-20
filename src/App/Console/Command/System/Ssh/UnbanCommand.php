<?php

namespace Datto\App\Console\Command\System\Ssh;

use Datto\Network\Fail2BanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Datto\App\Console\Command\CommandValidator;
use Symfony\Component\Console\Input\InputOption;
use Datto\App\Console\Input\InputArgument;

/**
 * Unlock SSH logins
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class UnbanCommand extends Command
{
    protected static $defaultName = 'system:ssh:unban';

    /** @var Fail2BanService */
    private $fail2BanService;

    /** @var CommandValidator */
    private $commandValidator;

    public function __construct(
        Fail2BanService $fail2BanService,
        CommandValidator $commandValidator
    ) {
        parent::__construct();

        $this->fail2BanService = $fail2BanService;
        $this->commandValidator = $commandValidator;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Manually unban one or all IPs that have been temporarily banned becuase of too many password attempts.')
            ->addArgument('ip', InputArgument::OPTIONAL, 'IP of the host to unban', '')
            ->addOption('all', 'a', InputOption::VALUE_NONE, "Unban all banned IP's");
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $all = $input->getOption('all');
        if ($all) {
            $this->fail2BanService->unbanAll();
        } else {
            $ip = $input->getArgument('ip');
            $this->fail2BanService->unban($ip);
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    protected function validateArgs(InputInterface $input): void
    {
        $all = $input->getOption('all');
        $ip = $input->getArgument('ip');
        if ($all === false) {
            $this->commandValidator->validateValue(
                $ip,
                new Assert\Ip(),
                'IP address is invalid.'
            );
        } else {
            $this->commandValidator->validateValue(
                $ip,
                new Assert\Blank(),
                'You may either select all or specify a single ip -- not both.'
            );
        }
    }
}
