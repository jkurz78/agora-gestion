<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('sender_email');
            $table->string('recipient_email')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at');
            $table->string('source_message_id')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->string('handler_attempted', 64)->nullable();
            $table->string('reason', 64);
            $table->text('reason_detail')->nullable();
            $table->timestamps();

            $table->index('source_message_id');
            $table->index(['association_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_documents');
    }
};
