<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorService
{
    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    // ── Enable / Disable ──────────────────────────────────────

    public function enableEmail(User $user): void
    {
        $user->update([
            'two_factor_method' => TwoFactorMethod::Email,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function enableTotp(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->update([
            'two_factor_method' => TwoFactorMethod::Totp,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        return $secret;
    }

    public function confirmTotp(User $user, string $code): bool
    {
        if ($user->two_factor_method !== TwoFactorMethod::Totp || $user->two_factor_secret === null) {
            return false;
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $code)) {
            return false;
        }

        $user->update(['two_factor_confirmed_at' => now()]);

        return true;
    }

    public function disable(User $user): void
    {
        $user->update([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_trusted_token' => null,
        ]);
    }

    // ── Email OTP ─────────────────────────────────────────────

    public function generateEmailCode(User $user): void
    {
        // Clear old codes
        DB::table('two_factor_codes')->where('user_id', $user->id)->delete();

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('two_factor_codes')->insert([
            'user_id' => $user->id,
            'code' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user)->send(new TwoFactorCodeMail($plainCode));
    }

    public function verifyEmailCode(User $user, string $code): bool
    {
        $record = DB::table('two_factor_codes')
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($record === null) {
            return false;
        }

        if (! Hash::check($code, $record->code)) {
            return false;
        }

        DB::table('two_factor_codes')->where('user_id', $user->id)->delete();

        return true;
    }

    // ── TOTP ──────────────────────────────────────────────────

    public function verifyTotpCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        return $this->google2fa->verifyKey($user->two_factor_secret, $code);
    }

    // ── Recovery codes ────────────────────────────────────────

    public function generateRecoveryCodes(User $user): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < 8; $i++) {
            $code = Str::lower(Str::random(4).'-'.Str::random(4));
            $plainCodes[] = $code;
            $hashedCodes[] = Hash::make($code);
        }

        $user->update(['two_factor_recovery_codes' => $hashedCodes]);

        return $plainCodes;
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $hashedCodes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashedCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($hashedCodes[$index]);
                $user->update(['two_factor_recovery_codes' => array_values($hashedCodes)]);

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    // ── Trusted browser ───────────────────────────────────────

    public function setTrustedBrowser(Response $response, User $user): Response
    {
        if ($user->two_factor_trusted_token === null) {
            $user->update(['two_factor_trusted_token' => Str::random(64)]);
        }

        $cookieValue = encrypt($user->id.'|'.$user->two_factor_trusted_token);

        $cookie = Cookie::make(
            'two_factor_trusted',
            $cookieValue,
            60 * 24 * 30, // 30 days
            '/',
            null,
            true,  // secure
            true,  // httpOnly
        );

        $response->headers->setCookie($cookie);

        return $response;
    }

    public function isTrustedBrowser(Request $request, User $user): bool
    {
        $cookie = $request->cookie('two_factor_trusted');

        if ($cookie === null || $user->two_factor_trusted_token === null) {
            return false;
        }

        try {
            $decrypted = decrypt($cookie);
            [$userId, $token] = explode('|', $decrypted, 2);

            return (int) $userId === $user->id && $token === $user->two_factor_trusted_token;
        } catch (\Throwable) {
            return false;
        }
    }

    public function revokeTrustedBrowsers(User $user): void
    {
        $user->update(['two_factor_trusted_token' => null]);
    }

    // ── QR Code ───────────────────────────────────────────────

    public function qrCodeSvg(User $user): string
    {
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $user->two_factor_secret,
        );

        $renderer = new \BaconQrCode\Renderer\Image\SvgImageBackEnd();
        $imageRenderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
            $renderer,
        );
        $writer = new \BaconQrCode\Writer($imageRenderer);

        return $writer->writeString($qrCodeUrl);
    }
}
