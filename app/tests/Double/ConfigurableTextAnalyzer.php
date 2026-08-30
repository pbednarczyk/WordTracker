<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Nlp\TextAnalysis;
use App\Nlp\TextAnalyzerException;
use App\Nlp\TextAnalyzerInterface;

final class ConfigurableTextAnalyzer implements TextAnalyzerInterface
{
    public static ?TextAnalysis $analysis = null;

    public function analyze(string $text): TextAnalysis
    {
        if (self::$analysis === null) {
            throw new TextAnalyzerException('Test analyzer was not configured.');
        }

        return self::$analysis;
    }
}
