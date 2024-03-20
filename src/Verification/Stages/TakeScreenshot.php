<?php

namespace Datto\Verification\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Connection\ConnectionType;
use Datto\Feature\FeatureService;
use Datto\System\Transaction\TransactionException;
use Datto\Common\Utility\Filesystem;
use Datto\Util\OsFamily;
use Datto\Common\Resource\Sleep;
use Datto\Verification\Notification\VerificationResults;
use Datto\Verification\Screenshot\BarcodeScanner;
use Datto\Verification\Screenshot\BitmapAnalyzer;
use Datto\Verification\Screenshot\OcrConverter;
use Datto\Verification\Screenshot\TextAnalyzer;
use Datto\Verification\VerificationResultType;
use Datto\Virtualization\VirtualMachine;
use Exception;
use Throwable;

/**
 * Take a screenshot.
 *
 * Logs messages with the VER prefix in the range 0500-0599.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class TakeScreenshot extends VerificationStage
{
    /**
     * The detail name for analysis results, used by VerificationResults.
     */
    const DETAILS_ANALYSIS_RESULT = 'AnalysisResult';

    /** @var BitmapAnalyzer */
    private $bitmapAnalyzer;

    /** @var OcrConverter */
    private $ocrConverter;

    /** @var TextAnalyzer */
    private $textAnalyzer;

    /** @var Filesystem */
    private $filesystem;

    /** @var Sleep */
    private $sleep;

    /** @var BarcodeScanner */
    private $barcodeScanner;

    private $featureService;

    public function __construct(
        BitmapAnalyzer $bitmapAnalyzer,
        OcrConverter $ocrConverter,
        TextAnalyzer $textAnalyzer,
        Filesystem $filesystem,
        Sleep $sleep,
        BarcodeScanner $barcodeScanner,
        FeatureService $featureService
    ) {
        $this->bitmapAnalyzer = $bitmapAnalyzer;
        $this->ocrConverter = $ocrConverter;
        $this->textAnalyzer = $textAnalyzer;
        $this->filesystem = $filesystem;
        $this->sleep = $sleep;
        $this->barcodeScanner = $barcodeScanner;
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        if ($this->context->getOsUpdatePending()
            && $this->featureService->isSupported(FeatureService::FEATURE_SKIP_VERIFICATION)) {
            $this->setResult(VerificationResultType::SKIPPED());
            return;
        }

        $failureState = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;

        try {
            $agent = $this->context->getAgent();
            $this->logger->debug('VER0500 Screenshot requested for ' . $agent->getKeyName());

            if (is_null($this->context->getVirtualMachine())) {
                throw new \RuntimeException("Expected VirtualMachine to be instantiated.");
            }

            // Loop until we get a screenshot.
            $failure = false;
            while (true) {
                try {
                    $this->logger->debug('VER0501 Taking screenshot.');
                    $this->takeScreenshot();
                    $this->logger->debug('VER0502 Screenshot captured.');
                } catch (Throwable $e) {
                    // Fail and requeue if the hypervisor fails to obtain a screenshot.
                    $this->logger->error(
                        'VER0505 Failed to take VM screenshot',
                        ['exception' => $e, 'agentKey' => $agent->getKeyName()]
                    );
                    $screenshotError = sprintf('Failed to take VM screenshot: %s', $e->getMessage());
                    $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $screenshotError);
                    break;
                }

                // Check for unwanted situations...
                $this->logger->debug('VER0503 Checking for unwanted situations.');
                $failure = $this->checkIfKnownFailure($agent);
                if ($failure) {
                    break; // We are confident this is a failure, don't bother checking any more screenshots
                }

                $unwanted = $this->checkIfUnwanted($agent);
                if ($unwanted && $this->context->getReadyTimeout() > 0) {
                    $this->sleep->sleep(5);
                    $this->context->setReadyTimeout($this->context->getReadyTimeout() - 5);
                    continue;
                }

                $this->logger->debug('VER0504 Passed checking for unwanted situations.');
                break;
            }

            // Failure was detected or we timed out while waiting for the GUI to show up.
            // Do pixel processing and OCR on the screenshot to make sure it is truly a fail.
            if ($failure || $this->context->getReadyTimeout() <= 0) {
                $failureState = $this->analyzeFailure($agent);
            } else {
                $this->logger->debug('VER0519 Skipping OCR/FailureAnalysis. Assume the screenshot is good');
            }
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        if (!$this->result) {
            $this->setResult(VerificationResultType::SUCCESS());

            // A failure state message is not considered a verification process error.
            // However, we would like to capture it for notification purposes.
            if ($failureState) {
                $resultDetails = $this->getResult()->getDetails();
                $resultDetails->setDetail(static::DETAILS_ANALYSIS_RESULT, $failureState);
            }
        }

        if (!$this->result->didSucceed()) {
            throw new TransactionException('Take screenshot failed. Error message: ' . $this->result->getErrorMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        $result = true;

        if (isset($this->result) &&
            $this->result->getResultType() === VerificationResultType::FAILURE_INTERMITTENT()) {
            $extensions = ['.png', '.jpg', '.txt'];
            foreach ($extensions as $extension) {
                $file = $this->context->getScreenshotPath() . $extension;
                if ($this->filesystem->exists($file)) {
                    $result = $this->filesystem->unlink($file) && $result;
                }
            }
        }

        if (!$result) {
            throw new TransactionException('Take screenshot clean up failed.');
        }
    }

    /**
     * Take a screenshot.
     *
     * Presses control and waits two seconds prior to taking the screenshot to:
     *  - display the login screen on Windows 8
     *  - stop any screensavers that may have started
     *  - activate the display if power saving has kicked in
     *
     */
    private function takeScreenshot()
    {
        $vm = $this->context->getVirtualMachine();

        // In case there's a splash screen or the screensaver started, press control.
        // This function does not appear to work for ESX hypervisors
        if ($this->context->getConnection()->getType() !== ConnectionType::LIBVIRT_ESX()) {
            $vm->sendKeyCodes(VirtualMachine::KEYS_CTRL);
        }

        // Wait for the screen to stabilize after pressing control.
        // For example, this ensures that any login screen animation has completed.
        $this->sleep->sleep(5);

        $vm->saveScreenshotJpeg($this->context->getScreenshotImagePath());
    }

    /**
     * Check a screenshot for unwanted situations.
     *
     * For Windows agents, we check if the corners are blank for weird reasons
     * documented in the BitmapAnalyzer class.
     *
     * For Linux agents, we check if the whole screenshot is blank.
     *
     * @param Agent $agent
     *   The agent whose screenshot is being checked for unwanted situations.
     *
     * @return bool
     *   TRUE if the screenshot shows an unwanted situation, otherwise FALSE.
     */
    private function checkIfUnwanted(Agent $agent)
    {
        $unwanted = false;
        if ($agent->getOperatingSystem()->getOsFamily() === OsFamily::WINDOWS) {
            if ($this->bitmapAnalyzer->areCornersBlank($this->context->getScreenshotImagePath())) {
                // Delete the useless black screenshot.
                $this->filesystem->unlink($this->context->getScreenshotImagePath());
                $this->logger->debug('VER0507 Fail. Waiting for Windows to reach the GUI.');
                $unwanted = true;
            }
        } elseif ($agent->getOperatingSystem()->getOsFamily() === OsFamily::LINUX) {
            try {
                if ($this->bitmapAnalyzer->isBlank($this->context->getScreenshotImagePath())) {
                    $this->logger->debug('VER0508 Fail. The screen is blank. Waiting for something to be displayed.');
                    $unwanted = true;
                }
            } catch (Exception $e) {
                $this->logger->critical(
                    'VER0515 Caught exception: ',
                    ['exception' => $e]
                );
                $this->logger->critical(
                    'VER0509 Fail. Unable to read screenshot image file. Caught exception: ',
                    ['exception' => $e]
                );
                $unwanted = true;
            }
        }

        return $unwanted;
    }

    /**
     * Check for screenshots that we know with a high degree of confidence are failures
     *
     * @param Agent $agent
     * @return bool True if it is a failure, false if we don't know
     */
    private function checkIfKnownFailure(Agent $agent): bool
    {
        $isWindows = $agent->getOperatingSystem()->getOsFamily() === OsFamily::WINDOWS;

        if ($isWindows && $this->screenshotContainsBsodQrCode()) {
            $this->logger->debug('VER0514 Fail. Blue screen of death detected.');
            return true;
        }
        return false;
    }

    /**
     * Analyze the screenshot for failure states.
     *
     * @param $agent
     *   The agent whose screenshot is being analyzed.
     *
     * @return string|null
     *   A description of the failure state detected in the screenshot.
     *   `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if the screenshot is good.
     */
    private function analyzeFailure(Agent $agent)
    {
        $analysis = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;

        $isLinux = $agent->getOperatingSystem()->getOsFamily() === OsFamily::LINUX;
        if ($isLinux) {
            $analysis = 'Timed out while waiting for the OS to boot.';
            $this->logger->warning('VER0510 ' . $analysis);
            $this->context->setScreenshotFailed();
            $this->filesystem->filePutContents($this->context->getScreenshotErrorTextPath(), $analysis);
        } elseif ($this->bitmapAnalyzer->isBlank($this->context->getScreenshotImagePath())) {
            // Solid black, grey, blue, etc. screens are considered fail.
            $analysis = 'A blank screen detected.';
            $this->logger->warning('VER0512 ' . $analysis);
            $this->context->setScreenshotFailed();
            $this->filesystem->filePutContents($this->context->getScreenshotErrorTextPath(), $analysis);
        } elseif ($this->screenshotContainsBsodQrCode()) {
            $analysis = 'Blue screen of death detected.';
            $this->logger->warning('VER0513 ' . $analysis);
            $this->context->setScreenshotFailed();
            $this->filesystem->filePutContents($this->context->getScreenshotErrorTextPath(), $analysis);
        } elseif (($analysis = $this->getOcrFailureText()) !== VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE) {
            // Check for BSOD, chkdsk and other "errors" so that we can fail the screenshot.
            $this->logger->warning(
                'VER0511 VM screenshot TIMED OUT and FAILED to boot correctly.',
                ['analysis' => $analysis]
            );
            $this->context->setScreenshotFailed();
        } else {
            // No known "bad" screens found; assume screenshot is good.
            $this->logger->info('VER0518 We didn\'t find any failures. Assume the screenshot is good');
            $this->filesystem->unlinkIfExists($this->context->getScreenshotErrorTextPath());

            $analysis = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
        }

        // CP-13809: If there's a pending OS update, note this alongside the results.
        if ($this->context->getOsUpdatePending()) {
            $message = 'A system update is pending on the protected system.';
            $this->logger->debug('VER0123' . $message);
            $this->filesystem->touch($this->context->getOsUpdatePendingPath());
        }

        return $analysis;
    }

    /**
     * Perform OCR conversion and text analysis.
     *
     * @return string|null
     *   For fail states, a message describing the matched phrase.
     *  `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if the screenshot does not contain any failure text.
     */
    private function getOcrFailureText()
    {
        $ocrResults = $this->ocrConverter->convert($this->context->getScreenshotImagePath());

        return $this->textAnalyzer->check($ocrResults);
    }

    /**
     * Starting with Windows 8.1, Microsoft graciously added a QR code
     * to the BSOD screen which links to their support website.
     *
     * By scanning for this barcode we can reliably detect BSOD screenshots.
     *
     * @return bool
     */
    private function screenshotContainsBsodQrCode(): bool
    {
        $link = $this->barcodeScanner->scan($this->context->getScreenshotImagePath());

        return stristr($link, 'windows.com') !== false;
    }
}
