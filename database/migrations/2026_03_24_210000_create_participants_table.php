<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('operation_id')->constrained('operations');
            $table->date('date_inscription');
            $table->boolean('est_helloasso')->default(false);
            $table->unsignedInteger('helloasso_item_id')->nullable();
            $table->unsignedInteger('helloasso_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tiers_id', 'operation_id']);
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
