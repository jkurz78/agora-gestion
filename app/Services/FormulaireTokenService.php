<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FormulaireToken;
use App\Models\Participant;

final class FormulaireTokenService
{
    private const ALPHABET = '3456789ABCDEFGHJKMNPQRSTVWXY';

    public function generate(Participant $participant, ?string $expireAt = null): FormulaireToken
    {
        // Delete existing token for this participant
        FormulaireToken::where('participant_id', $participant->id)->delete();

        $token = $this->generateUniqueToken();

        if ($expireAt === null) {
            $operation = $participant->operation;
            if ($operation->date_debut !== null && $operation->date_debut->gt(today())) {
                $expireAt = $operation->date_debut->subDay()->format('Y-m-d');
            } else {
                $expireAt = now()->addDays(30)->format('Y-m-d');
            }
        }

        return FormulaireToken::create([
            'participant_id' => $participant->id,
            'token' => $token,
            'expire_at' => $expireAt,
        ]);
    }

    /**
     * @return array{status: 'valid'|'invalid'|'expired'|'used', participant: ?Participant}
     */
    public function validate(string $input): array
    {
        $normalized = $this->normalizeToken($input);

        $formulaireToken = FormulaireToken::where('token', $normalized)->first();

        if ($formulaireToken === null) {
            return ['status' => 'invalid', 'participant' => null];
        }

        if ($formulaireToken->isUtilise()) {
            return ['status' => 'used', 'participant' => null];
        }

        if ($formulaireToken->isExpire()) {
            return ['status' => 'expired', 'participant' => null];
        }

        return ['status' => 'valid', 'participant' => $formulaireToken->participant];
    }

    private function generateUniqueToken(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $token = substr($code, 0, 4).'-'.substr($code, 4, 4);
        } while (FormulaireToken::where('token', $token)->exists());

        return $token;
    }

    private function normalizeToken(string $input): string
    {
        $clean = strtoupper(trim(str_replace(' ', '', $input)));
        // Remove tiret for uniform handling, then re-add
        $clean = str_replace('-', '', $clean);
        if (strlen($clean) === 8) {
            return substr($clean, 0, 4).'-'.substr($clean, 4, 4);
        }

        return $clean;
    }
}
