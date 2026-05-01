<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extournes', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('transaction_origine_id');
            $table->foreign('transaction_origine_id')
                ->references('id')->on('transactions')
                ->restrictOnDelete();

            $table->unsignedBigInteger('transaction_extourne_id');
            $table->foreign('transaction_extourne_id')
                ->references('id')->on('transactions')
                ->restrictOnDelete();

            $table->unsignedBigInteger('rapprochement_lettrage_id')->nullable();
            $table->foreign('rapprochement_lettrage_id')
                ->references('id')->on('rapprochements_bancaires')
                ->restrictOnDelete();

            $table->unsignedBigInteger('association_id');
            $table->foreign('association_id')
                ->references('id')->on('association')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('transaction_origine_id');
            $table->unique('transaction_extourne_id');
            $table->index('association_id');
            $table->index('rapprochement_lettrage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extournes');
    }
};
