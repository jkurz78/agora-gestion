<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('email_templates')
            ->where('categorie', 'attestation')
            ->whereNull('type_operation_id')
            ->exists();

        if (! $exists) {
            DB::table('email_templates')->insert([
                'categorie' => 'attestation',
                'type_operation_id' => null,
                'objet' => 'Attestation de présence — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom}</strong>,</p>'
                    .'<p>Veuillez trouver ci-joint votre attestation de présence pour {type_operation} « <strong>{operation}</strong> ».</p>'
                    .'{bloc_seances}'
                    .'<p>Cordialement,<br>L\'équipe</p>',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('categorie', 'attestation')
            ->whereNull('type_operation_id')
            ->delete();
    }
};
