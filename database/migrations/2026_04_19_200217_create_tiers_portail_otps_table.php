<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers_portail_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('associations')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_sent_at');
            $table->timestamps();
            $table->index(['association_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers_portail_otps');
    }
};
