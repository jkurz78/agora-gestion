<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user->hasTwoFactorEnabled() && $user->two_factor_method === TwoFactorMethod::Email) {
            $twoFactorService = app(TwoFactorService::class);
            if (! $twoFactorService->isTrustedBrowser($request, $user)) {
                $twoFactorService->generateEmailCode($user);
                $request->session()->put('two_factor_code_sent', true);
            }
        }

        $assos = $user->associations()->whereNull('association_user.revoked_at')->get();

        if ($assos->count() === 0) {
            auth()->logout();

            return redirect()->route('login')
                ->withErrors(['email' => 'Compte non rattaché à une association.']);
        }

        // Tenter d'auto-sélectionner derniere_association_id si encore valide
        if ($user->derniere_association_id !== null) {
            $stillValid = $assos->firstWhere('id', $user->derniere_association_id);
            if ($stillValid !== null) {
                $request->session()->put('current_association_id', $stillValid->id);

                return redirect($this->safeIntended(route('dashboard')));
            }
        }

        if ($assos->count() === 1) {
            $only = $assos->first();
            $request->session()->put('current_association_id', $only->id);
            $user->update(['derniere_association_id' => $only->id]);

            return redirect($this->safeIntended(route('dashboard')));
        }

        return redirect()->route('association-selector');
    }

    /**
     * Retourne l'URL voulue (stockée par le middleware auth) ou le défaut,
     * en ignorant les URLs d'assets/API qui ne servent pas de page HTML.
     *
     * Why: un utilisateur déconnecté peut déclencher la sauvegarde d'une
     * intended URL en tentant de charger une image signée mise en cache par
     * le navigateur. Rediriger vers cette URL après login affiche l'asset
     * en plein écran au lieu du dashboard.
     */
    private function safeIntended(string $default): string
    {
        $intended = session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $default;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?: '';
        foreach (['/tenant-assets/', '/api/', '/livewire/', '/storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $default;
            }
        }

        return $intended;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
