<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('source'); // manual-upload, inbox, formulaire
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_documents');
    }
};
