<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_email', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->string('objet', 255);
            $table->text('corps');
            $table->unsignedInteger('nb_destinataires')->default(0);
            $table->unsignedInteger('nb_erreurs')->default(0);
            $table->foreignId('envoye_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('campagne_id')->nullable()->after('envoye_par')->constrained('campagnes_email')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropForeign(['campagne_id']);
            $table->dropColumn('campagne_id');
        });

        Schema::dropIfExists('campagnes_email');
    }
};
