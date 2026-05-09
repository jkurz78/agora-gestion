<div x-on:keydown.escape.window="$wire.close()">
    @if($visible)
        @php($selectedFormule = $formuleId ? $formules->firstWhere('id', $formuleId) : null)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="z-index:2055">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi {{ $gratuite ? 'bi-gift' : 'bi-plus-circle' }} me-2"></i>
                            {{ $gratuite ? 'Offrir une adhésion' : 'Nouvelle adhésion' }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="close"></button>
                    </div>
                    <div class="modal-body">
                        @if($errorMessage)
                            <div class="alert alert-danger py-2 mb-3" role="alert">
                                {{ $errorMessage }}
                            </div>
                        @endif

                        {{-- Tiers --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Adhérent</label>
                            <livewire:tiers-autocomplete
                                wire:model="tiersId"
                                :key="'nouvelle-adhesion-tiers-'.($visible ? '1' : '0')"
                                context="adhesion" />
                            @error('tiersId')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Formule --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="nouvelle-formule">Formule</label>
                            <select id="nouvelle-formule"
                                    class="form-select form-select-sm @error('formuleId') is-invalid @enderror"
                                    wire:model.live="formuleId">
                                <option value="">— Choisir une formule —</option>
                                @foreach($formules as $formule)
                                    <option value="{{ $formule->id }}">
                                        {{ $formule->nom }}
                                        @if($formule->isModeDuree())
                                            ({{ $formule->duree_mois }} mois)
                                        @else
                                            (par exercice)
                                        @endif
                                        @if($formule->montant_par_defaut !== null)
                                            — {{ number_format((float) $formule->montant_par_defaut, 2, ',', ' ') }} €
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('formuleId')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            @if($formules->isEmpty())
                                <div class="form-text text-warning">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Aucune formule active.
                                    @if(\Route::has('parametres.adhesions.formules'))
                                        <a href="{{ route('parametres.adhesions.formules') }}">Paramétrez-en une.</a>
                                    @else
                                        Paramétrez-en une dans Paramètres → Adhésions → Formules.
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Mode dispatch --}}
                        @if($selectedFormule && $selectedFormule->isModeExercice())
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="nouvelle-exercice">Exercice</label>
                                <select id="nouvelle-exercice"
                                        class="form-select form-select-sm"
                                        wire:model="exercice">
                                    @foreach($availableYears as $year)
                                        <option value="{{ $year }}">{{ $year }}-{{ $year + 1 }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif($selectedFormule && $selectedFormule->isModeDuree())
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="nouvelle-date-debut">Date de début</label>
                                    <input id="nouvelle-date-debut"
                                           type="date"
                                           class="form-control form-control-sm"
                                           wire:model.live="dateDebut">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Date de fin (calculée)</label>
                                    <input type="date"
                                           class="form-control form-control-sm bg-light"
                                           value="{{ $this->dateFinCalculee }}"
                                           readonly>
                                </div>
                            </div>
                        @endif

                        {{-- Montant --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="nouvelle-montant">Montant (€)</label>
                            <input id="nouvelle-montant"
                                   type="number"
                                   step="0.01"
                                   min="0"
                                   class="form-control form-control-sm @error('montant') is-invalid @enderror"
                                   wire:model.live="montant">
                            <div class="form-text">Mettez 0 pour une adhésion offerte (sans paiement).</div>
                            @error('montant')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Bloc paiement (visible uniquement si montant > 0) --}}
                        @if($montant > 0)
                            <hr class="my-3">
                            <h6 class="text-muted mb-2">Détails du paiement</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="nouvelle-date-paiement">Date du paiement</label>
                                    <input id="nouvelle-date-paiement"
                                           type="date"
                                           class="form-control form-control-sm @error('datePaiement') is-invalid @enderror"
                                           wire:model="datePaiement">
                                    @error('datePaiement')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="nouvelle-mode">Mode de paiement</label>
                                    <select id="nouvelle-mode"
                                            class="form-select form-select-sm @error('modePaiement') is-invalid @enderror"
                                            wire:model="modePaiement">
                                        <option value="">— Choisir —</option>
                                        @foreach($modesPaiement as $mode)
                                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('modePaiement')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="nouvelle-compte">Compte</label>
                                    <select id="nouvelle-compte"
                                            class="form-select form-select-sm @error('compteId') is-invalid @enderror"
                                            wire:model="compteId">
                                        <option value="">— Choisir —</option>
                                        @foreach($comptes as $compte)
                                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                        @endforeach
                                    </select>
                                    @error('compteId')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="nouvelle-reference">Référence (optionnelle)</label>
                                    <input id="nouvelle-reference"
                                           type="text"
                                           class="form-control form-control-sm"
                                           wire:model="reference"
                                           maxlength="100">
                                </div>
                            </div>
                        @endif

                        {{-- Notes --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="nouvelle-notes">Notes (optionnel)</label>
                            <input id="nouvelle-notes"
                                   type="text"
                                   list="nouvelle-notes-suggestions"
                                   class="form-control form-control-sm"
                                   wire:model="notes"
                                   maxlength="255"
                                   autocomplete="off"
                                   placeholder="Ex : Membre d'honneur, bénévole…">
                            <datalist id="nouvelle-notes-suggestions">
                                @foreach($notesSuggestions as $suggestion)
                                    <option value="{{ $suggestion }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="close">
                            Annuler
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="submit">
                            <i class="bi bi-check-lg me-1"></i>Créer l'adhésion
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show" style="z-index:2054"></div>
    @endif
</div>
