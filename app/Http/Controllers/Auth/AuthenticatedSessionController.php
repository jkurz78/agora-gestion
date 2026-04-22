<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Association;
use App\Models\User;
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

        // Branded route /{slug}/login: enforce that the authenticated user belongs
        // to the route's association BEFORE sending any 2FA email. If not, logout
        // and redirect back with an error (prevents 2FA email spam to innocent users).
        // When the check passes, force the slug's association as the active tenant,
        // bypassing derniere_association_id (scenario 4: multi-asso user).
        //
        // Note: $request->route('association') may be an Association model (when
        // SubstituteBindings has resolved it) or a raw slug string (when the middleware
        // priority puts BootTenantFromSlug before SubstituteBindings). We handle both.
        $routeParam = $request->route('association');

        $routeAssociation = match (true) {
            $routeParam instanceof Association => $routeParam,
            is_string($routeParam) && $routeParam !== '' => Association::where('slug', $routeParam)->first(),
            default => null,
        };

        if ($routeAssociation !== null) {
            $belongs = $user->associations()
                ->wherePivotNull('revoked_at')
                ->where('association.id', $routeAssociation->id)
                ->exists();

            if (! $belongs) {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => "Cet email n'est pas rattaché à l'association {$routeAssociation->nom}.",
                ])->withInput($request->only('email'));
            }

            // Force the slug's association as the active tenant, regardless of derniere_association_id.
            $request->session()->put('current_association_id', $routeAssociation->id);
            $user->update(['derniere_association_id' => $routeAssociation->id]);

            // Trigger 2FA only for a verified member (code generated after membership check).
            $this->maybeGenerateTwoFactorCode($user, $request);

            return redirect($this->safeIntended(route('dashboard')));
        }

        // Standard (non-branded) login: check asso membership BEFORE sending
        // any 2FA email — prevents leaking credential validity to users with
        // no active association (they would otherwise receive a 2FA email).
        $assos = $user->associations()->wherePivotNull('revoked_at')->get();

        if ($assos->count() === 0) {
            auth()->logout();

            return redirect()->route('login')
                ->withErrors(['email' => 'Compte non rattaché à une association.']);
        }

        // 2FA only after we've confirmed the user has at least one active asso.
        $this->maybeGenerateTwoFactorCode($user, $request);

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
     * Generate and send a 2FA email code if the user has email-based 2FA enabled
     * and is not on a trusted browser. Sets the session flag used by the challenge
     * middleware. Extracted to avoid duplicating this logic between the slug-first
     * path and the standard login path.
     */
    private function maybeGenerateTwoFactorCode(
        User $user,
        Request $request,
    ): void {
        if (! $user->hasTwoFactorEnabled() || $user->two_factor_method !== TwoFactorMethod::Email) {
            return;
        }

        $twoFactorService = app(TwoFactorService::class);
        if (! $twoFactorService->isTrustedBrowser($request, $user)) {
            $twoFactorService->generateEmailCode($user);
            $request->session()->put('two_factor_code_sent', true);
        }
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
