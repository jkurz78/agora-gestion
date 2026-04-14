<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Élargir la colonne avant de chiffrer (ciphertext ~300 chars)
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->text('callback_token')->nullable()->change();
        });

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
        // Déchiffrer d'abord, puis rétrécir la colonne
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

        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->string('callback_token', 64)->nullable()->change();
        });
    }
};
