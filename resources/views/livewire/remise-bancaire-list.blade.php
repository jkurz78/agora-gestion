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

    {{-- Bouton Nouvelle remise --}}
    @if ($this->canEdit)
        <div class="d-flex justify-content-end mb-3">
            <button wire:click="$set('showCreateForm', true)" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Nouvelle remise
            </button>
        </div>

        {{-- Formulaire de création --}}
        @if ($showCreateForm)
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="mb-3">Nouvelle remise en banque</h6>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <x-date-input name="date" wire:model="date" :value="$date" />
                            @error('date') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Compte bancaire <span class="text-danger">*</span></label>
                            <select wire:model="compte_cible_id" class="form-select @error('compte_cible_id') is-invalid @enderror">
                                <option value="">-- Sélectionner --</option>
                                @foreach ($comptes as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compte_cible_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Type <span class="text-danger">*</span></label>
                            <select wire:model="mode_paiement" class="form-select @error('mode_paiement') is-invalid @enderror">
                                <option value="cheque">Chèques</option>
                                <option value="especes">Espèces</option>
                            </select>
                            @error('mode_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-auto">
                            <button wire:click="create" class="btn btn-success">
                                <i class="bi bi-check-lg"></i> Créer
                            </button>
                            <button wire:click="$set('showCreateForm', false)" class="btn btn-secondary">
                                Annuler
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Liste des remises --}}
    @if ($remises->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucune remise en banque. Cliquez sur "Nouvelle remise" pour en créer une.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>N°</th>
                        <th>Libellé</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Banque</th>
                        <th class="text-end">Nb pièces</th>
                        <th class="text-end">Montant</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($remises as $remise)
                        <tr wire:key="remise-{{ $remise->id }}">
                            <td class="small">{{ $remise->numero }}</td>
                            <td class="small">
                                <a href="{{ route('banques.remises.show', $remise) }}" class="text-decoration-none">
                                    {{ $remise->libelle }}
                                </a>
                            </td>
                            <td class="small text-nowrap">{{ $remise->date->format('d/m/Y') }}</td>
                            <td class="small">{{ $remise->mode_paiement->label() }}</td>
                            <td class="small">{{ $remise->compteCible->nom }}</td>
                            <td class="text-end small">{{ $remise->reglements->count() + $remise->transactionsDirectes->count() }}</td>
                            <td class="text-end small text-nowrap fw-semibold">{{ number_format($remise->montantTotal(), 2, ',', ' ') }} €</td>
                            <td>
                                @if ($remise->virement_id === null)
                                    <span class="badge bg-warning text-dark" style="font-size:.7rem">
                                        <i class="bi bi-pencil"></i> Brouillon
                                    </span>
                                @elseif ($remise->isVerrouillee())
                                    <span class="badge bg-secondary" style="font-size:.7rem">
                                        <i class="bi bi-lock"></i> Verrouillée
                                    </span>
                                @else
                                    <span class="badge bg-success" style="font-size:.7rem">
                                        <i class="bi bi-check-circle"></i> Comptabilisée
                                    </span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('banques.remises.show', $remise) }}"
                                       class="btn btn-sm btn-outline-primary" title="Voir">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if ($this->canEdit && ! $remise->isVerrouillee())
                                        <a href="{{ route('banques.remises.selection', $remise) }}"
                                           class="btn btn-sm btn-outline-secondary" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button wire:click="supprimer({{ $remise->id }})"
                                                wire:confirm="Supprimer cette remise ? Les transactions et le virement associés seront supprimés."
                                                class="btn btn-sm btn-outline-danger" title="Supprimer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    @endif
                                    @if ($remise->virement_id !== null)
                                        <a href="{{ route('banques.remises.pdf', $remise) }}?mode=inline"
                                           class="btn btn-sm btn-outline-dark" title="PDF" target="_blank">
                                            <i class="bi bi-file-pdf"></i>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $remises->links() }}
        </div>
    @endif
</div>
