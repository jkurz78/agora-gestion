<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_systeme', 20)->default('user')->after('role');
            $table->foreignId('derniere_association_id')
                ->nullable()
                ->after('dernier_espace')
                ->constrained('association')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derniere_association_id');
            $table->dropColumn('role_systeme');
        });
    }
};
