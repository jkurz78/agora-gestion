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
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($campagnes as $c)
                <tr>
                    <td>
                        {{ $c->titre_affiche ?: $c->titre }}
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
                        <button class="btn btn-sm btn-link text-decoration-none p-0"
                                wire:click="ouvrirParticipants({{ $c->id }})"
                                title="Voir les participants">
                            {{ $c->invitations_count }} <i class="bi bi-people ms-1"></i>
                        </button>
                    </td>
                    <td class="text-center">
                        {{ $c->soumises_count }}
                        @if ($c->invitations_count > 0)
                            <span class="text-muted small">({{ round($c->soumises_count / $c->invitations_count * 100) }}%)</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('questionnaires.campagnes.resultats', $c) }}"
                           class="btn btn-sm btn-outline-info me-1">
                            <i class="bi bi-bar-chart me-1"></i>Résultats
                        </a>

                        @if ($c->statut->peutOuvrir())
                            <button class="btn btn-sm btn-outline-success"
                                    wire:click="ouvrir({{ $c->id }})"
                                    wire:confirm="Lancer cette campagne ? Les participants pourront répondre.">
                                <i class="bi bi-play-fill me-1"></i>Lancer
                            </button>
                        @endif

                        @if ($c->statut === \App\Enums\StatutCampagne::Ouverte)
                            <a href="{{ route('questionnaires.campagnes.envoi', $c) }}"
                               class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-envelope me-1"></i>Invitations
                            </a>
                        @endif

                        @if ($c->statut->peutCloturer())
                            <button class="btn btn-sm btn-outline-warning me-1"
                                    wire:click="cloturer({{ $c->id }})"
                                    wire:confirm="Clôturer cette campagne ? Les réponses ne seront plus acceptées.">
                                <i class="bi bi-lock me-1"></i>Clôturer
                            </button>
                        @endif

                        @if ($c->statut === \App\Enums\StatutCampagne::Ouverte)
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    Plus
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="{{ route('questionnaires.campagnes.pdf', $c) }}" target="_blank">
                                            <i class="bi bi-file-earmark-pdf me-2"></i>PDF papier
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="{{ route('questionnaires.campagnes.scans', $c) }}">
                                            <i class="bi bi-qr-code-scan me-2"></i>Scans reçus
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-4">Aucune campagne.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ════════════════════════════════════════════════════════════════
         Modale Participants
    ════════════════════════════════════════════════════════════════ --}}
    @if ($campagneModale !== null)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
             wire:click.self="fermerParticipants">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-people me-2"></i>
                            {{ $campagneModale->titre_affiche ?: $campagneModale->titre }}
                            <span class="text-muted small ms-2">({{ $campagneModale->created_at->format('d/m/Y') }})</span>
                        </h5>
                        <button type="button" class="btn-close" wire:click="fermerParticipants"></button>
                    </div>
                    <div class="modal-body p-0">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Participant</th>
                                    <th class="text-center">Statut</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campagneModale->invitations->sortBy(fn ($i) => $i->participant?->tiers?->nom) as $inv)
                                    @php
                                        $statutBadge = match ($inv->statut) {
                                            \App\Enums\StatutInvitation::Soumis    => ['bg-success', 'Soumis'],
                                            \App\Enums\StatutInvitation::Commence  => ['bg-info', 'En cours'],
                                            \App\Enums\StatutInvitation::NonOuvert => ['bg-secondary', 'Non ouvert'],
                                            default                                => ['bg-secondary', $inv->statut->value],
                                        };
                                    @endphp
                                    <tr>
                                        <td class="ps-3">{{ $inv->participant?->tiers?->displayName() ?? '—' }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $statutBadge[0] }}">{{ $statutBadge[1] }}</span>
                                        </td>
                                        <td class="text-end pe-3">
                                            @if ($inv->statut !== \App\Enums\StatutInvitation::Soumis && $campagneModale->statut === \App\Enums\StatutCampagne::Ouverte)
                                                <a href="{{ $inv->lienReponse() . (str_contains($inv->lienReponse(), '?') ? '&' : '?') . 'saisie_pour=1' }}"
                                                   target="_blank"
                                                   class="btn btn-sm btn-outline-primary me-1"
                                                   title="Remplir le formulaire en ligne">
                                                    <i class="bi bi-pencil-square me-1"></i>Saisir
                                                </a>
                                                <button class="btn btn-sm btn-outline-dark"
                                                        wire:click="ouvrirScanPour({{ $inv->id }})"
                                                        title="Importer un scan pour ce participant">
                                                    <i class="bi bi-camera me-1"></i>Scanner
                                                </button>
                                            @endif
                                            @if ($inv->statut === \App\Enums\StatutInvitation::Soumis)
                                                <button class="btn btn-sm btn-outline-secondary"
                                                        wire:click="rouvrirInvitation({{ $inv->id }})"
                                                        wire:confirm="Rouvrir cette réponse ?">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rouvrir
                                                </button>
                                            @endif
                                        </td>
                                    </tr>

                                    {{-- Zone upload inline pour le scan ciblé --}}
                                    @if ($scanPourInvitationId === (int) $inv->id)
                                        <tr class="table-light">
                                            <td colspan="3" class="ps-4 pe-3 py-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <input type="file"
                                                           wire:model="scanFichier"
                                                           accept=".png,.jpg,.jpeg,.pdf"
                                                           class="form-control form-control-sm" style="max-width:300px">
                                                    <button class="btn btn-sm btn-primary"
                                                            wire:click="importerScanPour"
                                                            @if(!$scanFichier) disabled @endif>
                                                        <i class="bi bi-upload me-1"></i>Importer
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary"
                                                            wire:click="$set('scanPourInvitationId', null)">
                                                        Annuler
                                                    </button>
                                                    <div wire:loading wire:target="scanFichier" class="spinner-border spinner-border-sm text-primary"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
