<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Illuminate\Support\Str;

final class QuestionnaireTokenService
{
    // Alphabet sans I, O, 0, 1, L pour le code court (lecture humaine sur papier).
    private const ALPHABET_COURT = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Token public : clair haute entropie (jamais stocké) + hash sha256 (stocké).
     *
     * @return array{clair: string, hash: string}
     */
    public function generer(): array
    {
        $clair = Str::random(48);

        return ['clair' => $clair, 'hash' => hash('sha256', $clair)];
    }

    public function hash(string $clair): string
    {
        return hash('sha256', $clair);
    }

    public function codeCourt(int $taille = 8): string
    {
        $code = '';
        for ($i = 0; $i < $taille; $i++) {
            $code .= self::ALPHABET_COURT[random_int(0, strlen(self::ALPHABET_COURT) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
