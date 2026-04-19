<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_de_frais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association');
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->date('date')->nullable();
            $table->string('libelle')->nullable();
            $table->string('statut')->default('brouillon');
            $table->string('motif_rejet')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validee_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['association_id', 'tiers_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_de_frais');
    }
};
