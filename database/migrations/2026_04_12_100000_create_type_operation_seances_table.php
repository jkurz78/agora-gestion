<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_operation_seances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('type_operation_id')->constrained('type_operations')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->string('titre', 255)->nullable();
            $table->timestamps();
            $table->unique(['type_operation_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_operation_seances');
    }
};
