@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
    $exerciceService = app(App\Services\ExerciceService::class);
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="{{ route('immobilisations.index') }}" class="text-decoration-none small">
                <i class="bi bi-arrow-left"></i> Livre des immobilisations
            </a>
            <h4 class="mb-0 mt-1">{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</h4>
        </div>
        <div class="d-flex gap-2">
            @if ($this->canEdit)
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="ouvrirEdition">
                <i class="bi bi-pencil me-1"></i> Modifier
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="supprimer"
                    wire:confirm="Supprimer cette fiche ? L'écriture comptable d'acquisition sera supprimée elle aussi.">
                <i class="bi bi-trash me-1"></i> Supprimer
            </button>
            @endif
            <a href="{{ route('immobilisations.pdf', $immobilisation) }}" class="btn btn-outline-secondary btn-sm" target="_blank">
                <i class="bi bi-file-earmark-pdf me-1"></i> Imprimer la fiche
            </a>
        </div>
    </div>

    @if ($flashMessage !== '')
        <div class="alert alert-{{ $flashType === 'success' ? 'success' : 'danger' }}">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Identité</h6></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Quantité</dt><dd class="col-7">{{ $immobilisation->quantite }}</dd>
                        <dt class="col-5">Compte</dt>
                        <dd class="col-7">{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}</dd>
                        <dt class="col-5">Amortissements</dt>
                        <dd class="col-7">{{ $immobilisation->compteAmortissement->numero_pcg }} — {{ $immobilisation->compteAmortissement->intitule }}</dd>
                        <dt class="col-5">Mise en service</dt>
                        <dd class="col-7">
                            {{ $immobilisation->date_mise_en_service->format('d/m/Y') }}
                            @unless ($immobilisation->estEnService())
                                <span class="badge bg-warning text-dark">Pas encore en service</span>
                            @endunless
                        </dd>
                        <dt class="col-5">Durée</dt><dd class="col-7">{{ $immobilisation->duree_label }}</dd>
                        <dt class="col-5">Valeur brute</dt>
                        <dd class="col-7">{{ $euros($immobilisation->montantAcquisitionCentimes()) }} €</dd>
                        <dt class="col-5">Valeur nette</dt>
                        <dd class="col-7 fw-semibold">{{ $euros($immobilisation->valeurNetteCentimes()) }} €</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Acquisition</h6></div>
                <div class="card-body">
                    @foreach ($immobilisation->transactionsAcquisition() as $tx)
                        <dl class="row mb-0">
                            <dt class="col-5">Date</dt><dd class="col-7">{{ $tx->date->format('d/m/Y') }}</dd>
                            <dt class="col-5">Fournisseur</dt><dd class="col-7">{{ $tx->tiers?->nom_complet ?? '—' }}</dd>
                            <dt class="col-5">Pièce</dt><dd class="col-7">{{ $tx->numero_piece ?? '—' }}</dd>
                            <dt class="col-5">Montant</dt><dd class="col-7">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} €</dd>
                            @if ($tx->hasPieceJointe())
                                <dt class="col-5">Justificatif</dt>
                                <dd class="col-7">
                                    <a href="{{ $tx->pieceJointeUrl() }}" target="_blank">
                                        <i class="bi bi-paperclip"></i> {{ $tx->piece_jointe_nom }}
                                    </a>
                                </dd>
                            @endif
                        </dl>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2"
                                wire:click="ouvrirTransaction({{ $tx->id }})">
                            <i class="bi bi-journal-text me-1"></i> Voir l’écriture d’acquisition
                        </button>
                    @endforeach
                    @if ($immobilisation->notes)
                        <hr><p class="mb-0 small text-muted">{{ $immobilisation->notes }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h6 class="mb-0">Plan d'amortissement</h6></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Exercice</th>
                        <th class="text-end">Mois</th>
                        <th class="text-end">Dotation</th>
                        <th class="text-end">Cumul</th>
                        <th class="text-end">Valeur nette</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan as $ligne)
                        <tr class="{{ $ligne['comptabilisee'] ? '' : 'text-muted fst-italic' }}">
                            <td>{{ $exerciceService->label($ligne['exercice']) }}</td>
                            <td class="text-end">{{ $ligne['moisEcoules'] }}</td>
                            <td class="text-end">{{ $euros($ligne['dotationCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['cumulCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['valeurNetteCentimes']) }} €</td>
                            <td>
                                @if ($ligne['comptabilisee'])
                                    <span class="badge bg-success">Comptabilisée</span>
                                    @if ($ligne['transactionId'] !== null)
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1"
                                                wire:click="ouvrirTransaction({{ $ligne['transactionId'] }})"
                                                title="Voir l’écriture de dotation">
                                            <i class="bi bi-journal-text"></i>
                                        </button>
                                    @endif
                                @else
                                    <span class="badge bg-light text-dark border">Prévisionnel</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($showEditModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
             data-bs-backdrop="static" data-bs-keyboard="false" wire:key="modal-edit-immo">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifier {{ $immobilisation->numero }}</h5>
                        <button type="button" class="btn-close" wire:click="fermerEdition"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Libellé</label>
                                <input type="text" class="form-control @error('libelle') is-invalid @enderror"
                                       wire:model="libelle">
                                @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantité</label>
                                <input type="number" min="1" class="form-control @error('quantite') is-invalid @enderror"
                                       wire:model.live="quantite">
                                <div class="form-text">
                                    Sert au suivi de l’inventaire : n’entre pas dans le calcul de l’amortissement.
                                </div>
                                @error('quantite') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Montant total de l’acquisition</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" readonly
                                           value="{{ number_format((float) $immobilisation->montant_acquisition, 2, ',', ' ') }}">
                                    <span class="input-group-text">€</span>
                                </div>
                                @if ($quantite > 1 && (float) $immobilisation->montant_acquisition > 0)
                                    <div class="form-text">
                                        soit {{ number_format((float) $immobilisation->montant_acquisition / $quantite, 2, ',', ' ') }} € l’unité
                                    </div>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Compte d'immobilisation</label>
                                <input type="text" class="form-control" readonly
                                       value="{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}">
                            </div>
                            <div class="col-12">
                                <div class="form-text">
                                    Le montant et le compte ne sont pas modifiables : ils engagent l'écriture
                                    comptable d'acquisition, potentiellement déjà réglée, lettrée ou rapprochée.
                                    Pour les corriger, supprimez la fiche puis resaisissez-la.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Mise en service</label>
                                <input type="date" class="form-control @error('date_mise_en_service') is-invalid @enderror"
                                       wire:model="date_mise_en_service">
                                @error('date_mise_en_service') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                @include('livewire.immobilisations.partials._duree-selector')
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" rows="2" wire:model="notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerEdition">{{ $this->canEdit ? 'Annuler' : 'Fermer' }}</button>
                        @if ($this->canEdit)
                        <button type="button" class="btn btn-primary" wire:click="enregistrerModification">Enregistrer</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
