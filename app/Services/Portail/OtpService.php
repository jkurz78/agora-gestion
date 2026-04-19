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
    public function request(Association $association, string $email): RequestResult
    {
        $emailKey = mb_strtolower($email);
        $resendSeconds = (int) config('portail.otp_resend_seconds');

        // Vérif du délai renvoi AVANT le hash (le délai est public : pas d'info sensible révélée)
        $latest = TiersPortailOtp::where('email', $emailKey)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null && $latest->last_sent_at->diffInSeconds(now()) < $resendSeconds) {
            return RequestResult::TooSoon;
        }

        // Temps constant : Hash::make exécuté dans tous les cas
        $code = $this->generateCode();
        $codeHash = Hash::make($code);

        $tiers = $this->findEligibleTiers($emailKey);
        if ($tiers === null) {
            return RequestResult::Silent;
        }

        // Invalidation de l'ancien OTP
        if ($latest !== null) {
            $latest->update(['consumed_at' => now()]);
        }

        TiersPortailOtp::create([
            'association_id' => $association->id,
            'email' => $emailKey,
            'code_hash' => $codeHash,
            'expires_at' => now()->addMinutes((int) config('portail.otp_ttl_minutes')),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);

        Mail::to($emailKey)->send(new OtpMail($association, $code));

        return RequestResult::Sent;
    }

    public function canResend(Association $association, string $email): bool
    {
        $emailKey = mb_strtolower($email);

        $latest = TiersPortailOtp::where('email', $emailKey)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return true;
        }

        return $latest->last_sent_at->diffInSeconds(now()) >= (int) config('portail.otp_resend_seconds');
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
