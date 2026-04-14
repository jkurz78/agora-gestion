<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_parametres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->unique()->constrained('association')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_encryption', 10)->default('tls');
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_parametres');
    }
};
