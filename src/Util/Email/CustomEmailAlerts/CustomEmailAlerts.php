<?php
namespace Datto\Util\Email\CustomEmailAlerts;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Reads and writes the subject.emails file. The emails array looks like:
 * array(
 *     'screenshots' => 'Bootable Screenshot for -agenthostname on -devicehostname (-sn) -shot',
 *     'weeklys' => 'Weekly Backup Report for -devicehostname',
 *     'critical' => 'CRITICAL ERROR for -agenthostname on -devicehostname (-sn)',
 *     'missed' => 'Warning for -agenthostname on -devicehostname (-sn)',
 *     'notice' => '',
 *     'logs' => 'Logs for -agenthostname on -devicehostname (-sn)',
 *     'growth' => 'Growth Report for -agenthostname on -devicehostname (-sn)'
 * )
 *
 * @author Peter Salu <psalu@datto.com>
 */
class CustomEmailAlerts
{
    const FILE = '/datto/config/subject.emails';

    /** @var Filesystem */
    private $fileSystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->fileSystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Save the email subjects.
     *
     * @param array $emails
     */
    public function write(array $emails)
    {
        $emailsSerialized = serialize($emails);

        $this->fileSystem->filePutContents(self::FILE, $emailsSerialized);
    }

    /**
     * Gets the email subjects.
     *
     * @return array
     */
    public function read()
    {
        $emailsString = $this->fileSystem->fileGetContents(self::FILE);
        $emails = unserialize($emailsString, ['allowed_classes' => false]);

        return $emails;
    }

    /**
     * @return bool True if the key file that stores email subjects exists, false otherwise.
     */
    public function fileExists()
    {
        return $this->fileSystem->exists(self::FILE);
    }
}
