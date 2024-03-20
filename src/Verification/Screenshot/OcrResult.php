<?php

namespace Datto\Verification\Screenshot;

class OcrResult
{
    /** @var string The name of the tool that generated this ocr result text */
    private string $ocrToolName;

    /** @var string The text produced by running an OCR tool on an image */
    private string $ocrResultText;

    /** @var string The path to the results file containing this ocr result text */
    private string $resultFilePath;

    public function __construct(string $ocrToolName, string $ocrResultText, string $resultFilePath)
    {
        $this->ocrToolName = $ocrToolName;
        $this->ocrResultText = $ocrResultText;
        $this->resultFilePath = $resultFilePath;
    }

    public function getOcrResultText(): string
    {
        return $this->ocrResultText;
    }

    public function getOcrToolName(): string
    {
        return $this->ocrToolName;
    }

    public function getResultFilePath(): string
    {
        return $this->resultFilePath;
    }
}
