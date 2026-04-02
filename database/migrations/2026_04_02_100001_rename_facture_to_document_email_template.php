<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Supprimer l'ancien template « facture » (remplacé par « document »)
        DB::table('email_templates')
            ->where('categorie', 'facture')
            ->delete();

        // Créer le template « document » par défaut (si absent)
        $exists = DB::table('email_templates')
            ->where('categorie', 'document')
            ->whereNull('type_operation_id')
            ->exists();

        if (! $exists) {
            DB::table('email_templates')->insert([
                'categorie' => 'document',
                'type_operation_id' => null,
                'objet' => '{type_document_uc} n° {numero_document}',
                'corps' => '<p>{logo_operation}</p>'
                    .'<p>Bonjour <strong>{prenom} {nom}</strong>,</p>'
                    .'<p>Veuillez trouver ci-joint {type_document_article} n° <strong>{numero_document}</strong> '
                    .'du {date_document} d\'un montant de {montant_total}.</p>'
                    .'<p>Cordialement,<br>Le trésorier de l\'association</p>',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Ne pas supprimer — le gabarit peut avoir été personnalisé
    }
};
