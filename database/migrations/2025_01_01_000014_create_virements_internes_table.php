<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virements_internes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('montant', 10, 2);
            $table->foreignId('compte_source_id')->constrained('comptes_bancaires');
            $table->foreignId('compte_destination_id')->constrained('comptes_bancaires');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virements_internes');
    }
};
