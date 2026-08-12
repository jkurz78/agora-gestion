@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Livre des immobilisations</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('immobilisations.dotations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-calculator me-1"></i> Dotations de l'exercice
            </a>
            @if ($this->canEdit)
            <button type="button" class="btn btn-primary btn-sm" wire:click="ouvrirModal">
                <i class="bi bi-plus-lg me-1"></i> Nouvelle immobilisation
            </button>
            @endif
        </div>
    </div>

    @if ($flashMessage !== '')
        <div class="alert alert-{{ $flashType === 'success' ? 'success' : 'info' }}">
            {{ $flashMessage }}
        </div>
    @endif

    @if ($immobilisations->isEmpty())
        <div class="alert alert-info">
            Aucune immobilisation enregistrée. Les biens durables (matériel, mobilier,
            informatique) s'inscrivent ici plutôt qu'en charge de l'exercice.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="table-immobilisations">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th class="text-end">Qté</th>
                        <th>Compte</th>
                        <th>Mise en service</th>
                        <th>Durée</th>
                        <th class="text-end">Valeur brute</th>
                        <th class="text-end">Amortissements</th>
                        <th class="text-end">Valeur nette</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($immobilisations as $immo)
                        <tr>
                            <td data-sort="{{ $immo->numero }}">
                                <a href="{{ route('immobilisations.show', $immo) }}">{{ $immo->numero }}</a>
                            </td>
                            <td>
                                {{ $immo->libelle }}
                                @unless ($immo->estEnService())
                                    <span class="badge bg-warning text-dark ms-1">Pas encore en service</span>
                                @endunless
                            </td>
                            <td class="text-end" data-sort="{{ $immo->quantite }}">{{ $immo->quantite }}</td>
                            <td>{{ $immo->compte->numero_pcg }} — {{ $immo->compte->intitule }}</td>
                            <td data-sort="{{ $immo->date_mise_en_service->format('Y-m-d') }}">
                                {{ $immo->date_mise_en_service->format('d/m/Y') }}
                            </td>
                            <td data-sort="{{ $immo->duree_mois }}">{{ $immo->duree_label }}</td>
                            <td class="text-end" data-sort="{{ $immo->montantAcquisitionCentimes() }}">
                                {{ $euros($immo->montantAcquisitionCentimes()) }} €
                            </td>
                            <td class="text-end" data-sort="{{ $immo->cumulAmortiCentimes() }}">
                                {{ $euros($immo->cumulAmortiCentimes()) }} €
                            </td>
                            <td class="text-end fw-semibold" data-sort="{{ $immo->valeurNetteCentimes() }}">
                                {{ $euros($immo->valeurNetteCentimes()) }} €
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="6" class="text-end">Totaux</td>
                        <td class="text-end">{{ $euros($totalBrutCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalCumulCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalNetCentimes) }} €</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <x-per-page-selector :paginator="$immobilisations" storageKey="immobilisations" wire:model.live="perPage" />
        {{ $immobilisations->links() }}
    @endif

    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
             data-bs-backdrop="static" data-bs-keyboard="false" wire:key="modal-immo">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle immobilisation</h5>
                        <button type="button" class="btn-close" wire:click="fermerModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Libellé</label>
                                <input type="text" class="form-control @error('libelle') is-invalid @enderror"
                                       wire:model="libelle" placeholder="20 tenues d'escrime">
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
                                <label class="form-label">Fournisseur</label>
                                <livewire:tiers-autocomplete wire:model="tiers_id" :key="'tiers-immo'" />
                                @error('tiers_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Montant total de l’acquisition</label>
                                <div class="input-group">
                                    <input type="text" class="form-control @error('montant') is-invalid @enderror"
                                           wire:model.live="montant" inputmode="decimal">
                                    <span class="input-group-text">€</span>
                                    @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                @if ($quantite > 1 && $montant !== '' && (float) $montant > 0)
                                    <div class="form-text">
                                        soit {{ number_format((float) $montant / $quantite, 2, ',', ' ') }} € l’unité
                                    </div>
                                @endif
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Compte d'immobilisation</label>
                                <select class="form-select @error('compte_id') is-invalid @enderror" wire:model.live="compte_id">
                                    <option value="">— choisir —</option>
                                    @foreach ($comptesImmobilisation as $groupe)
                                        <optgroup label="{{ $groupe['famille']?->nom ?? 'Autres' }}">
                                            @foreach ($groupe['comptes'] as $compte)
                                                <option value="{{ $compte->id }}">
                                                    {{ $compte->numero_pcg }} — {{ $compte->intitule }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error('compte_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Compte d'amortissement</label>
                                <input type="text" class="form-control" readonly
                                       value="{{ $compte_amortissement_id ? \App\Models\Compte::find((int) $compte_amortissement_id)?->numero_pcg.' — '.\App\Models\Compte::find((int) $compte_amortissement_id)?->intitule : '' }}">
                                @error('compte_amortissement_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Date d'achat</label>
                                <input type="date" class="form-control @error('date_achat') is-invalid @enderror"
                                       wire:model.live="date_achat">
                                @error('date_achat') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Mise en service</label>
                                <input type="date" class="form-control @error('date_mise_en_service') is-invalid @enderror"
                                       wire:model="date_mise_en_service">
                                @error('date_mise_en_service') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                @include('livewire.immobilisations.partials._duree-selector')
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Paiement effectué ?</label>
                                <div class="d-flex gap-2 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" wire:model.live="regleImmediatement"
                                               id="immo_regle_oui" value="1">
                                        <label class="form-check-label" for="immo_regle_oui">Oui</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" wire:model.live="regleImmediatement"
                                               id="immo_regle_non" value="0">
                                        <label class="form-check-label" for="immo_regle_non">Non</label>
                                    </div>
                                </div>
                            </div>
                            @if ($regleImmediatement)
                                <div class="col-md-4">
                                    <label for="immo_mode_paiement" class="form-label">Mode paiement <span class="text-danger">*</span></label>
                                    <select wire:model="mode_paiement" id="immo_mode_paiement"
                                            class="form-select @error('mode_paiement') is-invalid @enderror">
                                        <option value="">-- Choisir --</option>
                                        @foreach ($modesPaiement as $mode)
                                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('mode_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-5">
                                    <label for="immo_compte_reglement_id" class="form-label">Compte bancaire</label>
                                    <select wire:model="compte_reglement_id" id="immo_compte_reglement_id"
                                            class="form-select @error('compte_reglement_id') is-invalid @enderror">
                                        <option value="">-- Aucun --</option>
                                        @foreach ($comptesBancaires as $compte)
                                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                        @endforeach
                                    </select>
                                    @error('compte_reglement_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div class="col-12">
                                <label class="form-label"><i class="bi bi-paperclip"></i> Justificatif</label>
                                <div class="d-flex align-items-center gap-2">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <i class="bi bi-paperclip"></i> Joindre un justificatif
                                        <input type="file" wire:model="pieceJointeAcquisition" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                                    </label>
                                    @if ($pieceJointeAcquisition)
                                        <span class="small text-success"><i class="bi bi-check-circle"></i> {{ $pieceJointeAcquisition->getClientOriginalName() }}</span>
                                    @endif
                                    <div wire:loading wire:target="pieceJointeAcquisition" class="spinner-border spinner-border-sm text-primary"></div>
                                </div>
                                @error('pieceJointeAcquisition') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" rows="2" wire:model="notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModal">{{ $this->canEdit ? 'Annuler' : 'Fermer' }}</button>
                        @if ($this->canEdit)
                        <button type="button" class="btn btn-primary" wire:click="enregistrer">Enregistrer</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
