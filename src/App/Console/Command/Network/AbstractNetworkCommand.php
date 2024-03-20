<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\LinkService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\Console\Command\Command;

/**
 * Class AbstractNetworkCommand
 *
 * @author Mario Rial <mrial@datto.com>
 */
abstract class AbstractNetworkCommand extends Command
{
    protected NetworkService $networkService;
    protected LinkService $linkService;

    public function __construct(
        NetworkService $networkService,
        LinkService $linkService
    ) {
        parent::__construct();

        $this->networkService = $networkService;
        $this->linkService = $linkService;
    }
}
