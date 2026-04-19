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
        $tiers = Tiers::where('email', $email)->first();

        if ($tiers === null) {
            return; // anti-énum traité en Step 3
        }

        $code = $this->generateCode();

        TiersPortailOtp::create([
            'association_id' => $association->id,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('portail.otp_ttl_minutes')),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);

        Mail::to($email)->send(new OtpMail($association, $code));
    }

    private function generateCode(): string
    {
        $length = (int) config('portail.otp_length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
