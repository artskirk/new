<?php

namespace Datto\Samba;

use Datto\Common\Resource\ProcessFactory;
use Datto\User\PasswordFileEntry;
use Datto\User\ShadowUser;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Service class to list and create Samba users.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class UserService
{
    /** @var Filesystem */
    private $filesystem;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var SmbpasswdFileRepositoryFactory */
    private $smbpasswdFileRepositoryFactory;

    /**
     * @param Filesystem|null $filesystem
     * @param ProcessFactory|null $processFactory
     * @param SmbpasswdFileRepositoryFactory|null $smbpasswdFileRepositoryFactory
     */
    public function __construct(
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        SmbpasswdFileRepositoryFactory $smbpasswdFileRepositoryFactory = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
        $this->smbpasswdFileRepositoryFactory = $smbpasswdFileRepositoryFactory ?: new SmbpasswdFileRepositoryFactory($this->filesystem);
    }

    /**
     * Creates a new Samba user via the 'pdbedit' command.
     *
     * @param string $user Username
     * @param string $pass Password
     */
    public function create(string $user, string $pass)
    {
        $passwordInput = $pass . "\n" . $pass . "\n";

        $this->processFactory->get([
                'passwd',
                $user
            ])
            ->setInput($passwordInput)
            ->mustRun();

        $this->processFactory->get([
                'pdbedit',
                '-t',
                '-a',
                $user
            ])
            ->setInput($passwordInput)
            ->mustRun();
    }

    /**
     * Delete a samba user
     *
     * @param string $user
     */
    public function delete(string $user)
    {
        $this->processFactory->get([
                'smbpasswd',
                '-x',
                $user
            ])
            ->mustRun();
    }

    /**
     * Set the password for a samba user
     *
     * @param $user
     * @param $pass
     */
    public function setPassword(string $user, string $pass)
    {
        ShadowUser::validateName($user);

        if (!$this->isValidUser($user)) {
            throw new Exception("User $user does not exist");
        }

        $this->processFactory->get([
                'smbpasswd',
                '-s',
                $user
            ])
            ->setInput("$pass\n$pass\n")
            ->mustRun();
    }

    /**
     * Returns a list of all existing users that can be added via. Samba
     *
     * @return string[]
     */
    public function getUsers(): array
    {
        return array_unique(array_merge($this->getLocalUsers(), $this->getDomainUsers()));
    }

    /**
     * Returns a list of domain users using wbinfo. If the device is not configured for domain membership, this will
     * return an empty array.
     *
     * @return string[]
     */
    public function getDomainUsers(): array
    {
        // If we're not configured for domain membership, this will return immediately with a non-zero exit code
        $process = $this->processFactory->get(['wbinfo', '--domain-users']);

        // Run with a timeout long enough to get all the users even on big domains, but not break the device if we
        // can't reach the DC
        $exitCode = $process->setTimeout(10)->run();

        // If the process returned successfully, parse the output
        if ($exitCode === 0) {
            $users = explode(PHP_EOL, trim($process->getOutput()));
            sort($users);
            return $users;
        }
        return [];
    }

    /**
     * Returns a list of domain groups using wbinfo. If the device is not configured for domain membership, this will
     * return an empty array.
     *
     * @return string[]
     */
    public function getDomainGroups(): array
    {
        $process = $this->processFactory->get(['wbinfo', '--domain-groups']);
        $exitCode = $process->setTimeout(10)->run();

        $groups = [];
        if ($exitCode === 0) {
            foreach (explode(PHP_EOL, trim($process->getOutput())) as $group) {
                $groups[] = '@' . $group;
            }
            sort($groups);
        }

        return $groups;
    }

    /**
     * Returns a list of local Samba users (using pdbedit -L).
     *
     * @return string[]
     */
    public function getLocalUsers(): array
    {
        $process = $this->processFactory->get([
                'pdbedit',
                '-L',
                '-d 0'
            ]);
        $process->run();

        $users = [];
        $allUsers = $this->getFields($process->getOutput(), 0, ':');

        foreach ($allUsers as $user) {
            $isIgnored = in_array($user, $this->getIgnoreUsers());

            if (!$isIgnored) {
                $users[] = $user;
            }
        }

        sort($users);

        return $users;
    }

    /**
     * Check to see if username matches samba user listing
     *
     * @param string $username The username to check
     * @return bool
     */
    public function isValidUser(string $username): bool
    {
        return in_array($username, $this->getUsers());
    }

    /**
     * Check to see if group matches samba group listing
     *
     * @param $username
     * @return bool
     */
    public function isValidGroup(string $username): bool
    {
        return in_array($username, $this->getDomainGroups());
    }

    /**
     * Imported new samba users to the samba database.
     *
     * @param PasswordFileEntry[] passwordFileEntries
     * @param string $importedSambaDbPath
     * @param string $exportSambaDbPath
     */
    public function importSambaUsersToSambaDb(
        array $passwordFileEntries,
        string $importedSambaDbPath,
        string $exportSambaDbPath
    ) {
        //export imported sambadb to text file
        $this->exportSambaDbToText($importedSambaDbPath, $exportSambaDbPath);
        $smbpasswdFileRepository = $this->smbpasswdFileRepositoryFactory->createFileRepository($exportSambaDbPath);
        $smbpasswdFileRepository->load();
        $this->removeUnusedImportedSambaUsers($passwordFileEntries, $smbpasswdFileRepository);
        $this->updateUserIdsFromPasswd($passwordFileEntries, $smbpasswdFileRepository);
        $smbpasswdFileRepository->save();
        $this->importTextToSambaDb($exportSambaDbPath);
    }

    /**
     * Export samba password database to a text file
     *
     * @param string $sambaDbPath
     * @param string $outputFilePath
     */
    private function exportSambaDbToText(string $sambaDbPath, string $outputFilePath)
    {
        if (!$this->filesystem->exists($sambaDbPath)) {
            throw new Exception("Remote Samba Database file $sambaDbPath is missing.  Users cant be migrated");
        }
        $process = $this->processFactory->get(['pdbedit', '-b', "tdbsam:$sambaDbPath", '-L', '-w']);
        $process->mustRun();

        if (!$this->filesystem->filePutContents($outputFilePath, $process->getOutput())) {
            throw new Exception("Unable to write samba database export to $outputFilePath, user migration not successful");
        }
    }

    /**
     * Import samba users in a text file in smbpasswd format
     *
     * @param string $smbImportTexttFilePath
     */
    private function importTextToSambaDb(string $smbImportFilePath)
    {
        if (!$this->filesystem->exists($smbImportFilePath)) {
            throw new Exception("Remote Samba Database file is missing.  Users can not be migrated");
        }
        $process = $this->processFactory->get([
                'pdbedit',
                '-i',
                "smbpasswd:$smbImportFilePath"
            ]);
        $process->run();
    }

    /**
     * remove unused imported sambaUsers from text file
     *
     * @param PasswordFileEntry[] passwordFileEntries
     * @param SmbpasswdFileRepository $exportSambaDbPath
     */
    private function removeUnusedImportedSambaUsers(array $passwordFileEntries, SmbpasswdFileRepository $smbpasswdFileRepository)
    {
        $usersToDelete = [];
        $usersToImport = $this->getUsersFromRepoFormatted($passwordFileEntries);
        foreach ($smbpasswdFileRepository->getAll() as $passwordFileEntry) {
            $user = $passwordFileEntry->getName();
            if (!in_array($user, $usersToImport)) {
                $usersToDelete[] = $user;
            }
        }
        if (count($usersToDelete) > 0) {
            $smbpasswdFileRepository->deleteUsersByName($usersToDelete);
        }
    }

    /**
     * remove unused imported sambaUsers from text file
     *
     * @param PasswordFileEntry[] passwordFileEntries
     * @param SmbpasswdFileRepository $exportSambaDbPath
     */
    private function updateUserIdsFromPasswd(array $passwordFileEntries, SmbpasswdFileRepository $smbpasswdFileRepository)
    {
        foreach ($passwordFileEntries as $passwordFileEntry) {
            /** @var PasswordFileEntry $passwordFileEntry */
            $smbpasswdFileRepository->setUidByName($passwordFileEntry->getName(), $passwordFileEntry->getUid());
        }
    }

    /**
     * @param string $output
     * @param int $fieldIndex
     * @param string $delim
     * @return string[]
     */
    private function getFields(string $output, int $fieldIndex, string $delim): array
    {
        $fields = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $parts = explode($delim, $line);

            if (isset($parts[$fieldIndex])) {
                $fields[] = $parts[$fieldIndex];
            }
        }
        return $fields;
    }

    /**
     * @return string[]
     */
    private function getIgnoreUsers(): array
    {
        return ['root', 'nobody', '', 'aurorauser', 'backup-admin'];
    }

    /**
     * @param $passwordFileEntries[]
     * @return string[]
     */
    private function getUsersFromRepoFormatted(array $passwordFileEntries): array
    {
        $usersToImport = [];
        foreach ($passwordFileEntries as $passwordFileEntry) {
            /** @var PasswordFileEntry  $passwordFileEntry */
            $usersToImport[] = $passwordFileEntry->getName();
        }
        return $usersToImport;
    }
}
