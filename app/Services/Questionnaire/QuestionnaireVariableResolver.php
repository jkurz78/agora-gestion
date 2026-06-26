<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\Association;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\Seance;
use App\Support\CurrentAssociation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class QuestionnaireVariableResolver
{
    /**
     * Clés dont la valeur est du HTML construit en interne — ne doit PAS être échappée dans remplacer().
     *
     * @var array<int, string>
     */
    private const HTML_KEYS = ['{table_seances}', '{table_seances_a_venir}', '{logo}', '{bloc_liens}'];

    /**
     * @return array<string, string>
     */
    public function pour(QuestionnaireInvitation $invitation, bool $avecLien = false): array
    {
        $participant = $invitation->participant;
        $tiers = $participant?->tiers;
        $operation = $invitation->campaign->operation;

        $civilite = (string) ($tiers?->civilite?->value ?? '');
        $politesse = (string) ($tiers?->civilite?->label() ?? '');
        $prenom = (string) ($tiers?->prenom ?? '');
        $nom = (string) ($tiers?->nom ?? '');
        $nomSeul = trim($nom);
        $prenomNom = trim($prenom.' '.$nom);

        $vars = [
            '{prenom}' => $prenom,
            '{nom}' => $nom,
            '{email_participant}' => (string) ($tiers?->email ?? ''),
            '{civilite}' => $civilite,
            '{politesse}' => $politesse,
            '{civilite_nom}' => $civilite !== '' ? trim($civilite.' '.$nomSeul) : $nomSeul,
            '{politesse_nom}' => $politesse !== '' ? trim($politesse.' '.$nomSeul) : $nomSeul,
            '{civilite_prenom_nom}' => $civilite !== '' ? trim($civilite.' '.$prenomNom) : $prenomNom,
            '{politesse_prenom_nom}' => $politesse !== '' ? trim($politesse.' '.$prenomNom) : $prenomNom,
            '{salutation}' => $politesse !== '' ? $politesse : 'Madame, Monsieur',
            '{operation}' => (string) ($operation?->nom ?? ''),
            '{type_operation}' => (string) ($operation?->typeOperation?->nom ?? ''),
            '{association}' => (string) (CurrentAssociation::tryGet()?->nom ?? ''),
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? '',
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? '',
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? ''),
            '{table_seances}' => $this->buildTableSeances($operation, false),
            '{table_seances_a_venir}' => $this->buildTableSeances($operation, true),
            '{logo}' => $this->buildLogoImg(CurrentAssociation::tryGet()),
        ];

        if ($avecLien) {
            $url = $invitation->lienReponse();
            $codeCourt = $invitation->code_court ?? '';

            $vars['{lien_questionnaire}'] = $url;
            $vars['{bloc_liens}'] = $this->buildBlocLiens($url, $codeCourt);
        }

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    public function exemple(?QuestionnaireCampaign $campagne = null): array
    {
        $operation = $campagne?->operation;

        return [
            '{prenom}' => 'Jean',
            '{nom}' => 'Dupont',
            '{email_participant}' => 'jean.dupont@example.fr',
            '{civilite}' => 'M.',
            '{politesse}' => 'Monsieur',
            '{civilite_nom}' => 'M. DUPONT',
            '{politesse_nom}' => 'Monsieur DUPONT',
            '{civilite_prenom_nom}' => 'M. Jean Dupont',
            '{politesse_prenom_nom}' => 'Monsieur Jean Dupont',
            '{salutation}' => 'Madame, Monsieur',
            '{operation}' => $operation?->nom ?? 'Mon opération',
            '{type_operation}' => $operation?->typeOperation?->nom ?? 'Type d\'opération',
            '{association}' => CurrentAssociation::tryGet()?->nom ?? 'Mon association',
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? now()->format('d/m/Y'),
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? now()->addMonth()->format('d/m/Y'),
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? '6'),
            '{table_seances}' => '',
            '{table_seances_a_venir}' => '',
            '{logo}' => $this->buildLogoImg(CurrentAssociation::tryGet()),
            '{lien_questionnaire}' => 'https://example.com/q/exemple-token',
            '{bloc_liens}' => $this->buildBlocLiens('https://example.com/q/exemple-token', ''),
        ];
    }

    /**
     * Remplace les {variables} dans le HTML.
     *
     * Les valeurs scalaires sont échappées (anti-injection HTML).
     * Exception : les clés HTML_KEYS ({table_seances}, {table_seances_a_venir}) sont
     * construites en interne depuis des données DB et insérées brutes.
     *
     * @param  array<string, string>  $vars
     */
    public function remplacer(string $html, array $vars): string
    {
        $htmlKeys = self::HTML_KEYS;

        $echappees = array_map(
            static fn (string $v, string $k): string => in_array($k, $htmlKeys, true) ? $v : e($v),
            $vars,
            array_keys($vars),
        );

        return strtr($html, array_combine(array_keys($vars), $echappees));
    }

    private function buildBlocLiens(string $url, string $codeCourt): string
    {
        $urlSafe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<p style="text-align:center;margin:25px 0;">'
            .'<a href="'.$urlSafe.'" style="display:inline-block;padding:10px 24px;background:#3d5473;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">'
            .'Accéder au questionnaire</a></p>';
    }

    /**
     * Construit un <img> inline (data URI) pour le logo de l'association.
     * Retourne '' si aucun logo n'est défini ou si le fichier est absent.
     * La data URI évite toute dépendance à un domaine ou une URL signée.
     */
    private function buildLogoImg(?Association $association): string
    {
        if ($association === null) {
            return '';
        }

        $fullPath = $association->brandingLogoFullPath();
        if ($fullPath === null || ! Storage::disk('local')->exists($fullPath)) {
            return '';
        }

        $contents = Storage::disk('local')->get($fullPath);
        $mime = Storage::disk('local')->mimeType($fullPath) ?: 'image/png';
        $dataUri = 'data:'.$mime.';base64,'.base64_encode((string) $contents);

        return '<img src="'.htmlspecialchars($dataUri).'" alt="Logo" style="max-height:60px;height:auto;width:auto;">';
    }

    /**
     * Construit un tableau HTML des séances de l'opération (même logique que MessageLibreMail::buildTableSeances).
     * Retourne '' si l'opération est null ou sans séances.
     */
    private function buildTableSeances(?Operation $operation, bool $aVenirOnly): string
    {
        if ($operation === null) {
            return '';
        }

        /** @var Collection<int, Seance> $seances */
        $seances = $operation->seances;

        if ($seances->isEmpty()) {
            return '';
        }

        $today = now()->startOfDay();
        $filtered = $aVenirOnly
            ? $seances->filter(fn (Seance $s) => $s->date && $s->date->gte($today))
            : $seances;

        if ($filtered->isEmpty()) {
            return '';
        }

        $rows = '';
        foreach ($filtered->sortBy('date') as $s) {
            $rows .= '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #ddd;text-align:center">'.$s->numero.'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.$s->date?->format('d/m/Y').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e($s->titre_affiche).'</td>'
                .'</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:13px">'
            .'<tr style="background:#3d5473;color:#fff">'
            .'<th style="padding:6px 10px;text-align:center;width:50px">N°</th>'
            .'<th style="padding:6px 10px;width:100px">Date</th>'
            .'<th style="padding:6px 10px">Titre</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }
}
