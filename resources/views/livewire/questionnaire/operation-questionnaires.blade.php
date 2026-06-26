<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Questionnaire</h2>
        <button class="btn btn-primary btn-sm" wire:click="$set('showCreate', true)">+ Nouvelle campagne</button>
    </div>

    <table class="table table-hover align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Titre</th>
                <th class="text-center">Statut</th>
                <th class="text-center">Invitations</th>
                <th class="text-center">Soumises / Taux</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($campagnes as $c)
                <tr>
                    <td>{{ $c->titre_affiche ?: $c->titre }}</td>
                    <td class="text-center">
                        @php
                            $badgeClass = match ($c->statut) {
                                \App\Enums\StatutCampagne::Brouillon  => 'bg-secondary',
                                \App\Enums\StatutCampagne::Ouverte    => 'bg-success',
                                \App\Enums\StatutCampagne::Cloturee   => 'bg-warning text-dark',
                                \App\Enums\StatutCampagne::Archivee   => 'bg-dark',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ $c->statut->label() }}</span>
                    </td>
                    <td class="text-center">{{ $c->invitations_count }}</td>
                    <td class="text-center">
                        {{ $c->soumises_count }}
                        @if ($c->invitations_count > 0)
                            <span class="text-muted small">({{ round($c->soumises_count / $c->invitations_count * 100) }}%)</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('questionnaires.campagnes.apercu', $c) }}"
                           target="_blank"
                           class="btn btn-sm btn-outline-secondary me-1">
                            Prévisualiser
                        </a>
                        <a href="{{ route('questionnaires.campagnes.resultats', $c) }}"
                           class="btn btn-sm btn-outline-info me-1">
                            Résultats
                        </a>
                        @if ($c->statut->peutOuvrir())
                            <button class="btn btn-sm btn-outline-success"
                                    wire:click="ouvrir({{ $c->id }})"
                                    wire:confirm="Lancer cette campagne ? Les participants pourront répondre.">
                                Lancer
                            </button>
                        @endif
                        @if ($c->statut === \App\Enums\StatutCampagne::Ouverte)
                            <a href="{{ route('questionnaires.campagnes.envoi', $c) }}"
                               class="btn btn-sm btn-outline-primary">
                                Envoyer les invitations
                            </a>
                            <button class="btn btn-sm btn-outline-secondary"
                                    wire:click="toggleImpression({{ $c->id }})">
                                Imprimer (papier)
                            </button>
                        @endif
                        @if ($c->statut->peutCloturer())
                            <button class="btn btn-sm btn-outline-warning"
                                    wire:click="cloturer({{ $c->id }})"
                                    wire:confirm="Clôturer cette campagne ? Les réponses ne seront plus acceptées.">
                                Clôturer
                            </button>
                        @endif
                    </td>
                </tr>
                @if ($c->statut === \App\Enums\StatutCampagne::Ouverte && $impressionCampagneId === $c->id)
                    <tr>
                        <td colspan="5" class="p-3 bg-light">
                            @livewire('questionnaire.impression-papier', ['campagne' => $c], key('impression-'.$c->id))
                        </td>
                    </tr>
                @endif
                @foreach ($c->invitations->where('statut', \App\Enums\StatutInvitation::Soumis) as $inv)
                    <tr class="table-light small">
                        <td colspan="3" class="ps-4 text-muted">
                            {{ $inv->participant?->tiers?->displayName() ?? '—' }}
                            <span class="badge bg-success ms-1">Soumis</span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary"
                                    wire:click="rouvrirInvitation({{ $inv->id }})"
                                    wire:confirm="Rouvrir cette réponse ?">
                                Rouvrir
                            </button>
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="5" class="text-muted text-center py-4">Aucune campagne.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($showCreate)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle campagne</h5>
                        <button type="button" class="btn-close" wire:click="$set('showCreate', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Modèle de questionnaire</label>
                            <select class="form-select" wire:model="selectedTemplateId">
                                <option value="">— Choisir un modèle —</option>
                                @foreach ($modeles as $m)
                                    <option value="{{ $m->id }}">{{ $m->titre_interne }}</option>
                                @endforeach
                            </select>
                            @error('selectedTemplateId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        @if ($participants->isNotEmpty())
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Participants à inviter</label>
                                <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                                    @foreach ($participants as $p)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="part-{{ $p->id }}"
                                                   wire:model="selectedParticipants"
                                                   value="{{ $p->id }}">
                                            <label class="form-check-label" for="part-{{ $p->id }}">
                                                {{ $p->tiers?->displayName() ?? '—' }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="$set('showCreate', false)">Annuler</button>
                        <button class="btn btn-primary" wire:click="creerCampagne">Créer la campagne</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
