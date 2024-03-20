<?php

namespace Datto\Asset\Share\Serializer;

use Datto\Asset\Serializer\LocalSerializer;
use Datto\Asset\Serializer\OffsiteSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Share;
use Exception;

/**
 * Serialize and unserialize a Share object into an array.
 *
 * Unserializing:
 *   $share = $serializer->unserialize(array(
 *       'name' => 'myNasShare',
 *       // A lot more ...
 *   ));
 *
 * Serializing:
 *   $serializedShare = $serializer->serialize(new NasShare(..));
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ShareSerializer implements Serializer
{
    private LocalSerializer $localSerializer;
    private OffsiteSerializer $offsiteSerializer;

    public function __construct(
        LocalSerializer $localSerializer = null,
        OffsiteSerializer $offsiteSerializer = null
    ) {
        $this->localSerializer = $localSerializer ?? new LocalSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new OffsiteSerializer();
    }

    /**
     * Serialize a share into an array of file contents.
     *
     * @param Share $share
     * @return array
     */
    public function serialize($share)
    {
        $serializedShare = [
            'name' => $share->getName(),
            'uuid' => $share->getUuid(),
            'keyName' => $share->getKeyName(),
            'displayName' => $share->getDisplayName(),
            'type' => $share->getType(),
            'local' => $this->localSerializer->serialize($share->getLocal()),
            'offsite' => $this->offsiteSerializer->serialize($share->getOffsite()),
            'reporting' => [
                'critical' => [
                    'emails' => $share->getEmailAddresses()->getCritical(),
                ],
                'warning' => [
                    'emails' => $share->getEmailAddresses()->getWarning(),
                ],
                'log' => [
                    'emails' => $share->getEmailAddresses()->getLog(),
                ]
            ]
        ];

        if ($share instanceof NasShare) {
            return array_merge_recursive($serializedShare, $this->serializeNasShare($share));
        } elseif ($share instanceof IscsiShare) {
            return array_merge_recursive($serializedShare, $this->serializeIscsiShare($share));
        } elseif ($share instanceof ExternalNasShare) {
            return array_merge_recursive($serializedShare, $this->serializeExternalNasShare($share));
        } else {
            return $serializedShare;
        }
    }

    /**
     * Unserializes the share from an array.
     *
     * @param array $serializedShare
     * @return Share Instance of a NasShare or IscsiShare object
     */
    public function unserialize($serializedShare)
    {
        throw new Exception('Not implemented');
    }

    private function serializeNasShare(NasShare $share): array
    {
        return [
            'access' => [
                'level' => $share->getAccess()->getLevel(),
                'writeLevel' => $share->getAccess()->getWriteLevel(),
                'authorizedUser' => $share->getAccess()->getAuthorizedUser()
            ],
            'users' => [
                'all' => $share->getUsers()->getAll(),
                'admin' => $share->getUsers()->getAdminUsers()
            ],
            'reporting' => [
                'growth' => [
                    'emails' => $share->getGrowthReport()->getEmailList(),
                    'frequency' => $share->getGrowthReport()->getFrequency()
                ]
            ]
        ];
    }

    private function serializeIscsiShare(IscsiShare $share): array
    {
        $chapSettings = $share->getChap();
        $authentication = $chapSettings->getAuthentication();

        return [
            'chap' => [
                'enabled' => $authentication !== ChapSettings::CHAP_DISABLED,
                'username' => $chapSettings->getUser(),
                'mutual' => [
                    'enabled' => $authentication === ChapSettings::CHAP_MUTUAL,
                    'username' => $chapSettings->getMutualUser()
                ]
            ]
        ];
    }

    private function serializeExternalNasShare(ExternalNasShare $share): array
    {
        return [
            'host' => $share->getSambaMount()->getHost(),
            'folder' => $share->getSambaMount()->getFolder(),
        ];
    }
}
