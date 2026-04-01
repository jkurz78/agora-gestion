<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_previsionnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations');
            $table->foreignId('participant_id')->constrained('participants');
            $table->string('type');
            $table->string('numero')->unique();
            $table->unsignedInteger('version');
            $table->date('date');
            $table->decimal('montant_total', 10, 2);
            $table->json('lignes_json');
            $table->string('pdf_path')->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->integer('exercice');
            $table->timestamps();

            $table->unique(['operation_id', 'participant_id', 'type', 'version'], 'doc_prev_op_part_type_ver_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_previsionnels');
    }
};
