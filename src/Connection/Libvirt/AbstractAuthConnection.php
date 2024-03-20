<?php

namespace Datto\Connection\Libvirt;

use Datto\Common\Resource\ProcessFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\StorageBackedConnectionInterface;
use Datto\Core\Security\Cipher;
use Datto\Common\Utility\Filesystem;
use Datto\Metadata\FileAccess\FileAccess;
use Exception;

/**
 * Represents a libvirt connection that requires credentials.
 *
 * Such connections by definion require a storage, so it already implements
 * StorageBackedInterface methods for us.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
abstract class AbstractAuthConnection extends AbstractLibvirtConnection implements StorageBackedConnectionInterface
{
    private Filesystem $filesystem;
    private FileAccess $storage;
    private Cipher $cipher;

    public function __construct(
        ConnectionType $type,
        string         $name,
        ?Filesystem    $filesystem = null,
        ?Cipher        $cipher = null
    ) {
        $this->cipher = $cipher ?: new Cipher();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());

        parent::__construct($type, $name);
    }

    /**
     * @return string|null
     */
    public function getUser()
    {
        return $this->getKey('user');
    }

    public function setUser(string $user): void
    {
        $this->setKey('user', $user);
    }

    /**
     * @return string|null
     */
    public function getPassword()
    {
        return $this->getKey('password');
    }

    public function setPassword(string $password): void
    {
        $this->setKey('password', $password);
    }

    /**
     * Refactor note:
     * Should be an array or empty array only but no time to check calls
     */
    public function getCredentials(): ?array
    {
        $user = $this->getKey('user');
        $pass = $this->getKey('password');

        if ($user && $pass) {
            return [
                VIR_CRED_AUTHNAME => $user,
                VIR_CRED_PASSPHRASE => $pass,
            ];
        }
        return null;
    }

    public function loadData()
    {
        /**
         * @var FileAccess
         */
        $storage = $this->getStorageBackend();

        if (null === $storage) {
            throw new Exception('Storage backend not set for connection.');
        }

        $data = $storage->getContents();

        if (!empty($data['password'])) {
            $pass = $this->cipher->decrypt($data['password']);
            $data['password'] = $pass;
        }

        $this->connectionData = $data;
    }

    /**
     * {@inheritdoc}
     */
    public function saveData()
    {
        $storage = $this->getStorageBackend();

        if (null === $storage) {
            throw new Exception('Storage backend not set for connection.');
        }

        $data = $this->connectionData;
        $pass = $this->getKey('password');
        $data['password'] = $this->cipher->encrypt($pass);

        $storage->setContents($data);

        return $storage->save();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteData()
    {
        $this->connectionData = array();
        $storage = $this->getStorageBackend();
        if (null !== $storage) {
            @$this->filesystem->unlink(sprintf(
                '%s/%s',
                $storage->getPath(),
                $storage->getFile()
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStorageBackend()
    {
        $name = $this->getName();
        if (empty($name)) {
            throw new Exception(
                'The connection name must be set to read/write from storage'
            );
        }

        $this->storage->setFile(sprintf(
            '%s.%s',
            $this->getName(),
            $this->getType()->value()
        ));

        return $this->storage;
    }

    /**
     * {@inheritdoc}
     */
    public function setStorageBackend(FileAccess $storageBackend)
    {
        $this->storage = $storageBackend;
    }

    /**
     * @return array connection data as an array
     */
    public function toArray(): array
    {
        $connectionParams = $this->connectionData;

        // Translate our connectionParams array to match what our setCorrectionParams method (formatted for UI) expects
        $connectionParams['username'] = $connectionParams['user'];

        return [
            'type' => $this->connectionType->value(),
            'name' => $this->getName(),
            'connectionParams' => $connectionParams
        ];
    }
}
