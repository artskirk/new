<?php

namespace Datto\Asset;

use Datto\Asset\Agent\Serializer\SecuritySettingsSerializer;
use Datto\Common\Resource\ProcessFactory;
use Datto\Samba\SambaShare;
use Datto\Common\Utility\Filesystem;

/**
 * Class to encapsulate reading and setting the Samba share authentication used
 * by agents and a subset of shares for file restores.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SambaShareAuthentication
{
    /** @var Filesystem */
    private $fileSystem;

    /** @var SecuritySettingsSerializer */
    private $serializer;

    /**
     * @param Filesystem|null $fileSystem
     * @param SecuritySettingsSerializer $serializer
     */
    public function __construct(
        Filesystem $fileSystem = null,
        SecuritySettingsSerializer $serializer = null
    ) {
        $this->fileSystem = $fileSystem ?: new Filesystem(new ProcessFactory());
        $this->serializer = $serializer ?: new SecuritySettingsSerializer();
    }

    /**
     * Sets the asset's configured authentication, if any, on the given Samba share.
     *
     * @param string $assetName
     * @param SambaShare $share
     */
    public function setAuthenticationOptions($assetName, SambaShare $share): void
    {
        $shareProperties = array(
            'guest ok' => 'yes',
        );

        $securitySettings = null;
        $shareAuthFile = '/datto/config/keys/' . $assetName . '.shareAuth';

        if ($this->fileSystem->exists($shareAuthFile)) {
            $data = array('shareAuth' => $this->fileSystem->fileGetContents($shareAuthFile));
            $securitySettings = $this->serializer->unserialize($data);
        }

        if ($securitySettings && $securitySettings->getUser()) {
            $shareProperties['public'] = 'no';
            $shareProperties['guest ok'] = 'no';
            $shareProperties['valid users'] = $securitySettings->getUser();
            $shareProperties['admin users'] = '';
        }

        $share->setProperties($shareProperties);
    }
}
