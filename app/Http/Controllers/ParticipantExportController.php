<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ParticipantExportController extends Controller
{
    public function __invoke(Request $request, Operation $operation): BinaryFileResponse
    {
        $showParcours = $request->boolean('confidentiel')
            && ($operation->typeOperation?->formulaire_parcours_therapeutique ?? false)
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $showPrescripteur = $request->boolean('confidentiel')
            && ($operation->typeOperation?->formulaire_prescripteur ?? false)
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $showDroitImage = $request->boolean('confidentiel')
            && ($operation->typeOperation?->formulaire_droit_image ?? false)
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar', 'donneesMedicales', 'medecinTiers', 'therapeuteTiers', 'typeOperationTarif'])
            ->get();

        $filename = 'participants-'.Str::slug($operation->nom).'-'.now()->format('Y-m-d').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($tempPath);

        $headerStyle = (new Style)->withFontBold(true);
        $headers = ['Nom', 'Prénom', 'Adresse', 'Code postal', 'Ville', 'Téléphone', 'Email', 'Date inscription'];
        if ($showParcours) {
            $headers = array_merge($headers, ['Référé par', 'Date naissance', 'Âge', 'Sexe', 'Taille', 'Poids', 'Notes']);
            $headers = array_merge($headers, [
                'Nom de jeune fille', 'Nationalité',
                'Médecin nom', 'Médecin prénom', 'Médecin tél', 'Médecin email', 'Médecin adresse', 'Médecin CP', 'Médecin ville',
                'Thérapeute nom', 'Thérapeute prénom', 'Thérapeute tél', 'Thérapeute email', 'Thérapeute adresse', 'Thérapeute CP', 'Thérapeute ville',
                'Autorisation contact médecin',
                'Mode paiement', 'Moyen paiement',
                'Tarif', 'Montant séance',
                'RGPD accepté le',
            ]);
        }
        if ($showPrescripteur) {
            $headers = array_merge($headers, [
                'Adressé par étab.', 'Adressé par nom', 'Adressé par prénom', 'Adressé par tél', 'Adressé par email', 'Adressé par adresse', 'Adressé par CP', 'Adressé par ville',
            ]);
        }
        if ($showDroitImage) {
            $headers[] = 'Droit à l\'image';
        }
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        foreach ($participants as $p) {
            $row = [
                $p->tiers->nom ?? '',
                $p->tiers->prenom ?? '',
                $p->tiers->adresse_ligne1 ?? '',
                $p->tiers->code_postal ?? '',
                $p->tiers->ville ?? '',
                $p->tiers->telephone ?? '',
                $p->tiers->email ?? '',
                $p->date_inscription?->format('d/m/Y') ?? '',
            ];
            if ($showParcours) {
                $row[] = $p->referePar?->displayName() ?? '';
                $med = $p->donneesMedicales;
                $dateNaisRaw = $med?->date_naissance ?? '';
                $dateNaisFormatted = '';
                $age = null;
                if ($dateNaisRaw !== '') {
                    try {
                        $carbon = Carbon::parse($dateNaisRaw);
                        $dateNaisFormatted = $carbon->format('d/m/Y');
                        $age = $carbon->age;
                    } catch (\Throwable) {
                    }
                }
                $taille = $med?->taille !== null && $med->taille !== '' ? (int) $med->taille : null;
                $poids = $med?->poids !== null && $med->poids !== '' ? (int) $med->poids : null;
                $notes = $med?->notes ?? '';
                $notesPlain = '';
                if ($notes !== '') {
                    $text = str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", $notes);
                    $notesPlain = trim(html_entity_decode(strip_tags($text)));
                }

                $row = array_merge($row, [
                    $dateNaisFormatted,
                    $age,
                    $med?->sexe ?? '',
                    $taille,
                    $poids,
                    $notesPlain,
                ]);

                // Nom de jeune fille, Nationalité
                $row[] = $p->nom_jeune_fille ?? '';
                $row[] = $p->nationalite ?? '';

                // Médecin — priorité au Tiers mappé, fallback texte chiffré
                $medTiers = $p->medecinTiers;
                $row[] = $medTiers?->nom ?? $med?->medecin_nom ?? '';
                $row[] = $medTiers?->prenom ?? $med?->medecin_prenom ?? '';
                $row[] = $medTiers?->telephone ?? $med?->medecin_telephone ?? '';
                $row[] = $medTiers?->email ?? $med?->medecin_email ?? '';
                $row[] = $medTiers?->adresse_ligne1 ?? $med?->medecin_adresse ?? '';
                $row[] = $medTiers?->code_postal ?? $med?->medecin_code_postal ?? '';
                $row[] = $medTiers?->ville ?? $med?->medecin_ville ?? '';

                // Thérapeute — priorité au Tiers mappé, fallback texte chiffré
                $therTiers = $p->therapeuteTiers;
                $row[] = $therTiers?->nom ?? $med?->therapeute_nom ?? '';
                $row[] = $therTiers?->prenom ?? $med?->therapeute_prenom ?? '';
                $row[] = $therTiers?->telephone ?? $med?->therapeute_telephone ?? '';
                $row[] = $therTiers?->email ?? $med?->therapeute_email ?? '';
                $row[] = $therTiers?->adresse_ligne1 ?? $med?->therapeute_adresse ?? '';
                $row[] = $therTiers?->code_postal ?? $med?->therapeute_code_postal ?? '';
                $row[] = $therTiers?->ville ?? $med?->therapeute_ville ?? '';

                // Autorisation contact médecin
                $row[] = $p->autorisation_contact_medecin ? 'Oui' : 'Non';

                // Mode / moyen paiement
                $row[] = $p->mode_paiement_choisi ?? '';
                $row[] = $p->moyen_paiement_choisi ?? '';

                // Tarif
                $row[] = $p->typeOperationTarif?->libelle ?? '';
                $row[] = $p->typeOperationTarif?->montant ?? '';

                // RGPD
                $row[] = $p->rgpd_accepte_at?->format('d/m/Y H:i') ?? '';
            }
            if ($showPrescripteur) {
                $row[] = $p->adresse_par_etablissement ?? '';
                $row[] = $p->adresse_par_nom ?? '';
                $row[] = $p->adresse_par_prenom ?? '';
                $row[] = $p->adresse_par_telephone ?? '';
                $row[] = $p->adresse_par_email ?? '';
                $row[] = $p->adresse_par_adresse ?? '';
                $row[] = $p->adresse_par_code_postal ?? '';
                $row[] = $p->adresse_par_ville ?? '';
            }
            if ($showDroitImage) {
                $row[] = $p->droit_image?->label() ?? '';
            }
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
