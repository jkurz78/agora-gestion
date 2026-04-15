<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $firstAssoId = DB::table('association')->orderBy('id')->value('id');
            if (! $firstAssoId) {
                return;
            }

            $users = DB::table('users')->get();
            foreach ($users as $user) {
                $exists = DB::table('association_user')
                    ->where('user_id', $user->id)
                    ->where('association_id', $firstAssoId)
                    ->exists();

                if (! $exists) {
                    DB::table('association_user')->insert([
                        'user_id' => $user->id,
                        'association_id' => $firstAssoId,
                        'role' => $user->role ?? 'consultation',
                        'joined_at' => $user->created_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Initialiser derniere_association_id si NULL
                DB::table('users')
                    ->where('id', $user->id)
                    ->whereNull('derniere_association_id')
                    ->update(['derniere_association_id' => $firstAssoId]);
            }
        });
    }

    public function down(): void
    {
        // Non réversible — les données pivot sont la source de vérité désormais.
    }
};
