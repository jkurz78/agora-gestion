{{-- resources/views/livewire/communication-tiers.blade.php --}}
<div>
    @if (session('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-3">
        {{-- ── Panneau de filtres ── --}}
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2" style="background:#3d5473;color:#fff;font-size:.85rem;font-weight:600;">
                    <i class="bi bi-funnel me-1"></i> Filtres
                </div>
                <div class="card-body p-3">

                    {{-- Recherche texte --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Recherche</label>
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               class="form-control form-control-sm"
                               placeholder="Nom, email…">
                    </div>

                    {{-- Mode ET / OU --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Combinaison des filtres</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <input type="radio" class="btn-check" wire:model.live="modeFiltres" value="et" id="mode-et">
                            <label class="btn btn-outline-secondary" for="mode-et">ET</label>
                            <input type="radio" class="btn-check" wire:model.live="modeFiltres" value="ou" id="mode-ou">
                            <label class="btn btn-outline-secondary" for="mode-ou">OU</label>
                        </div>
                    </div>

                    <hr class="my-2">

                    {{-- Fournisseurs --}}
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox"
                               wire:model.live="filtreFournisseurs" id="filtreFourn">
                        <label class="form-check-label small" for="filtreFourn">Fournisseurs</label>
                    </div>

                    {{-- Clients --}}
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox"
                               wire:model.live="filtreClients" id="filtreClients">
                        <label class="form-check-label small" for="filtreClients">Clients / payeurs</label>
                    </div>

                    <hr class="my-2">

                    {{-- Donateurs --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Donateurs</label>
                        <select wire:model.live="filtreDonateurs" class="form-select form-select-sm">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>
                    </div>

                    {{-- Adhérents --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Adhérents</label>
                        <select wire:model.live="filtreAdherents" class="form-select form-select-sm">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>
                    </div>

                    <hr class="my-2">

                    {{-- Participants --}}
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Participants</label>
                        <select wire:model.live="filtreParticipantsScope" class="form-select form-select-sm mb-2">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>

                        @if ($filtreParticipantsScope !== null)
                            <label class="form-label small text-muted mb-1">Types d'opération</label>
                            @foreach ($typesOperation as $typeOp)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           wire:model.live="filtreTypeOperationIds"
                                           value="{{ $typeOp->id }}"
                                           id="typeop-{{ $typeOp->id }}">
                                    <label class="form-check-label small" for="typeop-{{ $typeOp->id }}">
                                        {{ $typeOp->nom }}
                                    </label>
                                </div>
                            @endforeach
                        @endif
                    </div>

                </div>
            </div>
        </div>

        {{-- ── Liste des tiers ── --}}
        <div class="col-md-9">

            {{-- Barre de comptage + actions --}}
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                <span class="small text-muted">
                    <strong>{{ $tiersList->count() }}</strong> tiers filtrés —
                    <strong>{{ $emailCount }}</strong> avec email valide
                </span>

                <div class="ms-auto d-flex gap-2 align-items-center">
                    @if (count($selectedTiersIds) > 0)
                        <span class="badge bg-primary">{{ count($selectedTiersIds) }} sélectionné(s)</span>
                    @endif

                    <button class="btn btn-sm btn-outline-secondary"
                            wire:click="toggleSelectAll">
                        @if ($selectAll)
                            <i class="bi bi-square me-1"></i> Désélectionner tout
                        @else
                            <i class="bi bi-check-all me-1"></i> Tout sélectionner
                        @endif
                    </button>

                    @if (count($selectedTiersIds) > 0)
                        <button class="btn btn-sm btn-primary" disabled
                                title="Disponible dans la prochaine version">
                            <i class="bi bi-envelope me-1"></i> Envoyer un email
                        </button>
                    @endif
                </div>
            </div>

            {{-- Tableau --}}
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" style="font-size:.82rem;">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="form-check-input"
                                       wire:model.live="selectAll"
                                       wire:click="toggleSelectAll">
                            </th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Email</th>
                            <th>Ville</th>
                            <th class="text-center">Dép.</th>
                            <th class="text-center">Rec.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tiersList as $tiers)
                            @php
                                $hasEmail = ! empty($tiers->getRawOriginal('email'));
                                $optout   = $tiers->email_optout;
                                $canSelect = $hasEmail && ! $optout;
                                $rowClass  = $optout ? 'text-muted' : '';
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td>
                                    @if ($canSelect)
                                        <input type="checkbox" class="form-check-input"
                                               wire:model.live="selectedTiersIds"
                                               value="{{ $tiers->id }}">
                                    @else
                                        <input type="checkbox" class="form-check-input" disabled>
                                    @endif
                                </td>
                                <td>
                                    @if ($tiers->type === 'entreprise')
                                        <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                                        {{ $tiers->entreprise }}
                                    @else
                                        {{ $tiers->nom }}
                                    @endif
                                </td>
                                <td>{{ $tiers->prenom ?? '—' }}</td>
                                <td>
                                    @if ($optout)
                                        <span class="badge bg-secondary">Désinscrit</span>
                                        @if ($hasEmail)
                                            <span class="text-muted ms-1" style="font-size:.78rem;">
                                                {{ $tiers->getRawOriginal('email') }}
                                            </span>
                                        @endif
                                    @elseif (! $hasEmail)
                                        <span class="badge bg-warning text-dark">Pas d'email</span>
                                    @else
                                        {{ $tiers->getRawOriginal('email') }}
                                    @endif
                                </td>
                                <td>{{ $tiers->ville ?? '—' }}</td>
                                <td class="text-center">
                                    @if ($tiers->pour_depenses)
                                        <i class="bi bi-check-lg text-success"></i>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($tiers->pour_recettes)
                                        <i class="bi bi-check-lg text-success"></i>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('tiers.transactions', $tiers->id) }}"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Voir les transactions">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    Aucun tiers ne correspond aux filtres.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
