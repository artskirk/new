<?php

namespace Datto\User;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Manage local UNIX users.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UnixUserService
{
    const PASSWORD_FILE = '/etc/passwd';

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var PasswordFileRepositoryFactory */
    private $passwordFileRepositoryFactory;

    /** @var ShadowFileRepositoryFactory */
    private $shadowFileRepositoryFactory;

    /**
     * @param ProcessFactory|null $processFactory
     * @param Filesystem|null $filesystem
     * @param PasswordFileRepositoryFactory|null $passwordFileRepositoryFactory
     * @param ShadowFileRepositoryFactory|null $shadowFileRepositoryFactory
     */
    public function __construct(
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        PasswordFileRepositoryFactory $passwordFileRepositoryFactory = null,
        ShadowFileRepositoryFactory $shadowFileRepositoryFactory = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
        $this->passwordFileRepositoryFactory = $passwordFileRepositoryFactory ?: new PasswordFileRepositoryFactory($this->filesystem);
        $this->shadowFileRepositoryFactory = $shadowFileRepositoryFactory ?: new ShadowFileRepositoryFactory($this->filesystem);
    }

    /**
     * Check if a given user exists.
     *
     * @param string $username
     * @return bool
     */
    public function exists(string $username): bool
    {
        $lines = $this->filesystem->file(self::PASSWORD_FILE);

        foreach ($lines as $wholeline) {
            $linedata = explode(':', $wholeline);

            if (count($linedata) > 0 && $linedata[0] === $username) {
                return true;
            }
        }

        return false;
    }
    /**
     * Get the user id for the specified username.
     *
     * @param string $username
     * @return string
     */
    public function getUserId(string $username): string
    {
        if ($this->exists($username)) {
            $process = $this->processFactory->get([
                'id',
                '-u',
                $username
                ])
             ->mustRun();
            if ($process->getExitCode() !== 0) {
                throw new Exception("Could not get user id for user $username");
            }
            return trim($process->getOutput());
        }
    }

    /**
     * Delete the specified user.
     *
     * @param string $username
     */
    public function delete(string $username)
    {
        if ($this->exists($username)) {
            $this->processFactory->get([
                'userdel',
                $username
                ])
            ->mustRun();
        }
    }

    /**
     * Imports all normal users from another set of passwd and shadow files.
     * See "PasswordFileEntry::isNormalUser()" for the definition of "normal user".
     * WARNING: THIS DELETES ALL EXISTING NORMAL USERS BEFORE IMPORTING THE
     * NEW USERS!!!
     *
     * This function preserves imported user IDs and passwords.
     * If a conflict arises where an imported user has the same ID as an
     * existing system user, an exception will occur and no changes will be
     * made to the /etc/passwd or /etc/shadow files.
     *
     * @param string $importPasswordFile The /etc/passwd file to import
     * @param string $importShadowFile The /etc/shadow file to import
     */
    public function importNormalUsersFromFile(string $importPasswordFile, string $importShadowFile)
    {
        $importPasswordFileRepository = $this->passwordFileRepositoryFactory->createFileRepository($importPasswordFile);
        $importShadowFileRepository = $this->shadowFileRepositoryFactory->createFileRepository($importShadowFile);
        $systemPasswordFileRepository = $this->passwordFileRepositoryFactory->createSystemRepository();
        $systemShadowFileRepository = $this->shadowFileRepositoryFactory->createSystemRepository();

        $importPasswordFileRepository->load();
        $importShadowFileRepository->load();
        $systemPasswordFileRepository->load();
        $systemShadowFileRepository->load();

        $deletedUserNames = $systemPasswordFileRepository->deleteNormalUsers();
        $systemShadowFileRepository->deleteUsersByName($deletedUserNames);

        foreach ($importPasswordFileRepository->getNormalUsers() as $passwordEntry) {
            $shadowEntry = $importShadowFileRepository->getByName($passwordEntry->getName());
            if ($shadowEntry) {
                $systemPasswordFileRepository->add($passwordEntry);
                $systemShadowFileRepository->add($shadowEntry);
            }
        }

        $systemPasswordFileRepository->save();
        $systemShadowFileRepository->save();
    }

    /**
     * Adds a new user and updates the user password from imported version of shadow file
     *
     *
     * @param string $$userName The  username to create and import
     * @param string $importShadowFile The /etc/shadow file to import
     */
    public function addUserImportPassword(string $userName, string $importShadowFile)
    {
        $importShadowFileRepository = $this->shadowFileRepositoryFactory->createFileRepository($importShadowFile);
        $importShadowFileRepository->load();
        $this->processFactory->get([
                'useradd',
                '-g',
                'users',
                '-s',
                '/bin/bash',
                $userName
            ])
            ->mustRun();
        $shadowEntry = $importShadowFileRepository->getByName($userName);
        $this->processFactory->get(['chpasswd', '-e'])
            ->setInput("$userName:{$shadowEntry->getPasswordHash()}")
            ->mustRun();
    }

    /**
     *  Get a unix passwd entry object
     *
     * @param string $userName The  username to create and import
     * @return PasswordFileEntry|null
     */
    public function getPasswdEntry(string $userName)
    {
        $passwordShadowFileRepository = $this->passwordFileRepositoryFactory->createSystemRepository();
        $passwordShadowFileRepository->load();
        return $passwordShadowFileRepository->getByName($userName);
    }
}
