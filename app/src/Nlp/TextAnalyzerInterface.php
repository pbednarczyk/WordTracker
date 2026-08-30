<?php

declare(strict_types=1);

namespace App\Nlp;

interface TextAnalyzerInterface
{
    public function analyze(string $text): TextAnalysis;
}
