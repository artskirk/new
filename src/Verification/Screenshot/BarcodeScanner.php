<?php

namespace Datto\Verification\Screenshot;

use Datto\Common\Resource\ProcessFactory;

/**
 * Light wrapper around zbarimg.
 * Used to scan & extract barcode data from images.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class BarcodeScanner
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Scans the image for a barcode code and, if found,
     * returns the data contained within it.
     *
     * @param string $imagePath
     * @return string
     */
    public function scan(string $imagePath): string
    {
        $process = $this->processFactory->get(['zbarimg', '--quiet', '--raw', $imagePath]);
        $process->run();

        return $process->getOutput();
    }
}
