<?php

namespace Datto\App\Console\Command\System\Api\Key;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\App\Security\Api\ApiKeyService;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class RegenerateCommand extends AbstractCommand
{
    protected static $defaultName = 'system:api:key:regenerate';

    /** @var ApiKeyService */
    private $apiKeyService;

    public function __construct(
        ApiKeyService $apiKeyService
    ) {
        parent::__construct();

        $this->apiKeyService = $apiKeyService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_AUTH_LOCALHOST_API_KEY
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Regenerate API key for this user.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Regenerate API keys for all users.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('username') && $input->getOption('all')) {
            throw new InvalidArgumentException("Cannot provide username argument and --all together.");
        }

        if (!$input->getArgument('username') && !$input->getOption('all')) {
            throw new InvalidArgumentException("Must provide username argument or --all option.");
        }

        if ($input->getOption('all')) {
            $this->apiKeyService->regenerateAll();
        } else {
            $username = $input->getArgument('username');
            $this->apiKeyService->regenerate($username);
        }
        return 0;
    }
}
