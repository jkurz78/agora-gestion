<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_operations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 150)->unique();
            $table->text('description')->nullable();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories');
            $table->integer('nombre_seances')->nullable();
            $table->boolean('confidentiel')->default(false);
            $table->boolean('reserve_adherents')->default(false);
            $table->boolean('actif')->default(true);
            $table->string('logo_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_operations');
    }
};
