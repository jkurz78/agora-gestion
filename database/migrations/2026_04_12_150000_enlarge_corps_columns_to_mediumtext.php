<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->mediumText('corps')->change();
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->mediumText('corps')->change();
        });

        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->mediumText('corps')->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->text('corps')->change();
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->text('corps')->change();
        });

        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->text('corps')->change();
        });
    }
};
