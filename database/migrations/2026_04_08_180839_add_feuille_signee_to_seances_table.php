<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->string('feuille_signee_path')->nullable();
            $table->timestamp('feuille_signee_at')->nullable();
            $table->string('feuille_signee_source', 16)->nullable();
            $table->string('feuille_signee_sender_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropColumn([
                'feuille_signee_path',
                'feuille_signee_at',
                'feuille_signee_source',
                'feuille_signee_sender_email',
            ]);
        });
    }
};
