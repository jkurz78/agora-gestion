<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')
                ->constrained('association')
                ->cascadeOnDelete();

            $table->string('email', 255);
            $table->string('prenom', 100)->nullable();

            $table->enum('status', ['pending', 'confirmed', 'unsubscribed'])
                ->default('pending');

            $table->string('confirmation_token_hash', 64)->nullable();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->string('unsubscribe_token_hash', 64)->nullable();

            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->foreignId('tiers_id')
                ->nullable()
                ->constrained('tiers')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('email');
            $table->index('confirmation_token_hash');
            $table->unique('unsubscribe_token_hash');
            $table->index(['association_id', 'status']);
            $table->index(['association_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscription_requests');
    }
};
