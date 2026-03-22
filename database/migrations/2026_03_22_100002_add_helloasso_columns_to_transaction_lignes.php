<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_item_id')->nullable()->unique()->after('notes');
            $table->unsignedInteger('exercice')->nullable()->after('helloasso_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->dropUnique(['helloasso_item_id']);
            $table->dropColumn(['helloasso_item_id', 'exercice']);
        });
    }
};
