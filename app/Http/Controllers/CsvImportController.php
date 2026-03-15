<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class CsvImportController extends Controller
{
    public function template(string $type): Response
    {
        if (!in_array($type, ['depense', 'recette'], true)) {
            abort(404);
        }

        $headers_csv = 'date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation;seance;notes';

        // 3 example rows based on type
        if ($type === 'depense') {
            $rows = [
                '2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat papeterie;MAISON DUPONT;;',
                '2024-09-15;FAC-001;Animation / Encadrement;50.00;;;;;AG 2024;3;Intervenant externe',
                '2024-09-20;CHQ-042;Frais de déplacements;75.00;cheque;Compte principal;Frais déplacement;;AG 2024;;',
            ];
        } else {
            $rows = [
                '2024-09-15;SUB-001;Subvention État Ministère des Sports;500.00;virement;Compte principal;Subvention mairie;;;',
                '2024-09-20;ADH-042;Cotisations;30.00;cheque;Compte principal;Adhésion 2024;Jean DUPONT;;',
                '2024-09-25;FOR-007;Formations;100.00;especes;Compte principal;;Association X;Stage été;2;Séance 2 sur 4',
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
