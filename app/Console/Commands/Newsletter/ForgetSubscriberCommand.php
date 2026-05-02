<?php

declare(strict_types=1);

namespace App\Console\Commands\Newsletter;

use App\Models\Newsletter\SubscriptionRequest;
use App\Tenant\TenantScope;
use Illuminate\Console\Command;

final class ForgetSubscriberCommand extends Command
{
    protected $signature = 'newsletter:forget {email : Adresse email à oublier (RGPD)}';

    protected $description = 'Supprime physiquement toutes les lignes du buffer newsletter pour un email (droit à l\'effacement RGPD).';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $count = SubscriptionRequest::withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->count();

        if ($count === 0) {
            $this->info("Aucune ligne trouvée pour {$email}.");

            return self::SUCCESS;
        }

        SubscriptionRequest::withoutGlobalScope(TenantScope::class)
            ->where('email', $email)
            ->delete();

        $this->info("{$count} ligne(s) supprimée(s) pour {$email}.");

        return self::SUCCESS;
    }
}
