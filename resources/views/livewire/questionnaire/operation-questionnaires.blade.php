<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Questionnaire</h2>
        <div class="d-flex gap-2">
            @if ($campagnes->count() >= 2)
                <a href="{{ route('questionnaires.resultats.consolides') }}"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-diagram-3 me-1"></i>Consolider
                </a>
            @endif
            <button class="btn btn-primary btn-sm" wire:click="$set('showCreate', true)">+ Nouvelle campagne</button>
        </div>
    </div>

    @if (session('scan_ok'))
        <div class="alert alert-success py-2 small">{{ session('scan_ok') }}</div>
    @endif

    <table class="table table-hover align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Titre</th>
                <th class="text-center">Statut</th>
                <th class="text-center">Participants</th>
                <th class="text-center">Réponses / Taux</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($campagnes as $c)
                <tr>
                    <td>
                        <a href="{{ route('questionnaires.campagnes.show', $c) }}" class="fw-semibold text-decoration-none">
                            {{ $c->titre_affiche ?: $c->titre }}
                        </a>
                        <span class="text-muted small ms-1">({{ $c->created_at->format('d/m/Y') }})</span>
                    </td>
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
                    <td class="text-center">
                        {{ $c->invitations_count }}
                    </td>
                    <td class="text-center">
                        {{ $c->soumises_count }}
                        @if ($c->invitations_count > 0)
                            <span class="text-muted small">({{ round($c->soumises_count / $c->invitations_count * 100) }}%)</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center py-4">Aucune campagne.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ════════════════════════════════════════════════════════════════
         Modale Nouvelle campagne
    ════════════════════════════════════════════════════════════════ --}}
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
