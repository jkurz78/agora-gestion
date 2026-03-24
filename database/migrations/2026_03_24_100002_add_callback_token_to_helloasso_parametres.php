<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->string('callback_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->dropColumn('callback_token');
        });
    }
};
