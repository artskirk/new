<?php

namespace Datto\Asset\Share\ExternalNas\Serializer;

use Datto\Asset\Share\ExternalNas\BackupProgress;
use Datto\Asset\Share\ExternalNas\BackupStatusType;

/**
 * Serialize and deserialize backup progress objects
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class BackupProgressSerializer
{
    /**
     * Serialize a BackupProgress object.  This uses the same format as the snapWebState serialization because it uses
     * the same file, but we only use the extra data.
     *
     * @param BackupProgress $progress
     * @return string
     */
    public function serialize(BackupProgress $progress)
    {
        $progressData = array(
            'status' => $progress->getStatus()->value(),
            'transferred' => $progress->getBytesTransferred(),
            'rate' => $progress->getTransferRate(),
        );
        $data = array (
            'state'   => 'rsyncing',
            'data'    => $progressData,
            'md5'     => '',
            'started' => '',
        );
        return json_encode($data);
    }
    
    /**
     * Unserialize a string into a BackupProgress object.
     *
     * @param string $fileData
     * @return BackupProgress
     */
    public function unserialize($fileData)
    {
        $status = BackupStatusType::IDLE();
        $transferred = 0;
        $rate = '';
        
        $data = json_decode($fileData, true);
        $progressData = isset($data['data']) ? $data['data'] : null;
        if (is_array($progressData)) {
            $status = BackupStatusType::memberByValue((int)@$progressData['status']);
            $transferred = @(int)$progressData['transferred'];
            $rate = @(string)($progressData['rate']);
        }
        
        return new BackupProgress($status, $transferred, $rate);
    }
}
