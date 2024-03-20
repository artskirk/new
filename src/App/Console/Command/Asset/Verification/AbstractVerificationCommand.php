<?php

namespace Datto\App\Console\Command\Asset\Verification;

use Datto\Asset\AssetService;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;

/**
 * Class AbstractVerificationCommand
 * Injects Asset Service to concrete commands
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class AbstractVerificationCommand extends AbstractCommand
{
    /** @var AssetService */
    protected $assetService;

    /** @var CommandValidator */
    protected $validator;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct();

        $this->validator = $commandValidator;
        $this->assetService = $assetService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_VERIFICATIONS
        ];
    }
}
