<div>
    {{-- [1] Session error message --}}
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- [2] Bouton Nouveau (dropdown ou direct) --}}
    <div class="mb-3 d-flex justify-content-between align-items-center">
        @if(count($availableTypes) === 1)
            @php $type = $availableTypes[0]; @endphp
            <button wire:click="$dispatch('{{ match($type) {
                'depense' => 'open-transaction-form', 'recette' => 'open-transaction-form',
                'don' => 'open-don-form', 'cotisation' => 'open-cotisation-form',
                'virement' => 'open-virement-form', default => 'open-transaction-form'
            } }}', { id: null {{ $type === 'depense' ? ", type: 'depense'" : ($type === 'recette' ? ", type: 'recette'" : '') }} })"
                    class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i>
                {{ match($type) {
                    'depense' => 'Nouvelle dépense', 'recette' => 'Nouvelle recette',
                    'don' => 'Nouveau don', 'cotisation' => 'Nouvelle cotisation',
                    'virement' => 'Nouveau virement', default => 'Nouveau'
                } }}
            </button>
        @else
            <div class="dropdown">
                <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-plus-lg"></i> Nouvelle transaction
                </button>
                <ul class="dropdown-menu">
                    @if(in_array('depense', $availableTypes))
                        <li><a class="dropdown-item" href="#"
                            wire:click.prevent="$dispatch('open-transaction-form', { id: null, type: 'depense' })">
                            <i class="bi bi-arrow-down-circle text-danger me-1"></i> Dépense</a></li>
                    @endif
                    @if(in_array('recette', $availableTypes))
                        <li><a class="dropdown-item" href="#"
                            wire:click.prevent="$dispatch('open-transaction-form', { id: null, type: 'recette' })">
                            <i class="bi bi-arrow-up-circle text-success me-1"></i> Recette</a></li>
                    @endif
                    @if(in_array('don', $availableTypes))
                        <li><a class="dropdown-item" href="#"
                            wire:click.prevent="$dispatch('open-don-form', { id: null })">
                            <i class="bi bi-heart text-primary me-1"></i> Don</a></li>
                    @endif
                    @if(in_array('cotisation', $availableTypes))
                        <li><a class="dropdown-item" href="#"
                            wire:click.prevent="$dispatch('open-cotisation-form', { id: null })">
                            <i class="bi bi-person-check me-1"></i> Cotisation</a></li>
                    @endif
                    @if(in_array('virement', $availableTypes))
                        <li><a class="dropdown-item" href="#"
                            wire:click.prevent="$dispatch('open-virement-form', { id: null })">
                            <i class="bi bi-arrow-left-right text-warning me-1"></i> Virement</a></li>
                    @endif
                </ul>
            </div>
        @endif
    </div>

    {{-- [3] Toggles type (only if count($availableTypes) > 1) --}}
    @if(count($availableTypes) > 1)
    <div class="mb-3 d-flex gap-1 flex-wrap">
        <button wire:click="$set('filterTypes', [])"
                class="btn btn-sm {{ empty($filterTypes) ? 'btn-secondary' : 'btn-outline-secondary' }}">
            Toutes
        </button>
        @foreach($availableTypes as $type)
            @php
                [$btnClass, $label] = match($type) {
                    'depense'   => ['danger',    'DÉP'],
                    'recette'   => ['success',   'REC'],
                    'don'       => ['primary',   'DON'],
                    'cotisation'=> ['secondary', 'COT'],
                    'virement'  => ['warning',   'VIR'],
                    default     => ['secondary', strtoupper($type)],
                };
                $active = in_array($type, $filterTypes);
            @endphp
            <button wire:click="toggleType('{{ $type }}')"
                    class="btn btn-sm {{ $active ? "btn-{$btnClass}" : "btn-outline-{$btnClass}" }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
    @endif

    {{-- [4] Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    {{-- N°pièce header QBE --}}
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            N°pièce
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterNumeroPiece !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterNumeroPiece }}
                                        <a href="#" wire:click.prevent="$set('filterNumeroPiece', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2"
                                     style="z-index:200;min-width:180px;top:1.2rem;left:0">
                                    <input wire:model.live.debounce.300ms="filterNumeroPiece"
                                           class="form-control form-control-sm"
                                           placeholder="Filtrer…"
                                           @keydown.escape="open = false">
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Date header with presets --}}
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            <a href="#" wire:click.prevent="sortBy('date')" class="text-white text-decoration-none">
                                Date @if($sortColumn === 'date')<i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>@endif
                            </a>
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterDateDebut || $filterDateFin)
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterDateDebut ? \Carbon\Carbon::parse($filterDateDebut)->format('d/m') : '…' }}
                                        –
                                        {{ $filterDateFin ? \Carbon\Carbon::parse($filterDateFin)->format('d/m') : '…' }}
                                        <a href="#" wire:click.prevent="applyDatePreset('exercice')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                                     style="z-index:200;min-width:220px;top:1.2rem;left:0">
                                    <div class="d-flex flex-column gap-1 mb-2">
                                        <button wire:click="applyDatePreset('exercice')" @click="open=false"
                                                class="btn btn-outline-secondary btn-sm text-start">Exercice en cours</button>
                                        <button wire:click="applyDatePreset('mois')" @click="open=false"
                                                class="btn btn-outline-secondary btn-sm text-start">Mois en cours</button>
                                        <button wire:click="applyDatePreset('trimestre')" @click="open=false"
                                                class="btn btn-outline-secondary btn-sm text-start">Trimestre en cours</button>
                                        <button wire:click="applyDatePreset('all')" @click="open=false"
                                                class="btn btn-outline-secondary btn-sm text-start">Toutes les dates</button>
                                    </div>
                                    <hr class="my-1">
                                    <div class="d-flex flex-column gap-1">
                                        <label class="form-label small mb-0">Début</label>
                                        <input wire:model.live="filterDateDebut" type="date" class="form-control form-control-sm">
                                        <label class="form-label small mb-0">Fin</label>
                                        <input wire:model.live="filterDateFin" type="date" class="form-control form-control-sm">
                                    </div>
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Type header (only if multi) --}}
                    @if(count($availableTypes) > 1)
                    <th>Type</th>
                    @endif

                    {{-- Référence header QBE --}}
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Référence
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterReference !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterReference }}
                                        <a href="#" wire:click.prevent="$set('filterReference', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2"
                                     style="z-index:200;min-width:180px;top:1.2rem;left:0">
                                    <input wire:model.live.debounce.300ms="filterReference"
                                           class="form-control form-control-sm"
                                           placeholder="Filtrer…"
                                           @keydown.escape="open = false">
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Tiers header QBE (only if $showTiersCol) --}}
                    @if($showTiersCol)
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Tiers
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterTiers !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterTiers }}
                                        <a href="#" wire:click.prevent="$set('filterTiers', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2"
                                     style="z-index:200;min-width:180px;top:1.2rem;left:0">
                                    <input wire:model.live.debounce.300ms="filterTiers"
                                           class="form-control form-control-sm"
                                           placeholder="Filtrer…"
                                           @keydown.escape="open = false">
                                </div>
                            </span>
                        </div>
                    </th>
                    @endif

                    {{-- Compte header (select, only if $showCompteCol) --}}
                    @if($showCompteCol)
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Compte
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterCompteId)
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        <a href="#" wire:click.prevent="$set('filterCompteId', null)" class="text-white">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                                     style="z-index:200;min-width:180px;top:1.2rem;left:0">
                                    <select wire:model.live="filterCompteId" class="form-select form-select-sm">
                                        <option value="">Tous</option>
                                        @foreach($comptes as $c)
                                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </span>
                        </div>
                    </th>
                    @endif

                    {{-- Libellé header QBE --}}
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Libellé
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterLibelle !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterLibelle }}
                                        <a href="#" wire:click.prevent="$set('filterLibelle', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2"
                                     style="z-index:200;min-width:180px;top:1.2rem;left:0">
                                    <input wire:model.live.debounce.300ms="filterLibelle"
                                           class="form-control form-control-sm"
                                           placeholder="Filtrer…"
                                           @keydown.escape="open = false">
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Catégorie --}}
                    <th>Catégorie</th>

                    {{-- Mode paiement --}}
                    <th style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Mode
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterModePaiement !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterModePaiement }}
                                        <a href="#" wire:click.prevent="$set('filterModePaiement', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                                     style="z-index:200;min-width:160px;top:1.2rem;left:0">
                                    <select wire:model.live="filterModePaiement" class="form-select form-select-sm">
                                        <option value="">Tous</option>
                                        @foreach($modesPaiement as $mode)
                                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Montant --}}
                    <th class="text-end">
                        <a href="#" wire:click.prevent="sortBy('montant')" class="text-white text-decoration-none">
                            Montant @if($sortColumn === 'montant')<i class="bi bi-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>@endif
                        </a>
                    </th>

                    {{-- Pointé --}}
                    <th class="text-center" style="position:relative">
                        <div class="d-flex align-items-center gap-1">
                            Pointé
                            <span x-data="{ open: false }" style="position:relative">
                                <i class="bi bi-search" style="cursor:pointer;font-size:.65rem;opacity:.6" @click="open = !open"></i>
                                @if($filterPointe !== '')
                                    <span class="badge rounded-pill text-bg-primary ms-1" style="font-size:.6rem">
                                        {{ $filterPointe === '1' ? 'Oui' : 'Non' }}
                                        <a href="#" wire:click.prevent="$set('filterPointe', '')" class="text-white ms-1">×</a>
                                    </span>
                                @endif
                                <div x-show="open" @click.outside="open = false"
                                     class="position-absolute bg-white border rounded shadow-sm p-2 text-dark"
                                     style="z-index:200;min-width:120px;top:1.2rem;left:0">
                                    <select wire:model.live="filterPointe" class="form-select form-select-sm">
                                        <option value="">Tous</option>
                                        <option value="1">Oui</option>
                                        <option value="0">Non</option>
                                    </select>
                                </div>
                            </span>
                        </div>
                    </th>

                    {{-- Solde (only if $showSolde) --}}
                    @if($showSolde)
                    <th class="text-end">Solde</th>
                    @endif

                    {{-- Actions --}}
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            @forelse($rows as $tx)
                @php
                    $key = $tx->source_type . ':' . $tx->id;
                    $isExpanded = isset($expandedDetails[$key]);
                    $detail = $expandedDetails[$key] ?? null;
                    [$badgeClass, $badgeLabel] = match($tx->source_type) {
                        'depense'              => ['danger',    'DÉP'],
                        'recette'              => ['success',   'REC'],
                        'don'                  => ['primary',   'DON'],
                        'cotisation'           => ['secondary', 'COT'],
                        'virement_sortant',
                        'virement_entrant'     => ['warning',   'VIR'],
                        default                => ['secondary', '?'],
                    };
                    $isLocked = (bool) $tx->pointe;
                @endphp
                <tr style="cursor:pointer" wire:click="toggleDetail('{{ $tx->source_type }}', {{ $tx->id }})">
                    <td class="small text-muted text-nowrap">{{ $tx->numero_piece ?? '—' }}</td>
                    <td class="small text-nowrap">{{ \Carbon\Carbon::parse($tx->date)->format('d/m') }}</td>
                    @if(count($availableTypes) > 1)
                        <td>
                            <span class="badge text-bg-{{ $badgeClass }}" style="font-size:.65rem">{{ $badgeLabel }}</span>
                        </td>
                    @endif
                    <td class="small text-muted text-nowrap">{{ $tx->reference ?? '' }}</td>
                    @if($showTiersCol)
                        <td class="small text-nowrap" style="max-width:160px;overflow:hidden;text-overflow:ellipsis">
                            @if($tx->tiers)
                                @if($tx->tiers_type === 'entreprise')
                                    <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                                @elseif($tx->tiers_type)
                                    <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                                @else
                                    <i class="bi bi-bank text-muted me-1" style="font-size:.7rem"></i>
                                @endif
                                {{ $tx->tiers }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    @endif
                    @if($showCompteCol)
                        <td class="small text-muted">{{ $tx->compte_nom ?? '—' }}</td>
                    @endif
                    <td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">{{ $tx->libelle ?? '—' }}</td>
                    <td class="small text-muted">
                        @if((int)$tx->nb_lignes > 1)
                            <i class="bi bi-diagram-2 text-secondary me-1" title="{{ $tx->nb_lignes }} lignes"></i>
                        @endif
                        {{ $tx->categorie_label ?? '' }}
                    </td>
                    <td class="small text-muted">{{ $tx->mode_paiement ?? '—' }}</td>
                    <td class="text-end fw-semibold small text-nowrap {{ (float)$tx->montant >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format(abs((float)$tx->montant), 2, ',', ' ') }} €
                    </td>
                    <td class="text-center">
                        @if($tx->pointe)
                            <i class="bi bi-check-circle-fill text-success" style="font-size:.85rem"></i>
                        @endif
                    </td>
                    @if($showSolde)
                        <td class="text-end small text-muted">
                            {{ isset($tx->solde_courant) ? number_format((float)$tx->solde_courant, 2, ',', ' ').' €' : '' }}
                        </td>
                    @endif
                    <td>
                        <div class="d-flex gap-1" @click.stop>
                            <button wire:click="$dispatch('{{ match($tx->source_type) {
                                'depense', 'recette' => 'open-transaction-form',
                                'don' => 'open-don-form',
                                'cotisation' => 'open-cotisation-form',
                                'virement_sortant', 'virement_entrant' => 'open-virement-form',
                                default => 'open-transaction-form'
                            } }}', { id: {{ $tx->id }} })"
                                    @if($isLocked) style="display:none" @endif
                                    class="btn btn-sm btn-outline-primary"
                                    style="padding:.15rem .3rem;font-size:.7rem"
                                    title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button wire:click="deleteRow('{{ $tx->source_type }}', {{ $tx->id }})"
                                    wire:confirm="Supprimer cette ligne ?"
                                    @if($isLocked) style="display:none" @endif
                                    class="btn btn-sm btn-outline-danger"
                                    style="padding:.15rem .3rem;font-size:.7rem"
                                    title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                {{-- Expansion row --}}
                @if($isExpanded && $detail)
                    <tr class="table-light">
                        <td colspan="{{ 9 + (count($availableTypes) > 1 ? 1 : 0) + ($showTiersCol ? 1 : 0) + ($showCompteCol ? 1 : 0) + ($showSolde ? 1 : 0) }}"
                            class="px-4 py-2 small text-muted">
                            @if(!empty($detail['lignes']))
                                <strong>Ventilation :</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach($detail['lignes'] as $ligne)
                                        <li>{{ $ligne['categorie'] }} › {{ $ligne['sous_categorie'] }} — {{ number_format($ligne['montant'], 2, ',', ' ') }} €</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if(!empty($detail['sous_categorie']))
                                <strong>Sous-catégorie :</strong> {{ $detail['sous_categorie'] }}
                            @endif
                            @if(!empty($detail['operation']))
                                &nbsp;· <strong>Opération :</strong> {{ $detail['operation'] }}
                            @endif
                            @if(!empty($detail['seance']))
                                &nbsp;· <strong>Séance :</strong> {{ $detail['seance'] }}
                            @endif
                            @if(!empty($detail['exercice']))
                                &nbsp;· <strong>Exercice :</strong> {{ $detail['exercice'] }}
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ 9 + (count($availableTypes) > 1 ? 1 : 0) + ($showTiersCol ? 1 : 0) + ($showCompteCol ? 1 : 0) + ($showSolde ? 1 : 0) }}"
                        class="text-center text-muted py-4">
                        Aucune transaction trouvée.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- [5] Pagination + per-page selector --}}
    <div class="mt-3">
        <x-per-page-selector :paginator="$paginator" storageKey="transaction-universelle" wire:model.live="perPage" />
        {{ $paginator->links() }}
    </div>
</div>
