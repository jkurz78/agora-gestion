<div>
    {{-- Sélection du compte --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="compte-select" class="form-label">Compte bancaire</label>
                    <select wire:model.live="compte_id" id="compte-select" class="form-select">
                        <option value="">-- Sélectionner un compte --</option>
                        @foreach ($comptes as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($compte_id && ! $aEnCours)
                    <div class="col-md-auto">
                        <button wire:click="$set('showCreateForm', true)"
                                class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                        </button>
                    </div>
                @elseif ($compte_id && $aEnCours)
                    <div class="col-md-auto">
                        <button class="btn btn-primary" disabled
                                title="Finalisez le rapprochement en cours avant d'en créer un nouveau.">
                            <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                        </button>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle"></i> Un rapprochement est en cours.
                        </div>
                    </div>
                @endif
            </div>

            {{-- Formulaire de création --}}
            @if ($showCreateForm)
                <div class="mt-3 p-3 border rounded bg-light">
                    <h6 class="mb-3">Nouveau relevé bancaire</h6>
                    @if ($soldeOuverture !== null)
                        <p class="mb-2 text-muted small">
                            Solde d'ouverture automatique :
                            <strong>{{ number_format($soldeOuverture, 2, ',', ' ') }} €</strong>
                        </p>
                    @endif
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Date de fin du relevé <span class="text-danger">*</span></label>
                            <input type="date" wire:model="date_fin"
                                   class="form-control @error('date_fin') is-invalid @enderror">
                            @error('date_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Solde de fin du relevé <span class="text-danger">*</span></label>
                            <input type="number" wire:model="solde_fin" step="0.01"
                                   class="form-control @error('solde_fin') is-invalid @enderror">
                            @error('solde_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-auto">
                            <button wire:click="create" class="btn btn-success">Créer</button>
                            <button wire:click="$set('showCreateForm', false)" class="btn btn-secondary">Annuler</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Liste des rapprochements --}}
    @if ($compte_id)
        @if ($rapprochements->isEmpty())
            <div class="alert alert-info">
                Aucun rapprochement pour ce compte. Créez le premier en cliquant sur "Nouveau rapprochement".
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Date de fin</th>
                            <th class="text-end">Solde ouverture</th>
                            <th class="text-end">Solde fin</th>
                            <th>Statut</th>
                            <th>Verrouillé le</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rapprochements as $rapprochement)
                            <tr>
                                <td>{{ $rapprochement->date_fin->format('d/m/Y') }}</td>
                                <td class="text-end">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</td>
                                <td class="text-end">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</td>
                                <td>
                                    @if ($rapprochement->isVerrouille())
                                        <span class="badge bg-secondary"><i class="bi bi-lock"></i> Verrouillé</span>
                                    @else
                                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil"></i> En cours</span>
                                    @endif
                                </td>
                                <td>{{ $rapprochement->verrouille_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('rapprochement.detail', $rapprochement) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                        {{ $rapprochement->isEnCours() ? 'Continuer' : 'Consulter' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                {{ $rapprochements->links() }}
            </div>
        @endif
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sélectionnez un compte bancaire pour afficher ses rapprochements.
        </div>
    @endif
</div>
