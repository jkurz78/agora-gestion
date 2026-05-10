<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formules_adhesion', function (Blueprint $table): void {
            $table->boolean('est_helloasso')->default(false)->after('actif');
            $table->string('helloasso_form_slug', 255)->nullable()->after('est_helloasso');
            $table->unsignedInteger('helloasso_tier_id')->nullable()->after('helloasso_form_slug');

            $table->unique(
                ['association_id', 'helloasso_form_slug', 'helloasso_tier_id'],
                'formules_adhesion_helloasso_unique'
            );
            $table->index(['est_helloasso', 'actif'], 'formules_adhesion_helloasso_actif_idx');
        });
    }

    public function down(): void
    {
        Schema::table('formules_adhesion', function (Blueprint $table): void {
            $table->dropUnique('formules_adhesion_helloasso_unique');
            $table->dropIndex('formules_adhesion_helloasso_actif_idx');
            $table->dropColumn(['est_helloasso', 'helloasso_form_slug', 'helloasso_tier_id']);
        });
    }
};
