<?php

declare(strict_types=1);

namespace App\Exceptions;

final class OcrNotConfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Clé API Anthropic non configurée. Allez dans Paramètres > Association > OCR / IA.');
    }
}
