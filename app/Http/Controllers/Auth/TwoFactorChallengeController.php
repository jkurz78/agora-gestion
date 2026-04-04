<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('home');
        }

        if ($user->two_factor_method === TwoFactorMethod::Email && ! $request->session()->has('two_factor_code_sent')) {
            $this->twoFactorService->generateEmailCode($user);
            $request->session()->put('two_factor_code_sent', true);
        }

        return view('auth.two-factor-challenge', [
            'method' => $user->two_factor_method,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        $code = $request->input('code');
        $useRecovery = $request->boolean('use_recovery');
        $verified = false;

        if ($useRecovery) {
            $verified = $this->twoFactorService->verifyRecoveryCode($user, $code);
        } elseif ($user->two_factor_method === TwoFactorMethod::Email) {
            $verified = $this->twoFactorService->verifyEmailCode($user, $code);
        } elseif ($user->two_factor_method === TwoFactorMethod::Totp) {
            $verified = $this->twoFactorService->verifyTotpCode($user, $code);
        }

        if (! $verified) {
            return back()->withErrors(['code' => 'Le code est invalide ou expiré.']);
        }

        $request->session()->put('two_factor_verified', true);
        $request->session()->forget('two_factor_code_sent');

        $response = redirect()->intended(route('home'));

        if ($request->boolean('trust_browser')) {
            $this->twoFactorService->setTrustedBrowser($response, $user);
        }

        return $response;
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_method === TwoFactorMethod::Email) {
            $this->twoFactorService->generateEmailCode($user);
        }

        return back()->with('status', 'Un nouveau code a été envoyé.');
    }
}
