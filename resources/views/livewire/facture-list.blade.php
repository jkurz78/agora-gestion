<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Modale sélection tiers pour créer une facture --}}
    <div class="modal fade {{ $showCreerModal ? 'show d-block' : '' }}"
         tabindex="-1"
         role="dialog"
         id="creerFactureModal"
         aria-labelledby="creerFactureModalLabel"
         @if($showCreerModal) aria-modal="true" style="background:rgba(0,0,0,.4);" @else aria-hidden="true" @endif>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="creerFactureModalLabel">
                        <i class="bi bi-receipt me-1"></i> Nouvelle facture
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('showCreerModal', false)"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">
                        Tiers à facturer <span class="text-danger">*</span>
                    </label>
                    <livewire:tiers-autocomplete
                        wire:model.live="newFactureTiersId"
                        filtre="recettes"
                        :key="'facture-create-tiers-'.($showCreerModal ? '1' : '0')" />
                    @error('newFactureTiersId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            wire:click="$set('showCreerModal', false)">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            wire:click="creer({{ $newFactureTiersId ?? 'null' }})"
                            @if(!$newFactureTiersId) disabled @endif>
                        <i class="bi bi-check-lg"></i> Créer
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modale sélection tiers pour créer une facture libre --}}
    <div class="modal fade {{ $showCreerLibreModal ? 'show d-block' : '' }}"
         tabindex="-1"
         role="dialog"
         id="creerFactureLibreModal"
         aria-labelledby="creerFactureLibreModalLabel"
         @if($showCreerLibreModal) aria-modal="true" style="background:rgba(0,0,0,.4);" @else aria-hidden="true" @endif>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="creerFactureLibreModalLabel">
                        <i class="bi bi-receipt me-1"></i> Nouvelle facture libre
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('showCreerLibreModal', false)"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">
                        Tiers à facturer <span class="text-danger">*</span>
                    </label>
                    <livewire:tiers-autocomplete
                        wire:model.live="newFactureLibreTiersId"
                        filtre="recettes"
                        :key="'facture-libre-create-tiers-'.($showCreerLibreModal ? '1' : '0')" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            wire:click="$set('showCreerLibreModal', false)">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-success btn-sm"
                            wire:click="creerFactureLibre({{ $newFactureLibreTiersId ?? 'null' }})"
                            @if(!$newFactureLibreTiersId) disabled @endif>
                        <i class="bi bi-check-lg"></i> Créer
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Filtres + création --}}
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <select wire:model.live="filterStatut" class="form-select form-select-sm" style="max-width:180px;">
            <option value="">Tous les statuts</option>
            <option value="brouillon">Brouillon</option>
            <option value="validee">Validée (toutes)</option>
            <option value="non_reglee">Non réglée</option>
            <option value="acquittee">Acquittée</option>
            <option value="annulee">Annulée</option>
        </select>
        <input type="text" wire:model.live.debounce.300ms="filterTiers" class="form-control form-control-sm" style="max-width:220px;" placeholder="Rechercher un tiers…">

        @if($this->canEdit)
            <div class="ms-auto d-flex gap-2">
                <button wire:click="creer" class="btn btn-primary btn-sm text-nowrap">
                    <i class="bi bi-plus-lg"></i> Nouvelle facture
                </button>
                <button wire:click="ouvrirModalLibre" class="btn btn-outline-success btn-sm text-nowrap">
                    <i class="bi bi-plus-lg"></i> Nouvelle facture libre
                </button>
            </div>
        @endif
    </div>

    {{-- Liste des factures --}}
    @if ($factures->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucune facture pour cet exercice.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Date</th>
                        <th>Tiers</th>
                        <th class="text-end">Montant total</th>
                        <th class="text-end">Montant réglé</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($factures as $facture)
                        @php
                            $acquittee = $facture->isAcquittee();
                            $isBrouillon = $facture->statut === \App\Enums\StatutFacture::Brouillon;
                            $isAnnulee = $facture->statut === \App\Enums\StatutFacture::Annulee;
                            $montantRegle = $facture->montantRegle();
                        @endphp
                        <tr wire:key="facture-{{ $facture->id }}"
                            style="cursor:pointer"
                            onclick="window.location='{{ $isBrouillon ? route('facturation.factures.edit', $facture) : route('facturation.factures.show', $facture) }}'"
                        >
                            <td class="small">
                                @if ($facture->numero)
                                    {{ $facture->numero }}
                                @else
                                    <span class="text-muted fst-italic">Brouillon</span>
                                @endif
                            </td>
                            <td class="small text-nowrap" data-sort="{{ $facture->date->format('Y-m-d') }}">
                                {{ $facture->date->format('d/m/Y') }}
                            </td>
                            <td class="small">
                                {{ $facture->tiers?->displayName() }}
                                @if($facture->tiers_id)
                                    <x-tiers-info-icon :tiersId="$facture->tiers_id" />
                                @endif
                            </td>
                            @php $montantCalcule = $facture->montantCalcule(); @endphp
                            <td class="text-end small text-nowrap fw-semibold" data-sort="{{ $montantCalcule }}">
                                {{ number_format($montantCalcule, 2, ',', "\u{202F}") }}&nbsp;&euro;
                            </td>
                            <td class="text-end small text-nowrap" data-sort="{{ $montantRegle }}">
                                {{ number_format($montantRegle, 2, ',', "\u{202F}") }}&nbsp;&euro;
                            </td>
                            <td>
                                @if ($acquittee)
                                    <span class="badge bg-success" style="font-size:.7rem">
                                        <i class="bi bi-check-circle"></i> Acquittée
                                    </span>
                                @elseif ($facture->statut === \App\Enums\StatutFacture::Brouillon)
                                    <span class="badge bg-secondary" style="font-size:.7rem">
                                        <i class="bi bi-pencil"></i> Brouillon
                                    </span>
                                @elseif ($facture->statut === \App\Enums\StatutFacture::Validee && $montantRegle > 0)
                                    <span class="badge bg-warning text-dark" style="font-size:.7rem">
                                        <i class="bi bi-hourglass-split"></i> Partiellement réglée
                                    </span>
                                @elseif ($facture->statut === \App\Enums\StatutFacture::Validee)
                                    <span class="badge bg-secondary" style="font-size:.7rem">
                                        <i class="bi bi-clock"></i> Non réglée
                                    </span>
                                @elseif ($facture->statut === \App\Enums\StatutFacture::Annulee)
                                    <span class="badge bg-danger" style="font-size:.7rem">
                                        <i class="bi bi-x-circle"></i> Annulée
                                    </span>
                                @endif
                            </td>
                            <td onclick="event.stopPropagation()">
                                @if ($isBrouillon && $this->canEdit)
                                    <button wire:click="supprimer({{ $facture->id }})"
                                            wire:confirm="Supprimer ce brouillon de facture ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $factures->links() }}
        </div>
    @endif
</div>
