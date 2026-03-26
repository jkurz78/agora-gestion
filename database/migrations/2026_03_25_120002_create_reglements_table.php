<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->string('mode_paiement')->nullable();
            $table->decimal('montant_prevu', 10, 2)->default(0);
            $table->unsignedBigInteger('remise_id')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'seance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglements');
    }
};
