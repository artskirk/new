<?php

namespace Datto\Samba;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * SambaShare object extends the Section but contains attributes that only shares have. These
 * added properties will allow us to further validate a share section before applying changes.
 *
 * @author Evan Buther <evan.buther@dattobackup.com>
 */
class SambaShare extends SambaSection
{
    /** @var UserService */
    private $service;

    /** @var Filesystem */
    private $filesystem;

    /** @var string|null  The full path to the shared directory */
    public $path = null;

    /** @var array  An array of valid users */
    public $validUsers = [];

    /** @var array  An array of admin users */
    public $adminUsers = [];

    /** @var bool  Whether or not the share is public */
    public $isPublic = false;

    /**
     * @var string  current ACL mode
     *     no_acl: 777 - user rw
     *     acl_and_mask: 755 - user ro
     *     acl_only: No Linux masks
     *     custom:  customer configuration
     */
    public $aclMode = null;

    /**
     * @param string|null $shareName
     * @param UserService|null $service
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        string $shareName = null,
        UserService $service = null,
        Filesystem $filesystem = null
    ) {
        parent::__construct($shareName);

        $this->service = $service ?: new UserService();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Sets and validates the path of the share
     *
     * @param string $path The full path of the directory to be shared
     */
    public function setPath(string $path)
    {
        if (!$this->filesystem->exists($path)) {
            throw new Exception("The path: '$path' does not exist.");
        }

        $this->setProperty('path', $path);
    }

    /**
     * Overloads the section load and sets share properties
     *
     * @param string $sectionName The name of the section being loaded
     * @param string $sectionString The raw string from the configuration file containing the section definition
     * @return bool
     */
    public function load(string $sectionName, string $sectionString): bool
    {
        parent::load($sectionName, $sectionString);
        $this->setShareProperties();
        return true;
    }

    /**
     * Overloads the section setProperty and sets share properties
     *
     * @param string $propertyKey The key of the property to be set
     * @param mixed $propertyValue The value of the property to be set
     * @return bool  Whether or not the property was assigned
     */
    public function setProperty(string $propertyKey, $propertyValue): bool
    {
        parent::setProperty($propertyKey, $propertyValue);
        $this->setShareProperties();
        return true;
    }

    /**
     * Properly assigns the values of share properties
     */
    private function setShareProperties()
    {
        $this->path = $this->getProperty('path');

        // Assign valid users
        if ($this->getProperty('valid users') != null) {
            // when loading valid users, filter out "special" cases.
            $ignoreUserList = array('root', 'nobody', '', 'aurorauser');
            $rawUsers = $this->decapsulateUserEntries(explode(' ', $this->getProperty('valid users')));
            $this->validUsers = array_values(array_diff($rawUsers, $ignoreUserList));
        } else {
            $this->validUsers = array();
        }

        // Assign admin users
        if ($this->getProperty('admin users') != null) {
            $this->adminUsers = $this->decapsulateUserEntries(explode(' ', $this->getProperty('admin users')));
        } else {
            $this->adminUsers = array();
        }

        // Assign public
        $this->isPublic = $this->getProperty('guest ok') != null && $this->getProperty('guest ok') === 'yes';

        // Assign aclMode
        $create_mask = $this->getProperty('create mask');
        $force_create_mode = $this->getProperty('force create mode');
        $security_mask = $this->getProperty('security mask');
        $force_security_mode = $this->getProperty('force security mode');
        $directory_mask = $this->getProperty('directory mask');
        $force_directory_mode = $this->getProperty('force directory mode');
        $directory_security_mask = $this->getProperty('directory security mask');
        $force_directory_security_mode = $this->getProperty('force directory security mode');

        if ($create_mask === '0777' && $force_create_mode === '0777' && $security_mask === '2777' &&
            $force_security_mode === '0000' && $directory_mask === '2777' && $force_directory_mode === '2777' &&
            $directory_security_mask === '2777' && $force_directory_security_mode === '0000'
        ) {
            $this->aclMode = 'no_acl';
        } elseif ($create_mask == '0755' && $force_create_mode === '0755' && $security_mask === '2777' &&
            $force_security_mode === '0000' && $directory_mask === '0755' && $force_directory_mode === '0755' &&
            $directory_security_mask === '2777' && $force_directory_security_mode === '0000'
        ) {
            $this->aclMode = 'acl_and_mask';
        } elseif (!$this->isPropertySet('create mask') && !$this->isPropertySet('force create mode') && !$this->isPropertySet('security mask') &&
            !$this->isPropertySet('force security mode') && !$this->isPropertySet('directory mask') && !$this->isPropertySet('force directory mode') &&
            !$this->isPropertySet('directory security mask') && !$this->isPropertySet('force directory security mode')
        ) {
            $this->aclMode = 'acl_only';
        } else {
            $this->aclMode = 'custom';
        }
    }

    /**
     * Returns whether or not the share is public, i.e. anonymous users can access it
     *
     * @return bool True if public, false otherwise
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Returns the list of valid users
     *
     * @return array  Array of valid users
     */
    public function getValidUsers(): array
    {
        return $this->validUsers;
    }

    /**
     * Returns the list of admin users
     *
     * @return array  Array of admin users
     */
    public function getAdminUsers(): array
    {
        return $this->adminUsers;
    }

    /**
     * Returns a list of all associated users
     *
     * @return array
     */
    public function getAllUsers(): array
    {
        return array_unique(array_merge($this->getValidUsers(), $this->getAdminUsers()));
    }

    /**
     * Returns a list of all associated groups
     *
     * @return array
     */
    public function getAllGroups(): array
    {
        $groups = $this->service->getDomainGroups();
        $users = $this->getAllUsers();

        return array_intersect($groups, $users);
    }

    /**
     * Applies share properties before overloading section output
     *
     * @return string  The configuration output
     */
    public function confOutput(): string
    {
        // since setPropery calls setShareProperties, it will destroy isPublic flag,
        // so we need to preserve it here...
        $isPublic = $this->isPublic;

        $this->setProperty('path', $this->path);

        // if the share is private and no valid users were specified,
        // we still need to set valid users = nobody so that UNIX users
        // don't have access.
        //If the share is public, do not assign any valid users so that users
        //logged into private shares may also log into public shares at the same time
        //provided they log into the private share first.
        if ($isPublic) {
            $this->setProperty('valid users', null);
        } elseif (empty($this->validUsers)) {
            $this->setProperty('valid users', 'nobody');
        } else {
            $this->setProperty('valid users', implode(' ', $this->encapsulateUserEntries($this->validUsers)));
        }

        $this->setProperty('admin users', implode(' ', $this->encapsulateUserEntries($this->adminUsers)));
        $this->setProperty('veto files', '/lost+found/.locate.db');
        $this->setProperty('dfree command', '/datto/bin/dfree-runner');

        //Restore isPublic flag.
        $this->isPublic = $isPublic;
        $shareString = parent::confOutput();

        return $shareString;
    }

    /**
     * Add a user to this share
     *
     * @param string $username Username of the user to add
     * @param bool $asAdmin Whether or not they have admin privelages
     * @return bool Whether or not the user was added
     */
    public function addUser(string $username, bool $asAdmin = false): bool
    {
        $userAdded = false;

        if ($this->service->isValidUser($username)) {
            if (!in_array($username, $this->validUsers)) {
                $this->validUsers[] = $username;
                $this->setProperty('valid users', implode(' ', $this->encapsulateUserEntries($this->validUsers)));
                $userAdded = true;
            }

            if ($asAdmin && !in_array($username, $this->adminUsers)) {
                $this->adminUsers[] = $username;
                $this->setProperty('admin users', implode(' ', $this->encapsulateUserEntries($this->adminUsers)));
                $userAdded = true;
            }
        }

        return $userAdded;
    }

    /**
     * Change aclMode.  See class public var SambaShare->aclMode for description
     *
     * @param string $newACLlMode See class public var SambaShare->aclMode for description
     * @return bool  Whether or not there were errors
     */
    public function changeACLMode(string $newACLlMode): bool
    {
        $success = true;
        switch ($newACLlMode) {
            case 'no_acl':
                $success &= $this->setProperty('create mask', '0777');
                $success &= $this->setProperty('force create mode', '0777');
                $success &= $this->setProperty('security mask', '2777');
                $success &= $this->setProperty('force security mode', '0000');
                $success &= $this->setProperty('directory mask', '2777');
                $success &= $this->setProperty('force directory mode', '2777');
                $success &= $this->setProperty('directory security mask', '2777');
                $success &= $this->setProperty('force directory security mode', '0000');
                $success &= $this->setProperty('nt acl support', 'yes');
                break;
            case 'acl_and_mask':
                $success &= $this->setProperty('create mask', '0755');
                $success &= $this->setProperty('force create mode', '0755');
                $success &= $this->setProperty('security mask', '2777');
                $success &= $this->setProperty('force security mode', '0000');
                $success &= $this->setProperty('directory mask', '0755');
                $success &= $this->setProperty('force directory mode', '0755');
                $success &= $this->setProperty('directory security mask', '2777');
                $success &= $this->setProperty('force directory security mode', '0000');
                $success &= $this->setProperty('nt acl support', 'yes');
                break;
            case 'acl_only':
                if ($this->isPropertySet('create mask')) {
                    $success &= $this->removeProperty('create mask');
                }
                if ($this->isPropertySet('force create mode')) {
                    $success &= $this->removeProperty('force create mode');
                }
                if ($this->isPropertySet('security mask')) {
                    $success &= $this->removeProperty('security mask');
                }
                if ($this->isPropertySet('force security mode')) {
                    $success &= $this->removeProperty('force security mode');
                }
                if ($this->isPropertySet('directory mask')) {
                    $success &= $this->removeProperty('directory mask');
                }
                if ($this->isPropertySet('force directory mode')) {
                    $success &= $this->removeProperty('force directory mode');
                }
                if ($this->isPropertySet('directory security mask')) {
                    $success &= $this->removeProperty('directory security mask');
                }
                if ($this->isPropertySet('force directory security mode')) {
                    $success &= $this->removeProperty('force directory security mode');
                }
                $this->setShareProperties();
                break;
            default:
                $success = false;
                break;
        }

        return $success;
    }

    /**
     * Removes a user or group from the share.
     *
     * @param string $username The user or group to be removed
     * @return bool  Whether or not the user was removed (might not have been if not assigned)
     */
    public function removeUser(string $username): bool
    {
        $userRemoved = false;

        $isValid = array_diff($this->validUsers, [$username]);
        $isAdmin = array_diff($this->adminUsers, [$username]);

        if (count($isValid) < count($this->validUsers)) {
            $this->setProperty('valid users', implode(' ', $this->encapsulateUserEntries($isValid)));
            $userRemoved = true;
        }

        if (count($isAdmin) < count($this->adminUsers)) {
            $this->setProperty('admin users', implode(' ', $this->encapsulateUserEntries($isAdmin)));
            $userRemoved = true;
        }

        return $userRemoved;
    }

    /**
     * Makes the share public
     *
     * @return bool
     */
    public function makePublic(): bool
    {
        if (!$this->isPublic) {
            $propertiesToSet = [
                'valid users' => null,  // remove valid users
                'admin users' => null,  // remove admin users
                'guest ok' => 'yes', // guest ok
                'read only' => 'no'   //
            ];

            if ($this->setProperties($propertiesToSet)) {
                $this->isPublic = true;
            }
            //Change initial User read/write permissions to read-write
            // and change existing files to rw access for all users
            if ($this->changeACLMode('no_acl') && !empty($this->path)) {
                $this->filesystem->chmod($this->path, 0777, true);
            }
        }

        return $this->isPublic;
    }

    /**
     * Makes the share private
     *
     * @return bool
     */
    public function makePrivate(): bool
    {
        if ($this->isPublic) {
            $propertiesToSet = [
                'valid users' => null,  // remove valid users
                'admin users' => null,  // remove admin users
                'guest ok' => null, // guest ok
                'read only' => 'no'   //
            ];

            if ($this->setProperties($propertiesToSet)) {
                $this->isPublic = false;
            }
            //Change initial User read/write pemissions to read-only
            $this->changeACLMode('acl_and_mask');
        }

        return $this->isPublic;
    }

    /**
     * Sets the access level for this share
     *
     * @param string $level Public or private access level
     */
    public function setAccess(string $level)
    {
        if (!in_array($level, ['public', 'private'])) {
            throw new \InvalidArgumentException('access level must be public or private');
        }

        switch ($level) {
            case 'public':
                $this->makePublic();
                break;
            case 'private':
                $this->makePrivate();
                break;
        }
    }

    /**
     * Returns the path of the directory being shared
     *
     * @return null|string  The path of the directory being shared or null
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Returns an array of user entries without quotes
     *
     * Note: decapsulate is a word, google it! ;)
     *
     * Note2: "pieces" in this case are multiple array elements that make up a user entity
     *
     * For example:
     * - [0] => '@"DATTO\cloud'
     * - [1] => 'ops'
     * - [2] => 'dept"'
     *
     * ...all three of these elements make up an entity @"DATTO\cloud ops dept"
     *
     * @param array $userEntries Array of valid user entry (pieces or whole)
     * @return array  The array of users without quotes
     */
    private function decapsulateUserEntries(array $userEntries): array
    {
        $fullUser = '';
        $allUsers = [];
        $inPieces = false;

        foreach ($userEntries as $validPiece) {
            if (strpos($validPiece, '"') !== false && !$inPieces) {
                $fullUser = $validPiece;
                $inPieces = true;
                continue;
            } elseif ($inPieces) {
                $fullUser .= ' ' . $validPiece;
            } else {
                $allUsers[] = $validPiece;
                continue;
            }

            if (strpos($validPiece, '"') !== false) {
                $allUsers[] = str_replace('"', '', $fullUser);
                $fullUser = '';
                $inPieces = false;
            }
        }

        return $allUsers;
    }

    /**
     * Returns an array of user entries with quotes
     *
     * @param array $userEntries An array of user entries without quotes
     * @return array  An array of user entries with quotes
     */
    private function encapsulateUserEntries(array $userEntries): array
    {
        $allUsers = [];

        foreach ($userEntries as $validEntry) {
            if (strpos($validEntry, ' ') !== false) {
                if (substr($validEntry, 0, 1) == '@') {
                    $allUsers[] = '@"' . substr($validEntry, 1) . '"';
                } else {
                    $allUsers[] = '"' . $validEntry . '"';
                }
            } else {
                $allUsers[] = $validEntry;
            }
        }

        return $allUsers;
    }

    /**
     * @param string $group
     * @return bool
     */
    public function addGroup(string $group): bool
    {
        $group = '@' . $group;

        if ($this->service->isValidGroup($group)) {
            if (!in_array($group, $this->validUsers)) {
                $this->validUsers[] = $group;
                return $this->setProperty('valid users', implode(' ', $this->encapsulateUserEntries($this->validUsers)));
            }
        }

        return false;
    }

    /**
     * @param string $group
     * @return bool
     */
    public function removeGroup(string $group): bool
    {
        $group = '@' . $group;
        $isValid = array_diff($this->validUsers, [$group]);

        if (count($isValid) < count($this->validUsers)) {
            return $this->setProperty('valid users', implode(' ', $this->encapsulateUserEntries($isValid)));
        }
        return false;
    }
}
