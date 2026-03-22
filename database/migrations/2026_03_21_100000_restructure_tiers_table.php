<?php

// database/migrations/2026_03_21_100000_restructure_tiers_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->renameColumn('adresse', 'adresse_ligne1');
            $table->string('code_postal', 10)->nullable()->after('adresse_ligne1');
            $table->string('ville', 100)->nullable()->after('code_postal');
            $table->string('pays', 100)->nullable()->default('France')->after('ville');
            $table->string('entreprise', 255)->nullable()->after('prenom');
            $table->date('date_naissance')->nullable()->after('entreprise');
            $table->string('helloasso_id', 255)->nullable()->unique()->after('date_naissance');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->renameColumn('adresse_ligne1', 'adresse');
            $table->dropColumn([
                'code_postal', 'ville', 'pays',
                'entreprise', 'date_naissance', 'helloasso_id',
            ]);
        });
    }
};
