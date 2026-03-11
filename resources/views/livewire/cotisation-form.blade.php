<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Cotisations</h5>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Exercice</th>
                        <th>Montant</th>
                        <th>Date paiement</th>
                        <th>Mode paiement</th>
                        <th>Pointé</th>
                        <th style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cotisations as $cotisation)
                        <tr>
                            <td>{{ $exerciceService->label($cotisation->exercice) }}</td>
                            <td>{{ number_format((float) $cotisation->montant, 2, ',', ' ') }} &euro;</td>
                            <td>{{ $cotisation->date_paiement->format('d/m/Y') }}</td>
                            <td>{{ $cotisation->mode_paiement->label() }}</td>
                            <td>
                                @if ($cotisation->pointe)
                                    <span class="text-success fw-bold">&check;</span>
                                @else
                                    <span class="text-muted">&cross;</span>
                                @endif
                            </td>
                            <td>
                                <button wire:click="delete({{ $cotisation->id }})"
                                        wire:confirm="Supprimer cette cotisation ?"
                                        class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">Aucune cotisation enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Ajouter une cotisation</h5>
        </div>
        <div class="card-body">
            <form wire:submit="save">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="exercice" class="form-label">Exercice <span class="text-danger">*</span></label>
                        <select wire:model="exercice" id="exercice" class="form-select @error('exercice') is-invalid @enderror">
                            @foreach ($exercices as $ex)
                                <option value="{{ $ex }}">{{ $exerciceService->label($ex) }}</option>
                            @endforeach
                        </select>
                        @error('exercice')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="montant" class="form-label">Montant <span class="text-danger">*</span></label>
                        <input type="number" wire:model="montant" id="montant" step="0.01" min="0.01"
                               class="form-control @error('montant') is-invalid @enderror">
                        @error('montant')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="date_paiement" class="form-label">Date paiement <span class="text-danger">*</span></label>
                        <input type="date" wire:model="date_paiement" id="date_paiement"
                               class="form-control @error('date_paiement') is-invalid @enderror">
                        @error('date_paiement')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
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
                        @error('mode_paiement')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="compte_id" class="form-label">Compte bancaire</label>
                        <select wire:model="compte_id" id="compte_id" class="form-select">
                            <option value="">-- Aucun --</option>
                            @foreach ($comptes as $compte)
                                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100">Ajouter</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
