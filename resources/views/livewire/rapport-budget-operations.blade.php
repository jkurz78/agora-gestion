<div>
    <style>
        /* Fond de ligne : toujours sur le td, jamais sur le tr — Bootstrap peint
           background-color ET color sur « .table > :not(caption) > * > * », ce
           qui recouvre tout ce qu'un <tr> déclare. Une ligne s'est déjà
           affichée blanche pendant des mois sur ce projet pour avoir posé la
           couleur au mauvais niveau (voir rapport-compte-resultat.blade.php). */
        .bop-cat td { background: #dce6f0; color: #1e3a5f; font-weight: 600; border-bottom: 1px solid #b8ccdf; padding: 7px 12px; }
        .bop-sub td { background: #f7f9fc; color: #444; border-bottom: 1px solid #e2e8f0; padding: 5px 12px; }
        .bop-total td { background: #5a7fa8; color: #fff; font-weight: 700; padding: 9px 12px; border-bottom: none; }
        .cr-neg { color: #B5453A; }
        .cr-pos { color: #2E7D32; }
        .cr-zero { color: #6c757d; }
        /* Sur fond sombre (ligne de total), les couleurs d'écart pensées pour
           un fond blanc tombent sous le seuil de contraste — variantes claires,
           même teinte, même lecture « vert = favorable, rouge = défavorable ». */
        .bop-total .cr-pos { color: #C8F5C0; }
        .bop-total .cr-neg { color: #FFD2CB; }
        .bop-total .cr-zero { color: rgba(255,255,255,.75); }
    </style>

    {{-- Sélecteur d'opérations — même widget, même comportement que le compte
         de résultat par opérations (resources/views/livewire/rapport-compte-resultat-operations.blade.php),
         l'arbre étant fourni par le même service partagé. --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
            <div x-data="{
                selectedIds: @entangle('selectedOperationIds').live,
                open: false,
                tree: @js($operationTree),

                init() {
                    if (this.selectedIds.length === 0) {
                        this.$nextTick(() => { this.open = true; });
                    }
                },

                toggleOp(id) {
                    const idx = this.selectedIds.indexOf(id);
                    if (idx > -1) {
                        this.selectedIds = this.selectedIds.filter(i => i !== id);
                    } else {
                        this.selectedIds = [...this.selectedIds, id];
                    }
                },

                toggleGroup(opIds) {
                    const allIn = opIds.every(id => this.selectedIds.includes(id));
                    if (allIn) {
                        this.selectedIds = this.selectedIds.filter(id => !opIds.includes(id));
                    } else {
                        const newIds = [...this.selectedIds];
                        opIds.forEach(id => { if (!newIds.includes(id)) newIds.push(id); });
                        this.selectedIds = newIds;
                    }
                },

                groupState(opIds) {
                    const count = opIds.filter(id => this.selectedIds.includes(id)).length;
                    if (count === 0) return 'none';
                    if (count === opIds.length) return 'all';
                    return 'partial';
                },

                get label() {
                    const n = this.selectedIds.length;
                    if (n === 0) return 'Sélectionnez des opérations...';
                    if (n === 1) {
                        for (const sc of this.tree) {
                            for (const t of sc.types) {
                                for (const o of t.operations) {
                                    if (o.id === this.selectedIds[0]) return o.nom;
                                }
                            }
                        }
                    }
                    return n + ' opérations sélectionnées';
                },
            }" class="dropdown" @click.outside="open = false">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                        @click="open = !open" x-text="label"></button>
                <div class="dropdown-menu p-3" :class="{'show': open}" style="min-width:350px;max-height:400px;overflow-y:auto;">
                    <template x-for="sc in tree" :key="sc.id">
                        <div class="mb-2">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input"
                                       :checked="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'all'"
                                       :indeterminate="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'partial'"
                                       @change="toggleGroup(sc.types.flatMap(t => t.operations.map(o => o.id)))">
                                <label class="form-check-label small fw-bold text-muted" x-text="sc.nom"></label>
                            </div>
                            <template x-for="type in sc.types" :key="type.id">
                                <div class="ms-2 mb-1">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input"
                                               :checked="groupState(type.operations.map(o => o.id)) === 'all'"
                                               :indeterminate="groupState(type.operations.map(o => o.id)) === 'partial'"
                                               @change="toggleGroup(type.operations.map(o => o.id))">
                                        <label class="form-check-label small fw-semibold" x-text="type.nom"></label>
                                    </div>
                                    <template x-for="op in type.operations" :key="op.id">
                                        <div class="form-check ms-3">
                                            <input type="checkbox" class="form-check-input"
                                                   :checked="selectedIds.includes(op.id)"
                                                   @change="toggleOp(op.id)">
                                            <label class="form-check-label small" x-text="op.nom"></label>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Export dropdown — masqué sans sélection : budgetOperationsExport()
                 abort en 422 quand `ops` est vide (voir RapportExportController),
                 un bouton qui mènerait à une page d'erreur est pire qu'aucun bouton. --}}
            @if ($hasSelection)
                <div class="ms-auto">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download me-1"></i>Exporter
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
                        </ul>
                    </div>
                </div>
            @endif
            </div>
        </div>
    </div>

    @if ($selectionIgnoree)
        <div class="alert alert-info py-2 small">
            Les opérations demandées n'ont ni budget ni mouvement sur l'exercice affiché.
        </div>
    @endif

    @if ($aucuneOperationEligible)
        <p class="text-muted text-center py-4">
            Aucune opération n'a de budget ni de mouvement sur l'exercice affiché.
        </p>
    @elseif (! $hasSelection)
        <p class="text-muted text-center py-4">Sélectionnez au moins une opération pour afficher son budget.</p>
    @else
        @php
            // Un null n'est pas un zero : il dit que la grandeur ne parle pas pour ce
            // compte. Le formater en 0,00 € affirmerait « aucune subvention attendue »
            // en face d'une subvention budgetee.
            $fmt = fn (?float $v): string => $v === null
                ? '<span class="budget-op-vide text-muted">&mdash;</span>'
                : number_format($v, 2, ',', ' ') . ' &euro;';

            // Aucun ecart ecrit a la main : le motif s'est produit 3 fois sur ce projet,
            // pour 8 sites corriges. La couleur porte le jugement, le nombre est brut.
            $renderEcart = function (?float $budget, float $realise, bool $isCharge): string {
                if ($budget === null) {
                    return '<span class="budget-op-vide text-muted">&mdash;</span>';
                }
                $ecart = \App\Support\ComparaisonBudgetaire::ecart($budget, $realise);
                if ($ecart == 0) {
                    return '<span class="cr-zero">0,00 &euro;</span>';
                }
                $cls = \App\Support\ComparaisonBudgetaire::ecartEstFavorable($ecart, $isCharge) ? 'cr-pos' : 'cr-neg';
                $sign = $ecart > 0 ? '+' : '';

                return '<span class="' . $cls . '">' . $sign . number_format($ecart, 2, ',', ' ') . ' &euro;</span>';
            };

            $multiOperations = count($operations) > 1;
        @endphp

        @foreach ($operations as $op)
            @if ($multiOperations)
                <h5 class="mt-4 mb-2">{{ $op['operation_nom'] }}</h5>
            @endif

            @foreach ([
                ['data' => $op['charges'], 'totaux' => $op['totaux']['charges'], 'label' => 'Dépenses', 'isCharge' => true],
                ['data' => $op['produits'], 'totaux' => $op['totaux']['produits'], 'label' => 'Recettes', 'isCharge' => false],
            ] as $section)
                <h6 class="fw-bold mb-2" style="color:#3d5473;">{{ mb_strtoupper($section['label']) }}</h6>
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0" style="font-size:13px;">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Compte</th>
                                    <th class="text-end">Budget affecté</th>
                                    <th class="text-end">Prévisionnel</th>
                                    <th class="text-end">Réalisé</th>
                                    <th class="text-end">Écart</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($section['data'] as $famille)
                                    <tr class="bop-cat">
                                        <td>{{ $famille['famille_nom'] }}</td>
                                        <td class="text-end" data-sort="{{ $famille['budget'] ?? '' }}">{!! $fmt($famille['budget']) !!}</td>
                                        <td class="text-end" data-sort="{{ $famille['prevision'] ?? '' }}">{!! $fmt($famille['prevision']) !!}</td>
                                        <td class="text-end" data-sort="{{ $famille['realise'] }}">{{ number_format($famille['realise'], 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end">{!! $renderEcart($famille['budget'], $famille['realise'], $section['isCharge']) !!}</td>
                                    </tr>
                                    @foreach ($famille['comptes'] as $compte)
                                        <tr class="bop-sub">
                                            <td style="padding-left:32px;">
                                                {{ $compte['compte_nom'] }}
                                                @if ($compte['hors_dotation'])
                                                    <span class="badge text-bg-secondary ms-1">hors dotation</span>
                                                @endif
                                            </td>
                                            <td class="text-end" data-sort="{{ $compte['budget'] ?? '' }}">{!! $fmt($compte['budget']) !!}</td>
                                            <td class="text-end" data-sort="{{ $compte['prevision'] ?? '' }}">{!! $fmt($compte['prevision']) !!}</td>
                                            <td class="text-end" data-sort="{{ $compte['realise'] }}">{{ number_format($compte['realise'], 2, ',', ' ') }} &euro;</td>
                                            <td class="text-end">{!! $renderEcart($compte['budget'], $compte['realise'], $section['isCharge']) !!}</td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">Aucun compte.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if (! empty($section['data']))
                                <tfoot>
                                    <tr class="bop-total">
                                        <td>TOTAL {{ mb_strtoupper($section['label']) }}</td>
                                        <td class="text-end">{!! $fmt($section['totaux']['budget']) !!}</td>
                                        <td class="text-end">{!! $fmt($section['totaux']['prevision']) !!}</td>
                                        <td class="text-end">{{ number_format($section['totaux']['realise'], 2, ',', ' ') }} &euro;</td>
                                        <td class="text-end">{!! $renderEcart($section['totaux']['budget'], $section['totaux']['realise'], $section['isCharge']) !!}</td>
                                    </tr>
                                    @if ($section['totaux']['hors_dotation'] != 0.0)
                                        <tr>
                                            <td colspan="5" class="text-muted small" style="padding:4px 12px;">
                                                dont hors dotation : {{ number_format($section['totaux']['hors_dotation'], 2, ',', ' ') }} &euro;
                                            </td>
                                        </tr>
                                    @endif
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            @endforeach
        @endforeach

        <p class="text-muted small mt-2">
            Le prévisionnel ne couvre que les règlements des participants et les coûts
            d'encadrement. Un tiret signale un compte qu'il n'atteint pas — ce n'est pas un zéro.
        </p>
    @endif
</div>
