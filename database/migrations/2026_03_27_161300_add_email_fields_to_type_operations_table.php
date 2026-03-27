<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->string('email_from', 255)->nullable()->after('logo_path');
            $table->string('email_from_name', 255)->nullable()->after('email_from');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropColumn(['email_from', 'email_from_name']);
        });
    }
};
