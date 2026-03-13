<div>
    @if (! $showForm)
        <div class="mb-3">
            <button wire:click="showNewForm" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nouvelle recette
            </button>
        </div>
    @else
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ $recetteId ? 'Modifier la recette' : 'Nouvelle recette' }}</h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            @if ($recetteId && $recette_numero_piece)
                <div class="px-3 pt-2 text-muted small">
                    N° pièce : <strong>{{ $recette_numero_piece }}</strong>
                </div>
            @endif
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3 mb-4">
                        <div class="col-md-2">
                            <label for="date" class="form-label">
                                Date <span class="text-danger">*</span>
                                @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
                            </label>
                            @if ($isLocked)
                                <input type="text" value="{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}" class="form-control bg-light" disabled>
                            @else
                                <input type="date" wire:model="date" id="date"
                                       class="form-control @error('date') is-invalid @enderror">
                                @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @endif
                        </div>
                        <div class="col-md-2">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" wire:model="reference" id="reference" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
                            <input type="text" wire:model="libelle" id="libelle"
                                   class="form-control @error('libelle') is-invalid @enderror">
                            @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="tiers" class="form-label">Tiers</label>
                            <input type="text" wire:model="tiers" id="tiers" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label for="mode_paiement" class="form-label">Mode paiement <span class="text-danger">*</span></label>
                            <select wire:model="mode_paiement" id="mode_paiement"
                                    class="form-select @error('mode_paiement') is-invalid @enderror">
                                <option value="">-- Choisir --</option>
                                @foreach ($modesPaiement as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                            @error('mode_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_id" class="form-label">
                                Compte bancaire
                                @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
                            </label>
                            @if ($isLocked)
                                <input type="text" value="{{ $comptes->firstWhere('id', $compte_id)?->nom ?? '—' }}"
                                       class="form-control bg-light" disabled>
                            @else
                                <select wire:model="compte_id" id="compte_id" class="form-select">
                                    <option value="">-- Aucun --</option>
                                    @foreach ($comptes as $compte)
                                        <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">
                                Montant total
                                @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
                            </label>
                            <div class="form-control bg-light fw-bold text-end">
                                {{ number_format($this->montantTotal, 2, ',', ' ') }} €
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" wire:model="notes" id="notes" class="form-control">
                        </div>
                    </div>

                    {{-- Lignes section --}}
                    <h6 class="mb-2">Lignes de recette</h6>
                    @error('lignes')
                        <div class="alert alert-danger py-2">{{ $message }}</div>
                    @enderror

                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sous-catégorie <span class="text-danger">*</span></th>
                                    <th>Opération</th>
                                    <th style="width: 100px;">Séance</th>
                                    <th style="width: 130px;">Montant <span class="text-danger">*</span></th>
                                    <th>Notes</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lignes as $index => $ligne)
                                    <tr wire:key="ligne-{{ $index }}">
                                        <td>
                                            <select wire:model="lignes.{{ $index }}.sous_categorie_id"
                                                    class="form-select form-select-sm @error('lignes.' . $index . '.sous_categorie_id') is-invalid @enderror">
                                                <option value="">-- Choisir --</option>
                                                @foreach ($sousCategories->groupBy('categorie.nom') as $catNom => $sousCats)
                                                    <optgroup label="{{ $catNom }}">
                                                        @foreach ($sousCats as $sc)
                                                            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                            @error('lignes.' . $index . '.sous_categorie_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            <select wire:model.live="lignes.{{ $index }}.operation_id"
                                                    class="form-select form-select-sm">
                                                <option value="">-- Aucune --</option>
                                                @foreach ($operations as $op)
                                                    <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            @php
                                                $selectedOp = $ligne['operation_id'] !== '' ? $operations->firstWhere('id', (int) $ligne['operation_id']) : null;
                                                $nbSeances = $selectedOp?->nombre_seances;
                                            @endphp
                                            @if ($nbSeances)
                                                <select wire:model="lignes.{{ $index }}.seance"
                                                        class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    @for ($s = 1; $s <= $nbSeances; $s++)
                                                        <option value="{{ $s }}">{{ $s }}</option>
                                                    @endfor
                                                </select>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($isLocked)
                                                <span class="form-control-plaintext">{{ number_format((float) ($ligne['montant'] ?? 0), 2, ',', ' ') }} €</span>
                                            @else
                                                <input type="number" wire:model.live="lignes.{{ $index }}.montant"
                                                       step="0.01" min="0.01"
                                                       class="form-control form-control-sm @error('lignes.' . $index . '.montant') is-invalid @enderror">
                                                @error('lignes.' . $index . '.montant')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" wire:model="lignes.{{ $index }}.notes"
                                                   class="form-control form-control-sm">
                                        </td>
                                        <td class="text-center">
                                            @if (! $isLocked)
                                                <button type="button" wire:click="removeLigne({{ $index }})"
                                                        class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted text-center">Aucune ligne. Cliquez sur "Ajouter une ligne".</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end fw-bold">Total lignes :</td>
                                    <td class="fw-bold">
                                        {{ number_format($this->montantTotal, 2, ',', ' ') }} &euro;
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-flex gap-2">
                        @if (! $isLocked)
                            <button type="button" wire:click="addLigne" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-plus-lg"></i> Ajouter une ligne
                            </button>
                        @endif
                        <div class="ms-auto">
                            <button type="button" wire:click="resetForm" class="btn btn-secondary">Annuler</button>
                            <button type="submit" class="btn btn-success"
                                    @if ($isLocked) title="Certains champs sont verrouillés et ne pourront pas être modifiés." @endif>
                                {{ $recetteId ? 'Mettre à jour' : 'Enregistrer' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
