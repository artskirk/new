<?php

namespace Datto\App\Console\Command;

use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Provides a set of methods that all commands should inherit, in a perfect world only subclasses should be required
 * to define the implementation.
 *
 * TODO: Update all commands to inherit from this class
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractCommand extends Command implements LoggerAwareInterface
{
    use RequiresInteractivePassphrase;
    use LoggerAwareTrait;

    /**
     * Define a list of required features for the command to run.
     *
     * @return string[]
     * @see FeatureService::FEATURE_* for available features
     *
     */
    abstract public static function getRequiredFeatures(): array;

    /**
     * @return bool True if multiple instances of this command can be run concurrently. False if only one instance of
     *   the command process is allowed to run at a time.
     */
    public function multipleInstancesAllowed(): bool
    {
        return true;
    }

    /**
     * Allow command to conditionally ignore --fuzz option.
     *
     * The SnapctlApplication injects --fuzz support to all commands while the
     * CommandListener provides handler it. This option is most often passed
     * from systemd units/timers or bash scripts that run at certain interval.
     * However, there are some cases where a command needs to be conditionally
     * run with --fuzz, and it's better/cleaner to have the logic dealt with
     * in the command itself rather than systemd timer or bash scirpt.
     */
    public function fuzzAllowed(): bool
    {
        return true;
    }
}
