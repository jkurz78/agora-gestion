<div>
    @include('questionnaire.partials.modele-nav', ['template' => $template, 'active' => 'questions'])

    <table class="table align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th style="width:60px">#</th>
                <th>Question</th>
                <th>Type</th>
                <th class="text-center">Obligatoire</th>
                <th class="text-center" title="Grouper avec la précédente (affichage sur le même écran)">Grouper</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($questions as $q)
                @php $premier = $loop->first; @endphp
                <tr>
                    <td>{{ $q->ordre }}</td>
                    <td>
                        @if ($q->grouper_avec_precedente)
                            <span class="text-muted me-1">↳</span>
                        @endif
                        <span @class(['ms-3' => $q->grouper_avec_precedente])>{{ $q->libelle }}</span>
                        @if($q->aDesOptions()) <span class="text-muted small">({{ count($q->options()) }} options)</span>@endif
                    </td>
                    <td>{{ $q->type->label() }}</td>
                    <td class="text-center">{{ $q->obligatoire ? 'Oui' : 'Non' }}</td>
                    <td class="text-center">
                        @if (! $premier)
                            <input type="checkbox"
                                   class="form-check-input"
                                   @checked($q->grouper_avec_precedente)
                                   wire:click="toggleGroupe({{ $q->id }})"
                                   title="Grouper avec la question précédente">
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" wire:click="monter({{ $q->id }})">↑</button>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="descendre({{ $q->id }})">↓</button>
                        <button class="btn btn-sm btn-outline-danger" wire:click="supprimerQuestion({{ $q->id }})" wire:confirm="Supprimer cette question ?">×</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-3">Aucune question.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="card">
        <div class="card-body">
            <h2 class="h6">Ajouter une question</h2>
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" class="form-control" placeholder="Titre" wire:model="libelle">
                    @error('libelle') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <select class="form-select" wire:model.live="type">
                        @foreach ($types as $t)
                            <option value="{{ $t['value'] }}">{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($type !== 'information')
                    <div class="col-md-2 form-check d-flex align-items-center ms-2">
                        <input type="checkbox" class="form-check-input me-1" wire:model="obligatoire" id="obl">
                        <label class="form-check-label" for="obl">Obligatoire</label>
                    </div>
                @endif
                <div class="col-md-12">
                    <input type="text" class="form-control"
                           placeholder="{{ $type === 'information' ? 'Texte (optionnel)' : 'Aide (optionnelle)' }}"
                           wire:model="aide">
                </div>
                @if ($type === 'choix_unique')
                    <div class="col-md-12">
                        <label class="form-label small text-muted">Options (une par ligne)</label>
                        <textarea class="form-control" rows="3" wire:model="optionsBrut"></textarea>
                    </div>
                @endif
                @if ($type === 'satisfaction')
                    <div class="col-md-12 d-flex align-items-center gap-3 flex-wrap">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" wire:model.live="commentaire" id="commentaire_toggle">
                            <label class="form-check-label" for="commentaire_toggle">Commentaire optionnel</label>
                        </div>
                        @if ($commentaire)
                            <input type="text" class="form-control flex-grow-1"
                                   placeholder="Un commentaire ? (optionnel)"
                                   wire:model="commentaireLibelle">
                        @endif
                    </div>
                @endif
                @if ($type === 'satisfaction_texte_long')
                    <div class="col-md-12 d-flex align-items-center gap-3 flex-wrap">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" wire:model="texteObligatoire" id="texte_obligatoire_toggle">
                            <label class="form-check-label" for="texte_obligatoire_toggle">Texte long obligatoire</label>
                        </div>
                        <small class="text-muted">Une zone de texte long s'affiche toujours après les smileys.</small>
                    </div>
                @endif
                @if ($type === 'ressenti')
                    <div class="col-md-6">
                        <input type="text" class="form-control"
                               placeholder="😡 / texte gauche (optionnel)"
                               wire:model="labelGauche">
                    </div>
                    <div class="col-md-6">
                        <input type="text" class="form-control"
                               placeholder="😄 / texte droite (optionnel)"
                               wire:model="labelDroite">
                    </div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" wire:click="ajouterQuestion">Ajouter</button>
                </div>
            </div>
        </div>
    </div>
</div>
