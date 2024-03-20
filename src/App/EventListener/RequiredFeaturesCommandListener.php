<?php

namespace Datto\App\EventListener;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Symfony command listener to prevent execution of commands that do not meet the feature requirement defined by
 * each command.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RequiredFeaturesCommandListener implements EventSubscriberInterface
{
    /** @var FeatureService */
    private $featureService;

    public function __construct(FeatureService $featureService)
    {
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 1]
        ];
    }

    /**
     * @param ConsoleCommandEvent $event
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command instanceof AbstractCommand) {
            $unsupportedFeatures = [];

            //TODO this could be optimized by storing already-checked features

            $requiredFeatures = $command::getRequiredFeatures();
            foreach ($requiredFeatures as $requiredFeature) {
                if (!$this->featureService->isSupported($requiredFeature)) {
                    $unsupportedFeatures[] = $requiredFeature;
                }
            }

            if (!empty($unsupportedFeatures)) {
                throw new \Exception("Command requires the following features: " . implode(", ", $unsupportedFeatures));
            }
        }
    }
}
