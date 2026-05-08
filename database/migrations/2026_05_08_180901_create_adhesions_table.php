<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('adhesions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('exercice');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->boolean('gratuite')->default(false);
            $table->string('motif_gratuite', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['association_id', 'tiers_id', 'exercice'], 'adhesions_unique_per_exercice');
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adhesions');
    }
};
