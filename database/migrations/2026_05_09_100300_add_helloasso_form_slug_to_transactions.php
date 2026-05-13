<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('helloasso_form_slug')->nullable()->after('helloasso_payment_id');
            $table->index('helloasso_form_slug', 'transactions_helloasso_form_slug_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_helloasso_form_slug_idx');
            $table->dropColumn('helloasso_form_slug');
        });
    }
};
