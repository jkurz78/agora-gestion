<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helloasso_parametres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->unique()->constrained('association');
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('organisation_slug', 255)->nullable();
            $table->string('environnement', 20)->default('production');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_parametres');
    }
};
