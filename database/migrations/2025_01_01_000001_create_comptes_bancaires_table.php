<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_bancaires', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->string('iban', 34)->nullable();
            $table->decimal('solde_initial', 10, 2)->default(0);
            $table->date('date_solde_initial');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_bancaires');
    }
};
