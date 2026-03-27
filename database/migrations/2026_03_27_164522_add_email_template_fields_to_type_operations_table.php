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
            $table->string('email_formulaire_objet', 255)->nullable()->after('email_from_name');
            $table->text('email_formulaire_corps')->nullable()->after('email_formulaire_objet');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropColumn(['email_formulaire_objet', 'email_formulaire_corps']);
        });
    }
};
