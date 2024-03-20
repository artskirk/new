<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Core\Network\WindowsDomain;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Joins a device to a Windows Active Directory Domain
 */
class DomainJoinCommand extends AbstractCommand
{
    protected static $defaultName = 'network:domain:join';

    private WindowsDomain $windowsDomain;

    public function __construct(WindowsDomain $windowsDomain)
    {
        parent::__construct();
        $this->windowsDomain = $windowsDomain;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_SERVICE_SAMBA];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Joins the device to a Windows Active Directory Domain')
            ->addArgument('domain', InputArgument::REQUIRED, 'The domain to join')
            ->addArgument('user', InputArgument::REQUIRED, 'The domain user with permissions to add this device to the domain')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'The password for the domain user');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = $input->getArgument('domain');
        $user = $input->getArgument('user');
        $password = $input->getOption('password') ?? $this->promptForPassword($input, $output);

        $this->windowsDomain->join($domain, $user, base64_encode($password));
        return 0;
    }

    private function promptForPassword(InputInterface $input, OutputInterface $output): string
    {
        $passphraseQuestion = new Question('Domain User Password: ');
        $passphraseQuestion->setHidden(true);
        $passphraseQuestion->setHiddenFallback(false);

        $questionHelper = $this->getHelper('question');
        $password = $questionHelper->ask($input, $output, $passphraseQuestion);

        if (!is_string($password)) {
            $password = '';
        }

        return $password;
    }
}
