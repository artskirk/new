<?php
namespace Datto\Verification\Screenshot;

use Datto\Common\Utility\Filesystem;
use Datto\Verification\Notification\VerificationResults;

/**
 * Check if text contains a message indicating a failed boot state.
 */
class TextAnalyzer
{
    /**
     * Percentage to pass to `similar_text()` for fail text comparison.
     */
    const MINIMUM_MATCH_PERCENTAGE = 70;

    /**
     * @var string[][] $failText
     *   Phrases that indicate failure in a screenshot.
     *
     * You MUST place single-word phrases at the end of the array.
     *
     * Examples of each of these failure phrases can be found in OcrAnalysisTest's images directory.
     */
    protected $failText = [
        ['bootmgr', 'compressed'],
        ['bootmgr', 'missing'],
        ['fatal', 'system', 'error'],
        ['missing', 'operating', 'system'],
        ['operating', 'system', 'wasnt', 'found'],
        ['disk', 'read', 'error', 'occurred'],
        ['system', 'halted'],
        ['windows', 'boot', 'manager'],
        ['windows', 'could', 'not', 'start'],
        ['start', 'windows', 'normally'],
        ['checking', 'file', 'system'],
        ['chkdsk', 'verifying', 'files'],
        ['chkdsk', 'verifying', 'security'],
        ['scanning', 'and', 'repairing', 'drive'],
        ['stop', 'error', 'screen'],
        ['operating', 'system', 'not', 'found'],
        ['booting', 'from', 'hard', 'disk'],
        ['seabios', 'version'],
        ['getting', 'windows', 'ready'],
        ['getting', 'devices', 'ready'],
        ['getting', 'ready'],
        ['configuring', 'windows', 'updates'],
        ['working', 'updates'],
        ['please', 'wait'],
        ['shutting', 'down', 'service'],
        ['stopping', 'services'],
        ['needs', 'restart'],
        ['restarting']
    ];

    /**
     * @var string[] $replacements
     *   Associative array that maps regular expressions to replacement text.
     *
     * Facilitates the following conversions:
     *  - remove strings less than 3 characters long
     *  - remove non-alphabetic characters
     */
    protected $replacements = [
        '/[^\w\s]+/' => '', // fix contractions by removing unusual characters prior to removing 1-2 character words
        '/\b\S{1,2}\b/' => '',
        '/\d+/' => '',
    ];

    /** @var Filesystem */
    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Runs OCR on the screenshot to find failure text.
     *
     * chkdsk, BSOD, etc. cause a screenshot to be considered failed.
     *
     * @param OcrResult[] $ocrResults
     *   The text to be analyzed for failure states.
     *
     * @return string|null
     *   The error message upon detection of failure.
     *   `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if no known failure state is detected.
     */
    public function check(array $ocrResults)
    {
        // Run some pre-processing on each of the OCR Results
        $processedOcrText = [];
        $processedOcrWords = [];
        foreach ($ocrResults as $ocrResult) {
            // Preprocess OCR text using the provided array of character replacement regexes.
            $processedOcrText[$ocrResult->getOcrToolName()] = preg_replace(array_keys($this->replacements), array_values($this->replacements), $ocrResult->getOcrResultText());

            // Set words array from processed OCR string, split by whitespace.
            $processedOcrWords[$ocrResult->getOcrToolName()] = preg_split('/\s+/', strtolower($processedOcrText[$ocrResult->getOcrToolName()]));
        }

        // Check for phrases that indicate failure.  We need to make 1 pass through the array of phrases, in order.
        foreach ($this->failText as $phrase) {
            // Make sure we are looking for a matching failure phrase in the processed text from all ocr tools before
            // checking the next phrase.  Otherwise we might find a match on a more generic phrase before looking for
            // a more specific phrase on the other OCR results.
            foreach ($ocrResults as $ocrResult) {
                if ($this->matchesPhrase($phrase, $processedOcrWords[$ocrResult->getOcrToolName()])) {
                    // The error file should be the ocr-specific `.<tool>.txt` renamed to `.txt`
                    // since other code is expecting it in this specific format
                    $errorTextFilePath = str_replace(
                        '.' . $ocrResult->getOcrToolName(),
                        '',
                        $ocrResult->getResultFilePath()
                    );
                    $this->filesystem->copy($ocrResult->getResultFilePath(), $errorTextFilePath);
                    return 'OCR Text: ' . $processedOcrText[$ocrResult->getOcrToolName()] . ', matching phrase ' . implode(' ', $phrase);
                }
            }
        }

        return VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
    }

    /**
     * Tests if given phrase exists in a text.
     *
     * Checks each word with similar_text to make sure that tested texts are
     * "close enough", e.g. when checking text from OCR.
     *
     * @param array $phrase
     *   The array of words representing a phrase that we want to check.
     * @param array $words
     *   The array of words that may contain the phrase we're looking for.
     *
     * @return bool
     *   TRUE if the phrase is found, otherwise FALSE.
     */
    protected function matchesPhrase(array $phrase, array $words): bool
    {
        $wordCount = count($phrase);
        $wordsMatched = 0;

        foreach ($words as $word) {
            similar_text($word, $phrase[$wordsMatched], $matchPercent);

            if ($matchPercent >= static::MINIMUM_MATCH_PERCENTAGE) {
                $wordsMatched++;
            } else {
                $wordsMatched = 0;
            }

            // All words were matched in sequence.
            if ($wordsMatched === $wordCount) {
                return true;
            }
        }

        return false;
    }
}
