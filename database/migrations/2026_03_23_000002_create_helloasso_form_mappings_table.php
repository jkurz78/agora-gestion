<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helloasso_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helloasso_parametres_id')->constrained('helloasso_parametres')->cascadeOnDelete();
            $table->string('form_slug');
            $table->string('form_type');
            $table->string('form_title')->nullable();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['helloasso_parametres_id', 'form_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_form_mappings');
    }
};
