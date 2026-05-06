<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Enums\RoleAssociation;
use App\Enums\StatutFactureDeposee;
use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Extourne;
use App\Models\FacturePartenaireDeposee;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\AssociationObserver;
use App\Observers\ImmutableSlugObserver;
use App\Observers\TransactionObserver;
use App\Observers\UserRoleObserver;
use App\Policies\ExtournePolicy;
use App\Policies\FacturePartenaireDeposeePolicy;
use App\Policies\NoteDeFraisPolicy;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
use App\Tenant\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LigneTypeRegistry::class);
    }

    public function boot(): void
    {
        Gate::policy(FacturePartenaireDeposee::class, FacturePartenaireDeposeePolicy::class);
        Gate::policy(NoteDeFrais::class, NoteDeFraisPolicy::class);
        Gate::policy(Extourne::class, ExtournePolicy::class);

        Association::observe(AssociationObserver::class);
        Association::observe(ImmutableSlugObserver::class);
        Transaction::observe(TransactionObserver::class);
        User::observe(UserRoleObserver::class);

        // Rate limiter pour l'API newsletter publique : 5 requêtes / IP / heure.
        // Réponse 429 normalisée {"error": "rate_limit"} pour le contrat API.
        // Doit être enregistré ici (boot d'un Service Provider) et non dans
        // bootstrap/app.php — à l'instant où la closure withMiddleware s'exécute,
        // les facades ne sont pas encore bind, ce qui produit
        // "RuntimeException: A facade root has not been set".
        RateLimiter::for('newsletter', function (Request $request) {
            return Limit::perHour(
                (int) config('newsletter.rate_limit.max_attempts', 5)
            )
                ->by((string) $request->ip())
                ->response(fn () => response()->json(['error' => 'rate_limit'], 429));
        });

        if (! file_exists(config_path('version.php'))) {
            $data = VersionStampCommand::readGitVersion();
            VersionStampCommand::writeVersionFile($data);
        }

        Gate::define('access-newsletter-inbox', function (User $user): bool {
            if (! TenantContext::hasBooted()) {
                return false;
            }
            $assocUser = AssociationUser::where('user_id', $user->id)
                ->where('association_id', TenantContext::currentId())
                ->whereNull('revoked_at')
                ->first();
            if ($assocUser === null) {
                return false;
            }
            $role = $assocUser->role instanceof RoleAssociation
                ? $assocUser->role
                : RoleAssociation::from((string) $assocUser->role);

            return in_array($role, [RoleAssociation::Admin, RoleAssociation::Comptable], true);
        });

        View::composer(['layouts.app', 'layouts.app-sidebar'], function (\Illuminate\View\View $view): void {
            $view->with('incomingDocumentsCount', IncomingDocument::count());

            $canSeeNdf = false;
            $ndfPendingCount = 0;
            $facturesPartenairesPendingCount = 0;

            if (Auth::check() && TenantContext::hasBooted()) {
                $assocUser = AssociationUser::where('user_id', (int) Auth::id())
                    ->where('association_id', (int) TenantContext::currentId())
                    ->whereNull('revoked_at')
                    ->first();

                if ($assocUser !== null) {
                    $role = $assocUser->role instanceof RoleAssociation
                        ? $assocUser->role
                        : RoleAssociation::from((string) $assocUser->role);

                    if (in_array($role, [RoleAssociation::Admin, RoleAssociation::Comptable], true)) {
                        $canSeeNdf = true;
                        $ndfPendingCount = NoteDeFrais::where('statut', StatutNoteDeFrais::Soumise->value)->count();
                        $facturesPartenairesPendingCount = FacturePartenaireDeposee::where('statut', StatutFactureDeposee::Soumise->value)->count();
                    }
                }
            }

            $canSeeFacturesPartenaires = $canSeeNdf; // même règle d'accès (Admin/Comptable)

            $view->with([
                'canSeeNdf' => $canSeeNdf,
                'ndfPendingCount' => $ndfPendingCount,
                'canSeeFacturesPartenaires' => $canSeeFacturesPartenaires,
                'facturesPartenairesPendingCount' => $facturesPartenairesPendingCount,
            ]);
        });
    }
}
