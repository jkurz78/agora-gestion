{{--
    Tableau du rapport par opérations, portée « tous les exercices ».

    Second chemin de rendu, additif au tableau existant de la portée courante
    (rapport-compte-resultat-operations.blade.php), qui reste inchangé. La
    hiérarchie affichée est Famille → Compte → Exercice → Tiers : l'exercice
    est une dimension de LIGNE supplémentaire, insérée entre le compte et ses
    tiers.

    Ce partial ne fait ni requête ni calcul d'exercice — les montants viennent
    des champs déjà agrégés par CompteResultatBuilder (réalisé), ou sont
    résolus via les accesseurs de ProjectionMatrix sur la matrice du bon
    exercice (projection). La résolution est écrite une fois ci-dessous et
    réutilisée à chaque niveau ; le rendu des colonnes lui-même est délégué au
    partial partagé rapport-operations-cellules.blade.php.

    Variables attendues :
      $sections   list<array{data: list<array>, label: string, total: float, projParExercice: array<int, \App\Services\Rapports\ProjectionMatrix>}>

    Héritées du parent (rapport-compte-resultat-operations.blade.php) :
      $mode, $combinedMode, $parSeances, $parTiers, $parOperations, $seances,
      $operationNames, $seancesParOperation, $totalColspan, $exercices,
      $resultatNet.
--}}
@php
    $isProjectionAll = $mode === 'projection';

    // [annee => label], pour retrouver le libellé d'un exercice même quand le
    // compte n'a pas d'entrée réalisée pour cette année (compte apparu
    // uniquement par une prévision).
    $labelParAnneeAll = collect($exercices)->pluck('label', 'annee');

    /**
     * Résout (montant, séances, opérations, séance×opérations) pour un nœud
     * FAMILLE ou COMPTE :
     *  - réalisé : les champs déjà agrégés sur tous les exercices affichés ;
     *  - projection : la somme, sur chaque matrice d'exercice, des cellules
     *    des comptes couverts par le nœud (un seul compte pour un compte, tous
     *    ses comptes pour une famille — ProjectionMatrix n'expose pas
     *    d'agrégat direct par famille et par séance).
     */
    $resoudreNoeudAll = function (array $node, array $scIds, array $projParExercice) use ($isProjectionAll): array {
        if (! $isProjectionAll) {
            return [
                (float) ($node['montant_exercices'] ?? $node['montant'] ?? 0.0),
                $node['seances'] ?? [],
                $node['operations'] ?? [],
                $node['seance_operations'] ?? [],
            ];
        }

        $montant = 0.0;
        $seances = [];
        $operations = [];
        $seanceOperations = [];
        foreach ($projParExercice as $matrix) {
            foreach ($scIds as $scId) {
                $montant += (float) ($matrix->bySc()[$scId] ?? 0.0);
                foreach ($matrix->byScSeance()[$scId] ?? [] as $s => $v) {
                    $seances[$s] = ($seances[$s] ?? 0.0) + $v;
                }
                foreach ($matrix->byScOp()[$scId] ?? [] as $opId => $v) {
                    $operations[$opId] = ($operations[$opId] ?? 0.0) + $v;
                }
                foreach ($matrix->byScSeanceOp()[$scId] ?? [] as $s => $ops) {
                    foreach ($ops as $opId => $v) {
                        $seanceOperations[$s][$opId] = ($seanceOperations[$s][$opId] ?? 0.0) + $v;
                    }
                }
            }
        }

        return [$montant, $seances, $operations, $seanceOperations];
    };

    /**
     * Résout un nœud EXERCICE (un compte, une année) : réalisé = l'entrée
     * d'exercice du compte ; projection = la matrice de CET exercice seul —
     * jamais une somme, chaque exercice reste sa propre ligne.
     */
    $resoudreExerciceAll = function (array $exEntry, int $scId, ?\App\Services\Rapports\ProjectionMatrix $matrix): array {
        if ($matrix === null) {
            return [
                (float) ($exEntry['montant'] ?? 0.0),
                $exEntry['seances'] ?? [],
                $exEntry['operations'] ?? [],
                $exEntry['seance_operations'] ?? [],
            ];
        }

        return [
            (float) ($matrix->bySc()[$scId] ?? 0.0),
            $matrix->byScSeance()[$scId] ?? [],
            $matrix->byScOp()[$scId] ?? [],
            $matrix->byScSeanceOp()[$scId] ?? [],
        ];
    };

    /** Résout un nœud TIERS pour un exercice donné (même logique matrice-unique). */
    $resoudreTiersAll = function (array $tEntry, int $scId, int $tiersId, ?\App\Services\Rapports\ProjectionMatrix $matrix): array {
        if ($matrix === null) {
            return [
                (float) ($tEntry['montant'] ?? 0.0),
                $tEntry['seances'] ?? [],
                $tEntry['operations'] ?? [],
                $tEntry['seance_operations'] ?? [],
            ];
        }

        return [
            (float) ($matrix->byScTiers($scId)[$tiersId] ?? 0.0),
            $matrix->byScTiersSeance($scId)[$tiersId] ?? [],
            $matrix->byScTiersOp($scId)[$tiersId] ?? [],
            $matrix->byScTiersSeanceOp($scId)[$tiersId] ?? [],
        ];
    };
@endphp

@foreach ($sections as $section)
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    {{-- En-tête colonnes --}}
                    @if ($combinedMode)
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($operationNames as $opId => $opNom)
                                @php $opColspanAll = count($seancesParOperation[$opId] ?? []) + 1; @endphp
                                <td colspan="{{ $opColspanAll }}" class="text-center" style="font-size:11px;opacity:.85;padding:4px 4px;border-left:1px solid rgba(255,255,255,.2);" title="{{ $opNom }}">
                                    {{ \Illuminate\Support\Str::limit($opNom, 25) }}
                                </td>
                            @endforeach
                            <td rowspan="2" class="text-end align-bottom" style="width:70px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                        </tr>
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($operationNames as $opId => $opNom)
                                @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                    <td class="text-end" style="width:60px;font-size:10px;opacity:.75;padding:3px 5px;">{{ $s === 0 ? 'H.S.' : 'S'.$s }}</td>
                                @endforeach
                                <td class="text-end" style="width:60px;font-size:10px;opacity:.85;padding:3px 5px;border-left:1px solid rgba(255,255,255,.15);font-weight:600;">Tot.</td>
                            @endforeach
                        </tr>
                    @elseif ($parSeances)
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($seances as $s)
                                <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">{{ $s === 0 ? 'Hors séances' : 'S'.$s }}</td>
                            @endforeach
                            <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                        </tr>
                    @elseif ($parOperations)
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($operationNames as $opId => $opNom)
                                <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;" title="{{ $opNom }}">
                                    {{ \Illuminate\Support\Str::limit($opNom, 20) }}
                                </td>
                            @endforeach
                            <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                        </tr>
                    @else
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">{{ $isProjectionAll ? 'Projeté' : 'Montant' }}</td>
                        </tr>
                    @endif

                    {{-- Titre section --}}
                    <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                        <td colspan="{{ $totalColspan }}" style="padding:4px 12px 10px;">{{ $section['label'] }}</td>
                    </tr>

                    @foreach ($section['data'] as $famille)
                        @php
                            $scIdsFamilleAll = collect($famille['comptes'])->pluck('compte_id')->map(fn ($id) => (int) $id)->all();
                            [$vMontantFam, $vSeancesFam, $vOperationsFam, $vSeanceOperationsFam]
                                = $resoudreNoeudAll($famille, $scIdsFamilleAll, $section['projParExercice']);
                        @endphp
                        {{-- Ligne famille --}}
                        <tr style="background:#dce6f0;">
                            <td></td>
                            <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $famille['famille_nom'] }}</td>
                            @include('livewire.partials.rapport-operations-cellules', [
                                'vMontant' => $vMontantFam,
                                'vSeances' => $vSeancesFam,
                                'vOperations' => $vOperationsFam,
                                'vSeanceOperations' => $vSeanceOperationsFam,
                                'cellStyle' => 'padding:5px 4px;font-size:11px;',
                                'totalStyle' => 'padding:5px 8px;',
                            ])
                        </tr>

                        @foreach ($famille['comptes'] as $compte)
                            @php
                                $scIdAll = (int) ($compte['compte_id'] ?? 0);
                                [$vMontantSc, $vSeancesSc, $vOperationsSc, $vSeanceOperationsSc]
                                    = $resoudreNoeudAll($compte, [$scIdAll], $section['projParExercice']);
                            @endphp
                            {{-- Ligne compte --}}
                            <tr style="background:#f7f9fc;">
                                <td></td>
                                <td style="padding:5px 12px 5px 32px;color:#444;">{{ $compte['compte_nom'] }}</td>
                                @include('livewire.partials.rapport-operations-cellules', [
                                    'vMontant' => $vMontantSc,
                                    'vSeances' => $vSeancesSc,
                                    'vOperations' => $vOperationsSc,
                                    'vSeanceOperations' => $vSeanceOperationsSc,
                                    'cellStyle' => 'padding:4px 4px;font-size:11px;color:#444;',
                                    'totalStyle' => 'padding:4px 8px;color:#444;',
                                ])
                            </tr>

                            @php
                                $exercicesRealiseAll = collect($compte['exercices'] ?? [])->keyBy('annee');
                                $anneesDuCompteAll = $isProjectionAll
                                    ? collect($exercices)->pluck('annee')->all()
                                    : $exercicesRealiseAll->keys()->all();
                                $tiersCompteLabelAll = collect($compte['tiers'] ?? [])->keyBy('tiers_id');
                            @endphp

                            @foreach ($anneesDuCompteAll as $anneeAll)
                                @php
                                    $matrixExAll = $isProjectionAll ? ($section['projParExercice'][$anneeAll] ?? null) : null;
                                    $exEntryAll = $exercicesRealiseAll->get($anneeAll, ['montant' => 0.0, 'tiers' => []]);
                                    [$vMontantEx, $vSeancesEx, $vOperationsEx, $vSeanceOperationsEx]
                                        = $resoudreExerciceAll($exEntryAll, $scIdAll, $matrixExAll);
                                @endphp
                                @if ($vMontantEx > 0)
                                    {{-- Ligne exercice --}}
                                    <tr style="background:#fff;">
                                        <td></td>
                                        <td style="padding:4px 12px 4px 52px;color:#777;font-size:12px;font-style:italic;">
                                            {{ $labelParAnneeAll[$anneeAll] ?? $exEntryAll['label'] ?? '' }}
                                        </td>
                                        @include('livewire.partials.rapport-operations-cellules', [
                                            'vMontant' => $vMontantEx,
                                            'vSeances' => $vSeancesEx,
                                            'vOperations' => $vOperationsEx,
                                            'vSeanceOperations' => $vSeanceOperationsEx,
                                            'cellStyle' => 'padding:4px 4px;font-size:11px;color:#777;',
                                            'totalStyle' => 'padding:4px 8px;color:#777;',
                                        ])
                                    </tr>

                                    @if ($parTiers)
                                        @php
                                            $tiersIdsRealiseAll = collect($exEntryAll['tiers'] ?? [])->pluck('tiers_id')->map(fn ($id) => (int) $id)->all();
                                            $tiersIdsProjectionAll = ($isProjectionAll && $matrixExAll !== null)
                                                ? array_map('intval', array_keys($matrixExAll->byScTiers($scIdAll)))
                                                : [];
                                            $tousTiersIdsAll = collect(array_merge($tiersIdsRealiseAll, $tiersIdsProjectionAll))->unique()->all();
                                            $tiersRealiseParIdAll = collect($exEntryAll['tiers'] ?? [])->keyBy('tiers_id');
                                        @endphp

                                        @foreach ($tousTiersIdsAll as $tiersIdAll)
                                            @php
                                                $tEntryAll = $tiersRealiseParIdAll->get($tiersIdAll, ['montant' => 0.0, 'tiers_id' => $tiersIdAll]);
                                                [$vMontantT, $vSeancesT, $vOperationsT, $vSeanceOperationsT]
                                                    = $resoudreTiersAll($tEntryAll, $scIdAll, $tiersIdAll, $matrixExAll);
                                                $labelTAll = $tEntryAll['label']
                                                    ?? ($tiersCompteLabelAll[$tiersIdAll]['label'] ?? '(sans tiers)');
                                                $typeTAll = $tEntryAll['type']
                                                    ?? ($tiersCompteLabelAll[$tiersIdAll]['type'] ?? null);
                                            @endphp
                                            @if ($vMontantT > 0)
                                                {{-- Ligne tiers --}}
                                                <tr style="background:#fff;">
                                                    <td></td>
                                                    <td style="padding:3px 12px 3px 72px;color:#666;font-size:12px;">
                                                        @if ($typeTAll === 'entreprise')
                                                            <i class="bi bi-building text-muted" style="font-size:.65rem"></i>
                                                        @elseif ($typeTAll === 'particulier')
                                                            <i class="bi bi-person text-muted" style="font-size:.65rem"></i>
                                                        @endif
                                                        @if ($tiersIdAll === 0)
                                                            <em>{{ $labelTAll }}</em>
                                                        @else
                                                            {{ $labelTAll }}
                                                        @endif
                                                    </td>
                                                    @include('livewire.partials.rapport-operations-cellules', [
                                                        'vMontant' => $vMontantT,
                                                        'vSeances' => $vSeancesT,
                                                        'vOperations' => $vOperationsT,
                                                        'vSeanceOperations' => $vSeanceOperationsT,
                                                        'cellStyle' => 'padding:3px 4px;color:#888;font-size:10px;',
                                                        'totalStyle' => 'padding:3px 8px;color:#666;font-size:11px;',
                                                    ])
                                                </tr>
                                            @endif
                                        @endforeach
                                    @endif
                                @endif
                            @endforeach
                        @endforeach
                    @endforeach

                    {{-- Ligne total section — déjà calculé par le composant, jamais recalculé ici --}}
                    <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                        <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                        <td class="text-end" colspan="{{ $totalColspan - 2 }}" style="padding:9px 8px;">
                            {{ number_format($section['total'], 2, ',', ' ') }} &euro;
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endforeach

{{-- Barre résultat net — un seul chemin de rendu, contrairement à la portée
     courante qui peut l'injecter en colonne « opérations » : ici, toujours en
     bandeau plein, même style que le rendu existant. --}}
<div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
     style="background:{{ $resultatNet >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-size:1.1rem;font-weight:700;">
    <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
    <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} &euro;</span>
</div>
