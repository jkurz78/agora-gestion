<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_logs')
            ->where('categorie', 'operation')
            ->update(['categorie' => 'message']);

        DB::table('email_templates')
            ->where('categorie', 'operation')
            ->update(['categorie' => 'message']);

        DB::table('message_templates')
            ->where('categorie', 'operation')
            ->update(['categorie' => 'message']);
    }

    public function down(): void
    {
        // Rollback intentionnellement no-op : on ne peut pas distinguer
        // les rangées originellement 'operation' des rangées originellement 'message'.
        // Si rollback nécessaire, restaurer depuis backup.
    }
};
