<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `lettrage_audit` append-only audit log table (spec §2.5).
 *
 * Design decisions:
 * - Append-only: only `created_at`, no `updated_at`, no `deleted_at`.
 * - `action` ENUM('lettre', 'delettre') — exactly the two operations on a lettrage.
 * - `transaction_ligne_ids` JSON — the list of ligne IDs affected by this event.
 * - `user_id` nullable — system-generated lettrages have no actor user (e.g. auto-lettrage on remise).
 * - FK cascade chain: association → comptes → lettrage_audit all cascade on delete.
 * - FK on users: nullOnDelete — preserves audit history even when the actor is RGPD-purged.
 *
 * Deferred: Eloquent model + LettrageService (created in a later step, likely Step 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lettrage_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->enum('action', ['lettre', 'delettre']);
            $table->string('lettrage_code', 20);
            $table->foreignId('compte_id')->constrained('comptes')->cascadeOnDelete();
            $table->json('transaction_ligne_ids');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['association_id', 'lettrage_code'], 'lettrage_audit_asso_code_idx');
            $table->index(['association_id', 'created_at'], 'lettrage_audit_asso_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lettrage_audit');
    }
};
