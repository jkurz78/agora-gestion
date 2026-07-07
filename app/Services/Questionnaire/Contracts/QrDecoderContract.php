<?php

declare(strict_types=1);

namespace App\Services\Questionnaire\Contracts;

interface QrDecoderContract
{
    public function decodeFromPath(string $path, string $mime): ?string;
}
