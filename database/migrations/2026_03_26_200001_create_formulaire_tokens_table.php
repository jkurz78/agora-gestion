<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulaire_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained('participants')->cascadeOnDelete();
            $table->string('token', 9)->unique();
            $table->date('expire_at');
            $table->dateTime('rempli_at')->nullable();
            $table->string('rempli_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulaire_tokens');
    }
};
