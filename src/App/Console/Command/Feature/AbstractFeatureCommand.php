<?php

namespace Datto\App\Console\Command\Feature;

use Symfony\Component\Console\Command\Command;
use Datto\Feature\FeatureService;

/**
 * Class AbstractFeatureCommand
 * Injects FeatureService to all child classes.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class AbstractFeatureCommand extends Command
{
    /** @var FeatureService */
    protected $featureService;

    public function __construct(
        FeatureService $featureService
    ) {
        parent::__construct();

        $this->featureService = $featureService;
    }
}
