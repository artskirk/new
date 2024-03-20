<?php

namespace Datto\Service\Networking;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceState;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * Manages backup and automated revert operations for system network connections. To accomplish this, we use the
 * Checkpoint/Rollback mechanisms built into NetworkManager, and accessible via the DBus API (unfortunately, these
 * APIs are not exposed in `nmcli`).
 *
 * Essentially, we can tell NetworkManager to create a Checkpoint, which it will automatically (and silently) roll
 * back to after the supplied timeout value. After creating the checkpoint, any changes we make to any connections
 * will be automatically undone by NetworkManager when the timeout expires. This means that to "commit" changes, we
 * basically just have to cancel the upcoming automated rollback.
 *
 * We also provide a means here to manually rollback changes without waiting for the timer, for example in the case
 * that we encounter an error while making changes that require multiple `nmcli` operations, since there's not really
 * a good way to make these changes atomic.
 */
class LinkBackup implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var string Documentation text provided to users in command help and after requesting network changes */
    public const COMMIT_REQUIRED_NOTIFICATION_TEXT = 'The change rolls back after 3 minutes if it is not followed up with a \'snapctl network:changes:commit\' command.';

    /** @var string A state indicating there are no link backups */
    public const STATE_NONE = 'none';

    /** @var string A state indicating that a change is currently in the process of being made */
    public const STATE_IN_PROGRESS = 'in-progress';

    /** @var string A state indicating that a link backup is pending confirm/revert */
    public const STATE_PENDING = 'pending';

    /** @var string A state indicating that a link backup was automatically reverted */
    public const STATE_REVERTED = 'reverted';

    /** @var string A DeviceState key containing the DBus path of the current NetworkManager checkpoint */
    private const CUR_CHECKPOINT_KEY = 'netCheckpoint';

    /** @var string A DeviceState key indicating that a checkpoint is and is ready for user confirmation */
    private const CHECKPOINT_READY_KEY = 'netCheckpointReady';

    /** @var int The time to wait before reverting uncommitted networking changes */
    private const REVERT_TIMER_SECONDS = 180;

    private ProcessFactory $processFactory;
    private DeviceState $deviceState;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceState $deviceState
    ) {
        $this->processFactory = $processFactory;
        $this->deviceState = $deviceState;
    }

    /**
     * Snapshots the current state of the managed devices, with a scheduled automatic rollback in the future. If the
     * rollback is not canceled by calling `commit()` before the timer expires, NetworkManager will automatically
     * revert to this snapshot.
     *
     * After creating a checkpoint, the checkpoint state will be "in-progress" until `setPending` is called. While
     * a change is in progress, attempting to commit the change will do nothing.
     */
    public function create(): void
    {
        $this->logger->debug('LBU0001 Creating Link Checkpoint');

        // Create a NetworkManager checkpoint of our current link configuration, set to automatically roll back
        $checkpoint = $this->checkpointCreate(self::REVERT_TIMER_SECONDS);
        $this->deviceState->set(self::CUR_CHECKPOINT_KEY, $checkpoint);
        $this->deviceState->clear(self::CHECKPOINT_READY_KEY);

        $this->logger->debug('LBU0002 Checkpoint Created', ['checkpoint' => $checkpoint]);
    }

    /**
     * Mark the LinkBackup as being ready, and pending user confirmation.
     *
     * @return void
     */
    public function setPending(): void
    {
        $this->logger->debug('LBU0003 Marking Checkpoint as Pending Confirmation');
        // Set the "Checkpoint Ready" flag, which we use in our logic to prevent the front-end from confirming changes
        // before they have actually been completed.
        $this->deviceState->touch(self::CHECKPOINT_READY_KEY);
    }

    /**
     * "Commit" any pending change by cancelling the NetworkManager automatic rollback.
     *
     * @return bool true if changes were committed (or if there were none to commit), false if the changes could not
     *  be committed, because they have already been reverted.
     */
    public function commit(): bool
    {
        // Early return if there were changes that were previously reverted
        if ($this->getState() === self::STATE_REVERTED) {
            return false;
        }

        // Additional early return if there are no changes to confirm
        if ($this->getState() !== self::STATE_PENDING) {
            return true;
        }

        try {
            // Delete the NetworkManager checkpoint, cancelling the automatic rollback.
            $checkpoint = $this->deviceState->get(self::CUR_CHECKPOINT_KEY, '');
            $this->logger->debug('LBU0010 Committing Link Changes', ['checkpoint' => $checkpoint]);
            $this->checkpointDestroy($checkpoint);
            $this->deviceState->clear(self::CUR_CHECKPOINT_KEY);
            $this->deviceState->clear(self::CHECKPOINT_READY_KEY);
        } catch (Throwable $exception) {
            $this->logger->warning('LBU2011 Failed to commit network checkpoint', ['exception' => $exception]);
            return false;
        }
        return true;
    }

    /**
     * Revert any pending changes. If there are no changes pending, this will do nothing.
     */
    public function revert(): void
    {
        $this->logger->debug('LBU0020 Reverting uncommitted networking changes');
        $checkpoint = $this->deviceState->get(self::CUR_CHECKPOINT_KEY, false);

        if (!$checkpoint) {
            $this->logger->warning('LBU2021 No saved checkpoint to roll back to');
            return;
        }

        $rollbackResults = $this->checkpointRollback($checkpoint);
        $this->deviceState->clear(self::CUR_CHECKPOINT_KEY);
        $this->deviceState->clear(self::CHECKPOINT_READY_KEY);
        $this->logger->info('LBU1022 Checkpoint Rollback Complete', ['rollbackResults' => $rollbackResults]);
    }

    /**
     * @return string The current LinkBackup state (none, in-progress, pending, reverted)
     */
    public function getState(): string
    {
        $ourCheckpoint = $this->deviceState->get(self::CUR_CHECKPOINT_KEY, '');
        $checkpointReady = $this->deviceState->has(self::CHECKPOINT_READY_KEY);
        $nmCheckpoints = $this->getCheckpoints();

        if (!empty($nmCheckpoints)) {
            // If NetworkManager has checkpoints, we're either in the process of making a change, or we're waiting for
            // confirmation from the User/UI
            if (!$checkpointReady) {
                return self::STATE_IN_PROGRESS;
            } else {
                return self::STATE_PENDING;
            }
        } elseif ($ourCheckpoint) {
            // If we're tracking a checkpoint and NetworkManager isn't, then it means that the checkpoint timer
            // must have expired, and the checkpoint was automatically reverted.
            return self::STATE_REVERTED;
        } else {
            // If nether us nor NetworkManager is tracking any checkpoints, then it means none exist.
            return self::STATE_NONE;
        }
    }

    /**
     * Acknowledgement from the User/UI that an automatic rollback of changes occurred.
     */
    public function acknowledgeRevert(): void
    {
        if ($this->getState() === self::STATE_REVERTED) {
            // We only know we have reverted because we're tracking a checkpoint that no longer exists in
            // NetworkManager, so when acknowledging the revert, just clear out our tracked state.
            $this->deviceState->clear(self::CUR_CHECKPOINT_KEY);
            $this->deviceState->clear(self::CHECKPOINT_READY_KEY);
        }
    }

    /**
     * Create a checkpoint of the current networking configuration for all interfaces. If `rollbackTimeout` is non-zero,
     * a rollback is automatically performed after the given timeout.
     *
     * @see https://networkmanager.dev/docs/api/latest/gdbus-org.freedesktop.NetworkManager.html#gdbus-method-org-freedesktop-NetworkManager.CheckpointCreate
     *
     * @param int $rollbackTimeout The timeout in seconds to automatically roll back. 0 to disable automatic rollback.
     * @return string The DBus path of the newly-created checkpoint
     */
    private function checkpointCreate(int $rollbackTimeout): string
    {
        $output = $this->dbusCall(
            '/org/freedesktop/NetworkManager',
            'org.freedesktop.NetworkManager.CheckpointCreate',
            [
                '[]', // Empty array signifies we want to snapshot the state of all devices
                $rollbackTimeout, // The timeout in seconds before an automatic rollback occurs
                0x07 // Flags (DESTROY_ALL [0x01] | DELETE_NEW_CONNECTIONS [0x02] | DISCONNECT_NEW_DEVICES [0x04])
            ]
        );

        if (preg_match("/'([^']+)'/", $output, $matches)) {
            // The path of the checkpoint is everything between the single quotes
            return $matches[1];
        } else {
            $this->logger->warning('LBU2040 Could not create NetworkManager checkpoint', ['output' => $output]);
            throw new RuntimeException('Could not create checkpoint');
        }
    }

    /**
     * Destroy a previously-created checkpoint, or all checkpoints.
     *
     * @see https://networkmanager.dev/docs/api/latest/gdbus-org.freedesktop.NetworkManager.html#gdbus-method-org-freedesktop-NetworkManager.CheckpointDestroy
     *
     * @param string $checkpointPath The checkpoint to destroy, or an empty string to destroy all checkpoints
     */
    private function checkpointDestroy(string $checkpointPath = ''): void
    {
        $this->dbusCall(
            '/org/freedesktop/NetworkManager',
            'org.freedesktop.NetworkManager.CheckpointDestroy',
            [$checkpointPath]
        );
    }

    /**
     * Perform a manual rollback to a given checkpoint.
     *
     * @see https://networkmanager.dev/docs/api/latest/gdbus-org.freedesktop.NetworkManager.html#gdbus-method-org-freedesktop-NetworkManager.CheckpointRollback
     *
     * @param string $checkpointPath The DBUS path of the checkpoint to roll back to.
     * @return array<string, int> An array of devices rolled back, along with a RollbackResult enum for each
     *  (0 => Success, 1 => The device no longer exists, 2 => The device is now unmanaged, 3 => Other errors)
     */
    private function checkpointRollback(string $checkpointPath): array
    {
        $output = $this->dbusCall(
            '/org/freedesktop/NetworkManager',
            'org.freedesktop.NetworkManager.CheckpointRollback',
            [$checkpointPath]
        );

        // The above call returns output like this (including `uint32` only on the first entry). Parse it accordingly.
        //     ({'/dbus/path/1': uint32 0, '/dbus/path/2': 0, '/dbus/path/4': 0},)

        $rollbackResults = [];
        // First throw out everything except what's between the curly braces
        if (preg_match('/{([^}]*)}/', $output, $matches)) {
            // Split on commas and iterate over each path/result pair.
            foreach (explode(', ', $matches[1]) as $pair) {
                // The path is whatever is between the single quotes, and the result is the single digit at the end
                if (preg_match("/'(?<path>[^']+)'.*(?<result>\d)$/", $pair, $pairMatches)) {
                    $rollbackResults[$pairMatches['path']] = $pairMatches['result'];
                }
            }
        }

        return $rollbackResults;
    }

    /**
     * Get a list of all the currently-active checkpoints
     *
     * @see https://networkmanager.dev/docs/api/latest/gdbus-org.freedesktop.NetworkManager.html#gdbus-property-org-freedesktop-NetworkManager.Checkpoints
     *
     * @return string[] A list of checkpoint paths
     */
    private function getCheckpoints(): array
    {
        $output = $this->dbusCall(
            '/org/freedesktop/NetworkManager',
            'org.freedesktop.DBus.Properties.Get',
            [
                'org.freedesktop.NetworkManager',
                'Checkpoints'
            ]
        );

        if (preg_match_all("/'([^']+)'/", $output, $matches)) {
            return array_slice($matches, 1);
        }
        return [];
    }

    /**
     * Make a NetworkManager DBus call using `gdbus`, and return the results as a string
     *
     * @see https://www.freedesktop.org/software/gstreamer-sdk/data/docs/2012.5/gio/gdbus.html
     *
     * @param string $path The DBus path of the object to make the call on
     * @param string $method The method to call
     * @param string[] $arguments The arguments for the method
     *
     * @return string The output from the `gdbus` command
     */
    private function dbusCall(string $path, string $method, array $arguments): string
    {
        $cmdLine = [
            'gdbus', 'call', '--system',
            '--dest', 'org.freedesktop.NetworkManager',
            '--object-path', $path,
            '--method', $method
        ];

        $output = $this->processFactory->get(array_merge($cmdLine, $arguments))->mustRun()->getOutput();
        return trim($output);
    }
}
