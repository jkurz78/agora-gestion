<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

final class OtpService
{
    public function request(Association $association, string $email): RequestResult
    {
        $emailKey = mb_strtolower($email);

        if ($this->cooldownActive($association, $emailKey)) {
            return RequestResult::Cooldown;
        }

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

    public function verify(Association $association, string $email, string $code): VerifyResult
    {
        $emailKey = mb_strtolower($email);

        if ($this->cooldownActive($association, $emailKey)) {
            return VerifyResult::cooldown();
        }

        $otp = TiersPortailOtp::where('email', $emailKey)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($otp === null) {
            // Pas d'OTP valide — on incrémente quand même le compteur de cooldown
            $this->recordFailure($association, $emailKey);

            return VerifyResult::invalid();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $this->recordFailure($association, $emailKey);

            return VerifyResult::invalid();
        }

        // Consume atomique via DB::transaction
        $tiersIds = $this->consumeOtp($otp, $emailKey);

        if ($tiersIds === []) {
            // Un autre processus a consommé l'OTP entre la lecture et ici
            return VerifyResult::invalid();
        }

        // Reset cooldown on success
        RateLimiter::clear($this->cooldownKey($association, $emailKey));

        return VerifyResult::success($tiersIds);
    }

    public function cooldownActive(Association $association, string $email): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->cooldownKey($association, mb_strtolower($email)),
            (int) config('portail.otp_max_attempts'),
        );
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

    /**
     * Consomme l'OTP de façon atomique et retourne les IDs Tiers associés à l'email.
     * Retourne [] si l'OTP a déjà été consommé (race-condition).
     *
     * @return list<int>
     */
    private function consumeOtp(TiersPortailOtp $otp, string $emailKey): array
    {
        return DB::transaction(function () use ($otp, $emailKey): array {
            $affected = TiersPortailOtp::where('id', $otp->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($affected === 0) {
                return [];
            }

            return Tiers::where('email', $emailKey)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    private function recordFailure(Association $association, string $email): void
    {
        RateLimiter::hit(
            $this->cooldownKey($association, $email),
            (int) config('portail.otp_cooldown_minutes') * 60,
        );
    }

    private function cooldownKey(Association $association, string $email): string
    {
        return 'portail-otp:'.$association->id.':'.$email;
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
