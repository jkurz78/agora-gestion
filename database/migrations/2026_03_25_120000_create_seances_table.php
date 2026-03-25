<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations');
            $table->unsignedInteger('numero');
            $table->date('date')->nullable();
            $table->string('titre', 255)->nullable();
            $table->timestamps();

            $table->unique(['operation_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seances');
    }
};
