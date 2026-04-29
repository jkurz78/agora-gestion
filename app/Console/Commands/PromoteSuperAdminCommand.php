<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RoleSysteme;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote a user to super-admin by email — the CLI counterpart to the
 * "first super-admin" runbook entry. Deliberately not exposed in the UI:
 * super-admin is a system role, not an application role.
 */
final class PromoteSuperAdminCommand extends Command
{
    protected $signature = 'app:promote-super-admin
        {email : Email of the user to promote}
        {--demote : Revert the user back to a regular member (role_systeme = user)}';

    protected $description = "Promote (or demote) a user's system role to super-admin.";

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $demote = (bool) $this->option('demote');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun utilisateur trouvé avec l'email « {$email} ».");

            return self::FAILURE;
        }

        if ($demote) {
            if ($user->role_systeme !== RoleSysteme::SuperAdmin) {
                $this->warn("L'utilisateur « {$email} » n'est pas super-admin — rien à faire.");

                return self::SUCCESS;
            }

            $user->role_systeme = RoleSysteme::User;
            $user->save();
            $this->info("Utilisateur « {$email} » rétrogradé (role_systeme = user).");

            return self::SUCCESS;
        }

        if ($user->role_systeme === RoleSysteme::SuperAdmin) {
            $this->warn("L'utilisateur « {$email} » est déjà super-admin — rien à faire.");

            return self::SUCCESS;
        }

        $user->role_systeme = RoleSysteme::SuperAdmin;
        $user->save();

        $this->info("Utilisateur « {$email} » promu super-admin.");
        $this->line('Connexion : <fg=cyan>'.config('app.url').'/super-admin</> (logout/login obligatoire si déjà connecté).');

        return self::SUCCESS;
    }
}
