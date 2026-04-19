<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

final class OtpService
{
    public function request(Association $association, string $email): void
    {
        $tiers = $this->findEligibleTiers($email);

        $code = $this->generateCode();
        $codeHash = Hash::make($code); // exécuté dans tous les cas — garantit un temps comparable

        if ($tiers === null) {
            return;
        }

        TiersPortailOtp::create([
            'association_id' => $association->id,
            'email' => $email,
            'code_hash' => $codeHash,
            'expires_at' => now()->addMinutes((int) config('portail.otp_ttl_minutes')),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);

        Mail::to($email)->send(new OtpMail($association, $code));
    }

    private function findEligibleTiers(string $email): ?Tiers
    {
        return Tiers::where('email', $email)->first();
    }

    private function generateCode(): string
    {
        $length = (int) config('portail.otp_length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
