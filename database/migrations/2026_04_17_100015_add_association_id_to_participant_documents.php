<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('association_id')->nullable()->after('id');
            $table->index('association_id');
        });

        // Backfill association_id depuis la table participants (compatible MySQL + SQLite)
        DB::statement('
            UPDATE participant_documents
            SET association_id = (
                SELECT association_id FROM participants WHERE participants.id = participant_documents.participant_id
            )
        ');

        // Rendre non-nullable après backfill
        Schema::table('participant_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('association_id')->nullable(false)->change();
            $table->foreign('association_id')->references('id')->on('association')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('participant_documents', function (Blueprint $table) {
            $table->dropForeign(['association_id']);
            $table->dropIndex(['association_id']);
            $table->dropColumn('association_id');
        });
    }
};
