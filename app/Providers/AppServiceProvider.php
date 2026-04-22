<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Models\Transaction;
use App\Observers\AssociationObserver;
use App\Observers\ImmutableSlugObserver;
use App\Observers\TransactionObserver;
use App\Policies\NoteDeFraisPolicy;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(NoteDeFrais::class, NoteDeFraisPolicy::class);

        Association::observe(AssociationObserver::class);
        Association::observe(ImmutableSlugObserver::class);
        Transaction::observe(TransactionObserver::class);

        if (! file_exists(config_path('version.php'))) {
            $data = VersionStampCommand::readGitVersion();
            VersionStampCommand::writeVersionFile($data);
        }

        View::composer(['layouts.app', 'layouts.app-sidebar'], function (\Illuminate\View\View $view): void {
            $view->with('incomingDocumentsCount', IncomingDocument::count());

            $canSeeNdf = false;
            $ndfPendingCount = 0;

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
                    }
                }
            }

            $view->with([
                'canSeeNdf' => $canSeeNdf,
                'ndfPendingCount' => $ndfPendingCount,
            ]);
        });
    }
}
