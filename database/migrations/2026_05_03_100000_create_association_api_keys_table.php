<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')
                ->constrained('association')      // singulier dans ce projet
                ->cascadeOnDelete();
            $table->string('key_id', 64)->unique();
            $table->text('secret_encrypted');
            $table->string('label', 120)->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_api_keys');
    }
};
