<?php
namespace Datto\Verification\Screenshot;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use InvalidArgumentException;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Convert images to text using multiple OCR tools.
 */
class OcrConverter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const OCR_TOOL_GOCR = 'gocr';
    private const OCR_TOOL_TESSERACT = 'tesseract';
    private const OCR_TIMEOUT = 30;

    private Filesystem $filesystem;

    private ProcessFactory $processFactory;

    public function __construct(Filesystem $filesystem, ProcessFactory $processFactory)
    {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
    }

    /**
     * Returns text present in the screenshot by running OCR on it.
     *
     * @param string $jpegPath
     *   The path to the screenshot file.
     *
     * @return OcrResult[]
     *   The text present on the screen or empty string if OCR failed.
     */
    public function convert(string $jpegPath): array
    {
        if (!$this->filesystem->exists($jpegPath)) {
            throw new InvalidArgumentException('File does not exist: ' . $jpegPath);
        }

        if (strtolower(substr($jpegPath, -4)) !== '.jpg') {
            throw new InvalidArgumentException('File does not appear to be a JPEG image.');
        }

        $dirname = dirname($jpegPath);
        $basename = basename($jpegPath, '.jpg');
        $basePath = $dirname . '/' . $basename;
        $ocrResults = [];

        $result = $this->doTesseract($jpegPath, $basePath, true);
        if (isset($result)) {
            $ocrResults[] = $result;
        }

        $result = $this->doGOCR($jpegPath, $basePath);
        if (isset($result)) {
            $ocrResults[] = $result;
        }

        return $ocrResults;
    }

    /**
     * Perform OCR conversion on an image file.
     *
     * Creates a text file with OCR text extracted from the image.
     *
     * @param string $imagePath
     *   The path to the image file to be converted.
     * @param string $basePath
     *   The base path for the text file that will be generated.
     *   Do not include the '.txt' extension, since `tesseract` adds it.
     * @param bool $preprocess
     *   Whether we should perform image preprocessing to improve tesseract
     *   results at the expense of additional processing time
     *
     * @return OcrResult|null The results of the OCR, or null if the OCR encountered an error
     */
    private function doTesseract(string $imagePath, string $basePath, bool $preprocess): ?OcrResult
    {
        $tessTxtPath = $this->getResultsTxtPath($basePath, self::OCR_TOOL_TESSERACT);
        $tifPath = $basePath . '.tif';
        try {
            if ($preprocess) {
                // Run imagick convert to pre-process the jpg into a tif to improve tesseract OCR performance
                $this->processFactory
                    ->get([
                        'convert',
                        $imagePath,
                        '-resize', '2200x2200',
                        '-blur', '1x1',
                        '-background', 'black',  // ImageMagick's documentation is wrong about how to set border colors:
                        '-bordercolor', 'black', // Both 'background' and 'bordercolor' need to be specified
                        '-border', '20', // Adding a border improves results for text at the edge of the screen.
                        '-type', 'grayscale', // make it grayscale before adjusting contrast to fix white on gray text
                        '-contrast-stretch', '5x0%', // increase contrast to fix reading white fonts on light bgs
                        '-posterize', '3',
                        '-gamma', '100',
                        '+compress',
                        $tifPath
                    ])
                    ->disableOutput()
                    ->setTimeout(self::OCR_TIMEOUT)
                    ->mustRun();
            }

            // Run: tesseract $tifPath $basePath
            // NOTE: Tesseract has a tendency to deadlock when run on a system with a core count corresponding
            // to the number of threads it decides to use. Use the OMP_THREAD_LIMIT var to limit it to a single
            // thread, which can't deadlock. See https://github.com/tesseract-ocr/tesseract/issues/898
            $this->processFactory
                ->get([
                    self::OCR_TOOL_TESSERACT,
                    $preprocess ? $tifPath : $imagePath,
                    $basePath . '.' . self::OCR_TOOL_TESSERACT
                ])
                ->setEnv(['OMP_THREAD_LIMIT' => '1'])
                ->disableOutput()
                ->setTimeout(self::OCR_TIMEOUT)
                ->mustRun();

            return new OcrResult(
                self::OCR_TOOL_TESSERACT,
                trim($this->filesystem->fileGetContents($tessTxtPath)),
                $tessTxtPath
            );
        } catch (Throwable $t) {
            $this->filesystem->unlinkIfExists($tessTxtPath);
            $this->logger->error('OCR0001 Error during OCR Conversion', [
                'exception' => $t,
                'jpegPath' => $imagePath
            ]);
        } finally {
            $this->filesystem->unlinkIfExists($tifPath);
        }
        return null;
    }

    private function doGOCR(string $imagePath, string $basePath): ?OcrResult
    {
        $gocrTxtPath = $this->getResultsTxtPath($basePath, self::OCR_TOOL_GOCR);
        try {
            $this->processFactory
                ->get([self::OCR_TOOL_GOCR, '-i', $imagePath, '-o', $gocrTxtPath])
                ->disableOutput()
                ->setTimeout(self::OCR_TIMEOUT)
                ->mustRun();

            return new OcrResult(
                self::OCR_TOOL_GOCR,
                trim($this->filesystem->fileGetContents($gocrTxtPath)),
                $gocrTxtPath
            );
        } catch (Throwable $t) {
            $this->filesystem->unlinkIfExists($gocrTxtPath);
            $this->logger->error('OCR0002 Error during OCR Conversion', [
                'exception' => $t,
                'jpegPath' => $imagePath
            ]);
        }
        return null;
    }

    private function getResultsTxtPath(string $basePath, string $toolName): string
    {
        return "$basePath.$toolName.txt";
    }
}
