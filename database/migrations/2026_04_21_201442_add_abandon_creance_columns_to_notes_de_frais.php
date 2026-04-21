<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes_de_frais', function (Blueprint $table) {
            $table->boolean('abandon_creance_propose')->default(false)->after('statut');
            $table->foreignId('don_transaction_id')->nullable()->after('transaction_id')->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes_de_frais', function (Blueprint $table) {
            $table->dropForeign(['don_transaction_id']);
            $table->dropColumn(['abandon_creance_propose', 'don_transaction_id']);
        });
    }
};
