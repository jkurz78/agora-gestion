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

    {{-- Sélection du compte + action --}}
    <div class="d-flex align-items-center gap-3 mb-3">
        <label for="compte-select" class="form-label mb-0 small text-muted">Compte</label>
        <select wire:model.live="compte_id" id="compte-select" class="form-select form-select-sm" style="max-width:250px;">
            <option value="">-- Sélectionner un compte --</option>
            @foreach ($comptes as $compte)
                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
            @endforeach
        </select>
        @if (! $exerciceCloture && $compte_id)
            @if (! $aEnCours)
                <button wire:click="$set('showCreateForm', true)"
                        class="btn btn-primary btn-sm ms-auto">
                    <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                </button>
            @else
                <button class="btn btn-primary btn-sm ms-auto" disabled
                        title="Finalisez le rapprochement en cours avant d'en créer un nouveau.">
                    <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                </button>
                <span class="text-warning small">
                    <i class="bi bi-exclamation-triangle"></i> En cours
                </span>
            @endif
        @endif
    </div>

    {{-- Formulaire de création --}}
    @if ($showCreateForm && ! $exerciceCloture)
        <div class="p-3 border rounded bg-light mb-3">
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
                    <x-date-input name="date_fin" wire:model="date_fin" :value="$date_fin" />
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

    {{-- Liste des rapprochements --}}
    @if ($compte_id)
        @if ($rapprochements->isEmpty())
            <div class="alert alert-info">
                Aucun rapprochement pour ce compte. Créez le premier en cliquant sur "Nouveau rapprochement".
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Date de fin</th>
                            <th class="text-end">Solde ouverture</th>
                            <th class="text-end">Total débit</th>
                            <th class="text-end">Total crédit</th>
                            <th class="text-end">Solde fin</th>
                            <th>Statut</th>
                            <th>Verrouillé le</th>
                            <th class="text-center" style="width: 80px">Pièce</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @foreach ($rapprochements as $rapprochement)
                            <tr wire:key="rapprochement-{{ $rapprochement->id }}">
                                <td class="small text-nowrap">{{ $rapprochement->date_fin->format('d/m/Y') }}</td>
                                <td class="text-end fw-semibold small text-nowrap">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</td>
                                <td class="text-end small text-nowrap text-danger">{{ number_format($rapprochementTotals[$rapprochement->id]['debit'] ?? 0, 2, ',', ' ') }} €</td>
                                <td class="text-end small text-nowrap text-success">{{ number_format($rapprochementTotals[$rapprochement->id]['credit'] ?? 0, 2, ',', ' ') }} €</td>
                                <td class="text-end fw-semibold small text-nowrap">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</td>
                                <td>
                                    @if ($rapprochement->isVerrouille())
                                        <span class="badge bg-secondary" style="font-size:.7rem"><i class="bi bi-lock"></i> Verrouillé</span>
                                    @else
                                        <span class="badge bg-warning text-dark" style="font-size:.7rem"><i class="bi bi-pencil"></i> En cours</span>
                                    @endif
                                </td>
                                <td class="small text-muted text-nowrap">{{ $rapprochement->verrouille_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-center">
                                    @if ($rapprochement->hasPieceJointe())
                                        <a href="{{ $rapprochement->pieceJointeUrl() }}" target="_blank"
                                           class="btn btn-sm btn-outline-success"
                                           title="{{ $rapprochement->piece_jointe_nom }}">
                                            <i class="bi bi-paperclip"></i>
                                        </a>
                                        @if (! $exerciceCloture)
                                            <button wire:click="openPieceJointeModal({{ $rapprochement->id }})"
                                                    class="btn btn-sm btn-outline-secondary ms-1"
                                                    title="Remplacer ou supprimer">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @endif
                                    @else
                                        @if (! $exerciceCloture)
                                            <button wire:click="openPieceJointeModal({{ $rapprochement->id }})"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    title="Joindre un fichier">
                                                <i class="bi bi-paperclip"></i>
                                            </button>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('banques.rapprochement.detail', $rapprochement) }}"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                            {{ $rapprochement->isEnCours() && ! $exerciceCloture ? 'Continuer' : 'Consulter' }}
                                        </a>
                                        @if (! $exerciceCloture)
                                        @if ($rapprochement->isEnCours())
                                            <button wire:click="supprimer({{ $rapprochement->id }})"
                                                    wire:confirm="Supprimer ce rapprochement ? Toutes les écritures pointées seront dépointées."
                                                    class="btn btn-sm btn-outline-danger" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        @elseif ($rapprochement->id === $dernierVerrouilleId)
                                            <button wire:click="deverrouiller({{ $rapprochement->id }})"
                                                    wire:confirm="Déverrouiller ce rapprochement ? Il repassera en statut 'En cours'."
                                                    class="btn btn-sm btn-outline-warning" title="Déverrouiller">
                                                <i class="bi bi-unlock"></i>
                                            </button>
                                        @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <x-per-page-selector :paginator="$rapprochements" storageKey="rapprochements" wire:model.live="perPage" />
                {{ $rapprochements->links() }}
            </div>
        @endif
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sélectionnez un compte bancaire pour afficher ses rapprochements.
        </div>
    @endif

    {{-- Modale pièce jointe --}}
    @if ($showPieceJointeModal)
        @php($currentRapp = $this->currentPieceJointeRapprochement)
        <div class="modal-backdrop fade show" style="z-index: 1050;"></div>
        <div class="modal fade show d-block" tabindex="-1" style="z-index: 1055;"
             wire:ignore.self wire:click.self="closePieceJointeModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-paperclip me-2"></i>
                            Pièce jointe du rapprochement
                        </h5>
                        <button type="button" class="btn-close"
                                wire:click="closePieceJointeModal"></button>
                    </div>
                    <div class="modal-body">
                        @if ($currentRapp && $currentRapp->hasPieceJointe())
                            <div class="mb-3 p-3 border rounded bg-light">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-file-earmark-text fs-4 text-success"></i>
                                    <div>
                                        <div class="fw-semibold">{{ $currentRapp->piece_jointe_nom }}</div>
                                        <div class="small text-muted">{{ $currentRapp->piece_jointe_mime }}</div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="{{ $currentRapp->pieceJointeUrl() }}" target="_blank"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Voir
                                    </a>
                                    <button wire:click="deletePieceJointe({{ $currentRapp->id }})"
                                            wire:confirm="Supprimer définitivement cette pièce jointe ?"
                                            class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Supprimer
                                    </button>
                                </div>
                            </div>
                            <hr>
                            <p class="fw-semibold mb-2">Remplacer par un nouveau fichier</p>
                        @else
                            <p class="text-muted mb-3">Aucun fichier attaché pour l'instant.</p>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">
                                Fichier (PDF, JPG ou PNG — 5 Mo max)
                            </label>
                            <input type="file" wire:model="pieceJointeUpload"
                                   accept=".pdf,image/jpeg,image/png"
                                   class="form-control @error('pieceJointeUpload') is-invalid @enderror">
                            @error('pieceJointeUpload')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div wire:loading wire:target="pieceJointeUpload" class="text-muted small mt-1">
                                Téléchargement…
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary"
                                wire:click="closePieceJointeModal">
                            Fermer
                        </button>
                        <button type="button" class="btn btn-primary"
                                wire:click="uploadPieceJointe"
                                wire:loading.attr="disabled"
                                @disabled(! $pieceJointeUpload)>
                            <span wire:loading.remove wire:target="uploadPieceJointe">
                                <i class="bi bi-upload"></i> Enregistrer
                            </span>
                            <span wire:loading wire:target="uploadPieceJointe">
                                <span class="spinner-border spinner-border-sm"></span> Enregistrement…
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
