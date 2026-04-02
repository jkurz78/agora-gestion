<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            [
                'categorie' => 'formulaire',
                'objet' => 'Action requise : Formulaire à compléter pour votre inscription {type_operation} « {operation} »',
                'corps' => '<p>{logo_operation}</p>'
                    .'<p>Bonjour {prenom},</p>'
                    .'<p>Afin de compléter votre dossier d\'inscription {type_operation} « {operation} », nous vous remercions de compléter le formulaire dont le lien est ci-dessous.</p>'
                    .'<p>Rappel : ce parcours se déroulera sur {nb_seances} séances du {date_debut} au {date_fin}.</p>'
                    .'<p>Cordialement,<br>L\'équipe encadrante</p>',
            ],
            [
                'categorie' => 'attestation',
                'objet' => 'Attestation de présence — {operation}',
                'corps' => '<p>{logo_operation}</p>'
                    .'<p>Bonjour <strong>{prenom}</strong>,</p>'
                    .'<p>Veuillez trouver ci-joint votre attestation de présence pour {type_operation} « <strong>{operation}</strong> ».</p>'
                    .'{bloc_seances}'
                    .'<p>Cordialement,<br>L\'équipe encadrante</p>',
            ],
        ];

        foreach ($defaults as $tpl) {
            $exists = DB::table('email_templates')
                ->where('categorie', $tpl['categorie'])
                ->whereNull('type_operation_id')
                ->exists();

            if (! $exists) {
                DB::table('email_templates')->insert([
                    'categorie' => $tpl['categorie'],
                    'type_operation_id' => null,
                    'objet' => $tpl['objet'],
                    'corps' => $tpl['corps'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Ne pas supprimer — les gabarits peuvent avoir été personnalisés
    }
};
