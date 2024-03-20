<?php
namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\SecuritySettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Serializer for secure file restore and export settings
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class SecuritySettingsSerializer implements Serializer
{
    /**
     * @param SecuritySettings $securitySettings
     * @return array
     */
    public function serialize($securitySettings)
    {
        $shareAuth = array(
            'shareAuth' => null
        );

        $user = $securitySettings->getUser();
        if (!empty($user)) {
            $securitySettingsArray = array(
                'user' => $user
            );

            $shareAuth = array(
                'shareAuth' => serialize($securitySettingsArray),
            );
        }
        return $shareAuth;
    }

    /**
     * @param array $fileArray containusg serialied shareAuth
     * @return SecuritySettings
     */
    public function unserialize($fileArray)
    {
        $user = '';
        if (isset($fileArray['shareAuth'])) {
            $data = unserialize($fileArray['shareAuth'], ['allowed_classes' => false]);
            $user = $data['user'];
        }
        $shareAuth = new SecuritySettings(
            $user
        );
        return $shareAuth;
    }
}
