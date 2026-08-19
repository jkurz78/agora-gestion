{{--
    Tableau du rapport PDF par opérations, portée « tous les exercices ».

    Second chemin de rendu du PDF, additif au tableau existant de la portée
    courante (pdf.rapport-operations, corps @else, strictement inchangé).
    Hiérarchie Famille → Compte → Exercice → Tiers.

    Contrairement à l'écran et au classeur Excel, ce PDF reste à deux
    colonnes (libellé, montant) plutôt que de reproduire les colonnes de
    séances/opérations : dompdf ne tolère pas les mises en page complexes, et
    l'ajout d'une dimension Exercice au-dessus d'une hiérarchie déjà profonde
    rendrait un tableau multi-colonnes illisible à l'impression. Les montants
    viennent de la même résolution que l'écran et le classeur : les champs
    déjà agrégés par CompteResultatBuilder (réalisé), ou les accesseurs de
    ProjectionMatrix sur la matrice du bon exercice (projection) — jamais un
    recalcul à partir d'une date.

    Variables attendues :
      $sections   list<array{data: list<array>, label: string, total: float, projParExercice: array<int, \App\Services\Rapports\ProjectionMatrix>}>

    Héritées du parent (pdf.rapport-operations, donc de pdfOperationsData()) :
      $mode, $parTiers, $exercices, $resultatNet.
--}}
@php
    $isProjectionPdfAll = ($mode ?? 'realise') === 'projection';
    $parTiersPdfAll = $parTiers ?? false;
    $exercicesPdfAll = $exercices ?? [];
    $labelParAnneePdfAll = collect($exercicesPdfAll)->pluck('label', 'annee');

    $fmtPdfAll = fn (float $v): string => $v > 0 ? number_format($v, 2, ',', ' ').' €' : '—';

    /**
     * Nœud FAMILLE ou COMPTE : montant_exercices déjà agrégé en réalisé, somme
     * des matrices d'exercice en projection (ProjectionMatrix n'expose pas
     * d'agrégat direct par famille, d'où la boucle sur les comptes couverts).
     */
    $montantNoeudPdfAll = function (array $node, array $scIds, array $projParExercice) use ($isProjectionPdfAll): float {
        if (! $isProjectionPdfAll) {
            return (float) ($node['montant_exercices'] ?? $node['montant'] ?? 0.0);
        }

        $montant = 0.0;
        foreach ($projParExercice as $matrix) {
            foreach ($scIds as $scId) {
                $montant += (float) ($matrix->bySc()[$scId] ?? 0.0);
            }
        }

        return $montant;
    };

    /** Nœud EXERCICE (un compte, une année) : jamais une somme, chaque exercice reste sa propre ligne. */
    $montantExercicePdfAll = function (array $exEntry, int $scId, ?\App\Services\Rapports\ProjectionMatrix $matrix): float {
        if ($matrix === null) {
            return (float) ($exEntry['montant'] ?? 0.0);
        }

        return (float) ($matrix->bySc()[$scId] ?? 0.0);
    };

    /** Nœud TIERS pour un exercice donné (même logique matrice-unique). */
    $montantTiersPdfAll = function (array $tEntry, int $scId, int $tiersId, ?\App\Services\Rapports\ProjectionMatrix $matrix): float {
        if ($matrix === null) {
            return (float) ($tEntry['montant'] ?? 0.0);
        }

        return (float) ($matrix->byScTiers($scId)[$tiersId] ?? 0.0);
    };
@endphp

@foreach ($sections as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">{{ $section['label'] }}</td>
            </tr>

            @foreach ($section['data'] as $famille)
                @php
                    $scIdsFamillePdfAll = collect($famille['comptes'])->pluck('compte_id')->map(fn ($id) => (int) $id)->all();
                    $vMontantFamPdfAll = $montantNoeudPdfAll($famille, $scIdsFamillePdfAll, $section['projParExercice']);
                @endphp
                <tr class="cr-cat">
                    <td>{{ $famille['famille_nom'] }}</td>
                    <td class="text-right">{{ $fmtPdfAll($vMontantFamPdfAll) }}</td>
                </tr>

                @foreach ($famille['comptes'] as $compte)
                    @php
                        $scIdPdfAll = (int) ($compte['compte_id'] ?? 0);
                        $vMontantScPdfAll = $montantNoeudPdfAll($compte, [$scIdPdfAll], $section['projParExercice']);
                    @endphp
                    <tr class="cr-sub">
                        <td style="padding-left:16px;">{{ $compte['compte_nom'] }}</td>
                        <td class="text-right">{{ $fmtPdfAll($vMontantScPdfAll) }}</td>
                    </tr>

                    @php
                        $exercicesRealisePdfAll = collect($compte['exercices'] ?? [])->keyBy('annee');
                        $anneesDuComptePdfAll = $isProjectionPdfAll
                            ? collect($exercicesPdfAll)->pluck('annee')->all()
                            : $exercicesRealisePdfAll->keys()->all();
                        $tiersCompteLabelPdfAll = collect($compte['tiers'] ?? [])->keyBy('tiers_id');
                    @endphp

                    @foreach ($anneesDuComptePdfAll as $anneePdfAll)
                        @php
                            $matrixExPdfAll = $isProjectionPdfAll ? ($section['projParExercice'][$anneePdfAll] ?? null) : null;
                            $exEntryPdfAll = $exercicesRealisePdfAll->get($anneePdfAll, ['montant' => 0.0, 'tiers' => []]);
                            $vMontantExPdfAll = $montantExercicePdfAll($exEntryPdfAll, $scIdPdfAll, $matrixExPdfAll);
                        @endphp
                        @if ($vMontantExPdfAll > 0)
                            <tr>
                                <td style="padding-left:32px;font-style:italic;color:#777;">
                                    {{ $labelParAnneePdfAll[$anneePdfAll] ?? $exEntryPdfAll['label'] ?? '' }}
                                </td>
                                <td class="text-right" style="color:#777;">{{ $fmtPdfAll($vMontantExPdfAll) }}</td>
                            </tr>

                            @if ($parTiersPdfAll)
                                @php
                                    $tiersIdsRealisePdfAll = collect($exEntryPdfAll['tiers'] ?? [])->pluck('tiers_id')->map(fn ($id) => (int) $id)->all();
                                    $tiersIdsProjectionPdfAll = ($isProjectionPdfAll && $matrixExPdfAll !== null)
                                        ? array_map('intval', array_keys($matrixExPdfAll->byScTiers($scIdPdfAll)))
                                        : [];
                                    $tousTiersIdsPdfAll = collect(array_merge($tiersIdsRealisePdfAll, $tiersIdsProjectionPdfAll))->unique()->all();
                                    $tiersRealiseParIdPdfAll = collect($exEntryPdfAll['tiers'] ?? [])->keyBy('tiers_id');
                                @endphp
                                @foreach ($tousTiersIdsPdfAll as $tiersIdPdfAll)
                                    @php
                                        $tEntryPdfAll = $tiersRealiseParIdPdfAll->get($tiersIdPdfAll, ['montant' => 0.0, 'tiers_id' => $tiersIdPdfAll]);
                                        $vMontantTPdfAll = $montantTiersPdfAll($tEntryPdfAll, $scIdPdfAll, (int) $tiersIdPdfAll, $matrixExPdfAll);
                                        $labelTPdfAll = $tEntryPdfAll['label'] ?? ($tiersCompteLabelPdfAll[$tiersIdPdfAll]['label'] ?? '(sans tiers)');
                                    @endphp
                                    @if ($vMontantTPdfAll > 0)
                                        <tr class="cr-tiers">
                                            <td style="padding-left:48px;">{{ $labelTPdfAll }}</td>
                                            <td class="text-right">{{ $fmtPdfAll($vMontantTPdfAll) }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            @endif
                        @endif
                    @endforeach
                @endforeach
            @endforeach

            <tr class="cr-total">
                <td>TOTAL {{ $section['label'] }}</td>
                <td class="text-right">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
            </tr>
        </tbody>
    </table>
@endforeach

<div class="{{ ($resultatNet ?? 0) >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
    {{ ($resultatNet ?? 0) >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet ?? 0), 2, ',', ' ') }} €
</div>
