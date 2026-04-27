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

    {{-- Titre + bouton Nouveau devis --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Devis</h4>
        <button wire:click="creerDevis" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nouveau devis
        </button>
    </div>

    {{-- Modale sélection tiers pour créer un devis --}}
    <div class="modal fade {{ $showCreerModal ? 'show d-block' : '' }}"
         tabindex="-1"
         role="dialog"
         id="creerDevisModal"
         aria-labelledby="creerDevisModalLabel"
         @if($showCreerModal) aria-modal="true" style="background:rgba(0,0,0,.4);" @else aria-hidden="true" @endif>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="creerDevisModalLabel">
                        <i class="bi bi-file-earmark-plus me-1"></i> Nouveau devis
                    </h5>
                    <button type="button" class="btn-close" wire:click="$set('showCreerModal', false)"></button>
                </div>
                <div class="modal-body">
                    <label for="modalNouveauTiersId" class="form-label fw-semibold">
                        Tiers <span class="text-danger">*</span>
                    </label>
                    <select id="modalNouveauTiersId"
                            wire:model="nouveauTiersId"
                            class="form-select">
                        <option value="">— Choisir un tiers —</option>
                        @foreach ($tiers as $t)
                            <option value="{{ $t->id }}">{{ $t->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            wire:click="$set('showCreerModal', false)">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            wire:click="creerDevis({{ $nouveauTiersId ?? 'null' }})"
                            @if(!$nouveauTiersId) disabled @endif>
                        <i class="bi bi-check-lg"></i> Créer
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Filtres --}}
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        {{-- Filtre statut --}}
        <select wire:model.live="filtreStatut" class="form-select form-select-sm" style="max-width:200px;">
            <option value="">Tous (sauf annulés)</option>
            <option value="brouillon">Brouillon</option>
            <option value="envoye">Envoyé</option>
            <option value="accepte">Accepté</option>
            <option value="refuse">Refusé</option>
            <option value="annule">Annulé</option>
        </select>

        {{-- Filtre tiers --}}
        <select wire:model.live="filtreTiersId" class="form-select form-select-sm" style="max-width:220px;">
            <option value="">Tous les tiers</option>
            @foreach ($tiers as $t)
                <option value="{{ $t->id }}">{{ $t->displayName() }}</option>
            @endforeach
        </select>

        {{-- Filtre exercice --}}
        <input type="number"
               wire:model.live="filtreExercice"
               class="form-control form-control-sm"
               style="max-width:100px;"
               placeholder="Exercice">

        {{-- Recherche --}}
        <input type="text"
               wire:model.live.debounce.300ms="search"
               class="form-control form-control-sm"
               style="max-width:220px;"
               placeholder="Rechercher…">
    </div>

    {{-- Table --}}
    @if ($devis->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucun devis pour ces critères.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Date émission</th>
                        <th>Tiers</th>
                        <th>Libellé</th>
                        <th class="text-end">Montant total</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($devis as $d)
                        @php $expired = $this->expire($d); @endphp
                        <tr wire:key="devis-{{ $d->id }}">
                            <td class="small" data-sort="{{ $d->numero ?? '' }}">
                                @if ($d->numero)
                                    {{ $d->numero }}
                                @else
                                    <span class="text-muted fst-italic">—</span>
                                @endif
                            </td>
                            <td class="small text-nowrap" data-sort="{{ $d->date_emission->format('Y-m-d') }}">
                                {{ $d->date_emission->format('d/m/Y') }}
                            </td>
                            <td class="small">
                                {{ $d->tiers?->displayName() }}
                            </td>
                            <td class="small">{{ $d->libelle }}</td>
                            <td class="text-end small text-nowrap fw-semibold" data-sort="{{ $d->montant_total }}">
                                {{ number_format((float) $d->montant_total, 2, ',', "\u{202F}") }}&nbsp;&euro;
                            </td>
                            <td>
                                @if ($d->statut === \App\Enums\StatutDevis::Brouillon)
                                    <span class="badge bg-secondary" style="font-size:.7rem">
                                        <i class="bi bi-pencil"></i> Brouillon
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Envoye)
                                    <span class="badge bg-primary" style="font-size:.7rem">
                                        <i class="bi bi-send"></i> Envoyé
                                    </span>
                                    @if ($expired)
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">
                                            <i class="bi bi-clock-history"></i> Expiré
                                        </span>
                                    @endif
                                @elseif ($d->statut === \App\Enums\StatutDevis::Accepte)
                                    <span class="badge bg-success" style="font-size:.7rem">
                                        <i class="bi bi-check-circle"></i> Accepté
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Refuse)
                                    <span class="badge bg-danger" style="font-size:.7rem">
                                        <i class="bi bi-x-circle"></i> Refusé
                                    </span>
                                @elseif ($d->statut === \App\Enums\StatutDevis::Annule)
                                    <span class="badge bg-dark" style="font-size:.7rem">
                                        <i class="bi bi-slash-circle"></i> Annulé
                                    </span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('devis-libres.show', $d) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $devis->links() }}
        </div>
    @endif
</div>
