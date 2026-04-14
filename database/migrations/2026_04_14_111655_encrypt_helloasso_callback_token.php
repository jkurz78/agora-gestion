<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('helloasso_parametres')
            ->whereNotNull('callback_token')
            ->get()
            ->each(function (object $row): void {
                DB::table('helloasso_parametres')
                    ->where('id', $row->id)
                    ->update(['callback_token' => Crypt::encryptString($row->callback_token)]);
            });
    }

    public function down(): void
    {
        // Déchiffrement inverse — nécessite APP_KEY inchangée
        DB::table('helloasso_parametres')
            ->whereNotNull('callback_token')
            ->get()
            ->each(function (object $row): void {
                try {
                    $plain = Crypt::decryptString($row->callback_token);
                    DB::table('helloasso_parametres')
                        ->where('id', $row->id)
                        ->update(['callback_token' => $plain]);
                } catch (\Throwable) {
                    // déjà en clair ou clef changée — on laisse tel quel
                }
            });
    }
};
