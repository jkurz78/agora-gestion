<?php

// database/migrations/2026_03_14_100000_create_tiers_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['entreprise', 'particulier'])->default('particulier');
            $table->string('nom', 150);
            $table->string('prenom', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->text('adresse')->nullable();
            $table->boolean('pour_depenses')->default(false);
            $table->boolean('pour_recettes')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers');
    }
};
