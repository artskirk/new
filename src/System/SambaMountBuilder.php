<?php

namespace Datto\System;

use Datto\Security\SecretFile;
use Datto\System\SambaShare;

/**
 * Class SambaMountBuilder
 *
 * Contains the behaviors which translate a SambaMount model object into arguments
 * suitable for being passed to the mount command.
 * todo: Support all possible mount.cifs configuration options.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class SambaMountBuilder implements MountableInterface
{
    const NTLMV1 = "ntlm";
    const NTLMV2 = "ntlmv2";
    const NTLMSSP = "ntlmssp";
    const PACKET_SIGNING_SUFFIX = "i";
    const GUEST_USERNAME = 'guest';

    const SMB_1 = "1.0";
    const SMB_2 = "2.1";

    /**
     * @var SambaMount
     */
    private $sambaMount;

    /**
     * @var SecretFile
     */
    private $secretFile;

    /**
     * @var string
     */
    private $security = self::NTLMV2;

    /**
     * @var string
     */
    private $smbVersion = self::SMB_2;

    /**
     * SambaMountBuilder constructor
     *
     * @param SambaMount $sambaMount
     * @param SecretFile|null $secretFile
     */
    public function __construct(SambaMount $sambaMount, SecretFile $secretFile = null)
    {
        $this->sambaMount = $sambaMount;
        $this->secretFile = $secretFile ?: new SecretFile();
    }

    /**
     * SambaMountBuilder destructor
     */
    public function __destruct()
    {
        if ($this->secretFile->fileExists()) {
            $this->secretFile->shred();
        }
    }

    /**
     * Selects NTLMSSP authentication
     */
    public function useSSP()
    {
        $this->security = self::NTLMSSP;
    }

    /**
     * Selects NTLM authentication
     */
    public function useV1()
    {
        $this->security = self::NTLMV1;
    }

    /**
     * Selects NTLMV2 authentication
     */
    public function useV2()
    {
        $this->security = self::NTLMV2;
    }

    /**
     * Returns the selected security option argument
     *
     * @return string
     */
    public function securityOption(): string
    {
        return $this->security;
    }

    /**
     * Sets the smb version to version 1.0
     */
    public function useSMB1()
    {
        $this->smbVersion = self::SMB_1;
    }

    /**
     * Sets the smb version to version 2.1
     */
    public function useSMB2()
    {
        $this->smbVersion = self::SMB_2;
    }

    /**
     * Gets the SMB version argument
     *
     * @return string
     */
    public function smbVersion(): string
    {
        return $this->smbVersion;
    }

    /**
     * Returns a string of the form 'credentials=/run/shm/someFilename', where the file
     * is a SMB credentials file.
     *
     * @return string
     */
    public function getCredentialClause(): string
    {
        $username = $this->sambaMount->getUsername();
        $username = ($username === null || $username === self::GUEST_USERNAME) ? self::GUEST_USERNAME : $username;
        $password = $this->sambaMount->getPassword();
        $domain = $this->sambaMount->getDomain();

        $contents = "username=$username\n";
        $contents .= "password=$password\n";
        if ($domain !== null) {
            $contents .= "domain=$domain\n";
        }
        $this->secretFile->save($contents);

        return "credentials=" . $this->secretFile->getFilename();
    }

    /**
     * Returns the mount command line arguments necessary to mount a SAMBA share
     * using the mount.cifs filesystem type.
     *
     * @return array
     */
    public function getMountArguments()
    {
        $shareNetworkPath = '//' . $this->sambaMount->getHost() . '/' . $this->sambaMount->getFolder();
        $options = $this->getCredentialClause();
        $options .= ',nodev,noexec,nosuid';  // a basic security measure applied to any non-root mount
        $options .= ',sec=' . $this->securityOption();
        $options .= ',vers=' . $this->smbVersion();

        if ($this->sambaMount->isReadOnly()) {
            $options .= ',ro';
        }

        if ($this->sambaMount->includeCifsAcls()) {
            $options .= ',cifsacl'; // store Windows ACL information in system.cifs_acl xattr
        }

        return array('-t', 'cifs', '-o', $options, $shareNetworkPath);
    }
}
