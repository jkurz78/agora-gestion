<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class CsvImportController extends Controller
{
    public function template(string $type): Response
    {
        $headers_csv = 'date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation';

        // 3 example rows based on type
        if ($type === 'depense') {
            $rows = [
                '2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat papeterie;MAISON DUPONT;',
                '2024-09-15;FAC-001;Communication;50.00;;;;;',
                '2024-09-20;CHQ-042;Déplacements;75.00;cheque;Compte principal;Frais déplacement;;AG 2024',
            ];
        } else {
            $rows = [
                '2024-09-15;SUB-001;Subventions;500.00;virement;Compte principal;Subvention mairie;;',
                '2024-09-20;ADH-042;Cotisations;30.00;cheque;Compte principal;Adhésion 2024;Jean DUPONT;',
                '2024-09-25;DON-007;Dons divers;100.00;especes;Compte principal;;;',
            ];
        }

        $content = $headers_csv . "\n" . implode("\n", $rows) . "\n";
        $filename = 'modele-' . $type . '.csv';

        return response($content, 200, [
            'Content-Type'        => 'text/csv;charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
