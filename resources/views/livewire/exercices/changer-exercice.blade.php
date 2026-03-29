<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @php
        $exerciceActuel = $exercices->firstWhere('annee', $exerciceActif);
    @endphp

    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle me-1"></i>
        Exercice actuellement affiché :
        <strong>{{ $exerciceActuel ? $exerciceActuel->label() : $exerciceActif . '-' . ($exerciceActif + 1) }}</strong>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Exercice</th>
                    <th>Période</th>
                    <th>Statut</th>
                    <th>Date clôture</th>
                    <th>Clôturé par</th>
                    <th>URL HelloAsso</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($exercices as $ex)
                    <tr>
                        <td><strong>{{ $ex->label() }}</strong></td>
                        <td>{{ $ex->dateDebut()->format('d/m/Y') }} → {{ $ex->dateFin()->format('d/m/Y') }}</td>
                        <td>
                            <span class="badge {{ $ex->statut->badge() }}">{{ $ex->statut->label() }}</span>
                        </td>
                        <td>
                            {{ $ex->date_cloture ? $ex->date_cloture->format('d/m/Y') : '—' }}
                        </td>
                        <td>
                            {{ $ex->cloturePar ? $ex->cloturePar->name : '—' }}
                        </td>
                        <td>
                            @if ($ex->helloasso_url)
                                <a href="{{ $ex->helloasso_url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-truncate d-inline-block" style="max-width:200px"
                                   title="{{ $ex->helloasso_url }}">
                                    {{ $ex->helloasso_url }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary me-1"
                                    wire:click="ouvrirEdition({{ $ex->id }})"
                                    title="Modifier l'URL HelloAsso">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @if ($ex->annee === $exerciceActif)
                                <span class="badge bg-primary">Affiché</span>
                            @elseif ($ex->isCloture())
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="changer({{ $ex->annee }})"
                                        wire:confirm="Cet exercice est clôturé. Les données seront en lecture seule. Continuer ?">
                                    Afficher
                                </button>
                            @else
                                <button class="btn btn-sm btn-outline-primary"
                                        wire:click="changer({{ $ex->annee }})">
                                    Afficher
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">Aucun exercice trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-end mt-3">
        <button class="btn btn-success" wire:click="$set('showCreateModal', true)">
            <i class="bi bi-plus-circle me-1"></i> Créer un exercice
        </button>
    </div>

    @if ($showEditModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">URL HelloAsso</h5>
                        <button type="button" class="btn-close" wire:click="$set('showEditModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editHelloassoUrl" class="form-label">URL du formulaire HelloAsso</label>
                            <input type="url"
                                   id="editHelloassoUrl"
                                   class="form-control @error('editHelloassoUrl') is-invalid @enderror"
                                   wire:model="editHelloassoUrl"
                                   placeholder="https://www.helloasso.com/…">
                            @error('editHelloassoUrl')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Laisser vide pour supprimer l'URL associée à cet exercice.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('showEditModal', false)">
                            Annuler
                        </button>
                        <button type="button" class="btn btn-primary" wire:click="sauvegarderUrl">
                            <i class="bi bi-check-lg me-1"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showCreateModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Créer un exercice</h5>
                        <button type="button" class="btn-close" wire:click="$set('showCreateModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nouvelleAnnee" class="form-label">Année de début</label>
                            <input type="number"
                                   id="nouvelleAnnee"
                                   class="form-control @error('nouvelleAnnee') is-invalid @enderror"
                                   wire:model="nouvelleAnnee"
                                   min="2000"
                                   max="2099"
                                   placeholder="ex: 2026">
                            @error('nouvelleAnnee')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                L'exercice s'étend du 1er septembre de l'année saisie au 31 août de l'année suivante.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="$set('showCreateModal', false)">
                            Annuler
                        </button>
                        <button type="button" class="btn btn-success" wire:click="creer">
                            <i class="bi bi-plus-circle me-1"></i> Créer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
