<?php

namespace Datto\Config\RepairTask;

use Datto\Common\Resource\Filesystem;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Remove all instances of graphics "mux" type in all virsh xml files, since we removed the rdpmux library
 *
 * @author mblakley@datto.com
 */
class RemoveRdpMuxXmlTask implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const VIRSH_XML_PATH = '/etc/libvirt/qemu/';
    const FILENAME_LOG_KEY = 'filename';

    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Loop over each xml file in /etc/libvirt/qemu
     * If type=mux, we need to get rid of that graphics child node
     * also, remove the file in the authFile attribute
     * Example xml:
     * <domain ...>
     *  ...
     *  <graphics type="mux" dbusObj="org.RDPMux.RDPMux" dbusPath="/org/RDPMux/RDPMux" authFile="/homePool/1dbd0a71428f46028ffcd5b5262fb22a-active/rdpmux.authFile"></graphics>
     *  ...
     * </domain>
     */
    public function run(): bool
    {
        $configChanged = false;
        $virshXmlFiles = $this->filesystem->glob(self::VIRSH_XML_PATH . '*.xml');

        foreach ($virshXmlFiles as $virshXmlFile) {
            try {
                $virshXmlContents = simplexml_load_string($this->filesystem->fileGetContents($virshXmlFile));
                if (count($virshXmlContents->xpath("//graphics[@type='mux']")) > 0) {
                    $authFile = (string)$virshXmlContents->xpath("//graphics[@type='mux']")[0]['authFile'];
                    $passFile = preg_replace('/authFile$/', 'pass', $authFile);
                    $filesToRemove = [$authFile, $passFile];
                    foreach ($filesToRemove as $fileToRemove) {
                        $this->filesystem->unlinkIfExists($fileToRemove);
                    }

                    unset($virshXmlContents->xpath("//graphics[@type='mux']")[0][0]);
                    $xmlFileWritten = $this->filesystem->filePutContents($virshXmlFile, $virshXmlContents->asXML());
                    $configChanged = $configChanged || $xmlFileWritten;
                    $this->logger->info(
                        'CFG0016 Removing rdpmux from virsh xml and removing auth/pass files',
                        [
                            self::FILENAME_LOG_KEY => $virshXmlFile,
                            'xmlUpdateSucceeded' => $xmlFileWritten,
                            'removedFiles' => $filesToRemove
                        ]
                    );
                }
            } catch (Throwable $t) {
                $this->logger->error(
                    'CFG0026 Error removing rdpmux from virsh xml file',
                    [self::FILENAME_LOG_KEY => $virshXmlFile, 'error' => $t->getMessage()]
                );
            }
        }

        return $configChanged;
    }
}
