<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Modèles de questionnaires</h1>
        <button class="btn btn-primary" wire:click="openCreate">+ Nouveau modèle</button>
    </div>

    <table class="table table-hover align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Titre interne</th>
                <th>Titre affiché</th>
                <th class="text-center">Questions</th>
                <th class="text-center">Actif</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($modeles as $m)
                <tr>
                    <td>{{ $m->titre_interne }}</td>
                    <td>{{ $m->titre_affiche }}</td>
                    <td class="text-center">{{ $m->questions_count }}</td>
                    <td class="text-center">
                        <button class="btn btn-sm {{ $m->actif ? 'btn-success' : 'btn-outline-secondary' }}"
                                wire:click="toggleActif({{ $m->id }})">
                            {{ $m->actif ? 'Actif' : 'Inactif' }}
                        </button>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('questionnaires.modeles.textes', $m) }}" class="btn btn-sm btn-outline-secondary">Textes</a>
                        <a href="{{ route('questionnaires.modeles.editor', $m) }}" class="btn btn-sm btn-outline-primary">Questions</a>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="openEdit({{ $m->id }})">Éditer</button>
                        <button class="btn btn-sm btn-outline-danger"
                                wire:click="supprimer({{ $m->id }})"
                                wire:confirm="Supprimer ce modèle ?">Supprimer</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-4">Aucun modèle.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingId ? 'Éditer' : 'Nouveau' }} modèle</h5>
                        <button type="button" class="btn-close" wire:click="$set('showModal', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Titre interne</label>
                            <input type="text" class="form-control" wire:model="titre_interne">
                            @error('titre_interne') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Titre affiché au répondant</label>
                            <input type="text" class="form-control" wire:model="titre_affiche">
                            @error('titre_affiche') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="$set('showModal', false)">Annuler</button>
                        <button class="btn btn-primary" wire:click="save">Enregistrer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
