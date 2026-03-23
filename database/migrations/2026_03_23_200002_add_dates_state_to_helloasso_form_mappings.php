<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_form_mappings', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('form_title');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('state', 50)->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_form_mappings', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date', 'state']);
        });
    }
};
