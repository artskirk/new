<?php

namespace Datto\App\Console\Command\Verification;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\System\Exceptions\FileNotFoundException;
use Datto\Verification\Screenshot\OcrConverter;
use Datto\Verification\Screenshot\TextAnalyzer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OcrCommand extends AbstractCommand
{
    protected static $defaultName = 'verification:ocr';

    /** @var Filesystem */
    private $filesystem;

    /** @var OcrConverter */
    private $ocrConverter;

    /** @var TextAnalyzer */
    private $textAnalyzer;

    public function __construct(OcrConverter $ocrConverter, TextAnalyzer $textAnalyzer, Filesystem $filesystem)
    {
        parent::__construct();
        $this->ocrConverter = $ocrConverter;
        $this->filesystem = $filesystem;
        $this->textAnalyzer = $textAnalyzer;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_VERIFICATIONS,
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Perform OCR text conversion on an image')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the image')
            ->addOption('analyze', 'a', InputOption::VALUE_NONE, 'Analyze the text in the image')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!$this->filesystem->exists($path)) {
            throw new FileNotFoundException($path);
        }

        $ocrResults = $this->ocrConverter->convert($path);
        foreach ($ocrResults as $ocrResult) {
            $characterCount = mb_strlen($ocrResult->getOcrResultText());

            $output->writeln("OCR Tool: " . $ocrResult->getOcrToolName());
            $output->writeln('Total characters: ' . $characterCount);
            if ($characterCount !== 0) {
                $output->writeln('OCR text: ' . $ocrResult->getOcrResultText() . PHP_EOL);
            }
        }

        if ($input->getOption('analyze')) {
            $analysis = $this->textAnalyzer->check($ocrResults);
            $output->writeln('Analysis: ' . ($analysis ?? 'successful screenshot'));
        }
        return 0;
    }
}
