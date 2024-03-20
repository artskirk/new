<?php

namespace Datto\App\Console\Command\Asset\Error;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\AssetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clear stored error for an agent.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ClearCommand extends Command
{
    protected static $defaultName = 'asset:error:clear';

    /** @var AssetService */
    private $assetService;

    public function __construct(
        AssetService $assetService
    ) {
        parent::__construct();

        $this->assetService = $assetService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('assetKey', InputArgument::REQUIRED, 'Asset to clear errors for.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('assetKey');

        $asset = $this->assetService->get($assetKey);
        $asset->clearLastError();
        $this->assetService->save($asset);
        return 0;
    }
}
