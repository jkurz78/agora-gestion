<div>
    @php
        $badgeClass = match ($campagne->statut) {
            \App\Enums\StatutCampagne::Brouillon  => 'bg-secondary',
            \App\Enums\StatutCampagne::Ouverte    => 'bg-success',
            \App\Enums\StatutCampagne::Cloturee   => 'bg-warning text-dark',
            \App\Enums\StatutCampagne::Archivee   => 'bg-dark',
        };
    @endphp

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1">
                {{ $campagne->titre_affiche ?: $campagne->titre }}
                <span class="badge {{ $badgeClass }} ms-2 align-middle">{{ $campagne->statut->label() }}</span>
            </h1>
            <div class="text-muted small">
                Créée le {{ $campagne->created_at->format('d/m/Y') }}
                @if ($campagne->template !== null)
                    — modèle « {{ $campagne->template->titre_interne }} »
                @endif
                — opération {{ $campagne->operation->nom }}
            </div>
        </div>
        <div class="d-flex gap-2">
            @if ($campagne->statut->peutOuvrir())
                <button class="btn btn-sm btn-outline-success"
                        wire:click="ouvrir"
                        wire:confirm="Lancer cette campagne ? Les participants pourront répondre.">
                    <i class="bi bi-play-fill me-1"></i>Lancer
                </button>
            @endif
            @if ($campagne->statut->peutCloturer())
                <button class="btn btn-sm btn-outline-warning"
                        wire:click="cloturer"
                        wire:confirm="Clôturer cette campagne ? Les réponses ne seront plus acceptées.">
                    <i class="bi bi-lock me-1"></i>Clôturer
                </button>
            @endif
        </div>
    </div>

    @if (session('scan_ok'))
        <div class="alert alert-success py-2 small">{{ session('scan_ok') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'suivi' ? 'active' : '' }}" wire:click="setTab('suivi')">
                <i class="bi bi-people me-1"></i>Suivi
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'diffusion' ? 'active' : '' }}" wire:click="setTab('diffusion')">
                <i class="bi bi-envelope me-1"></i>Diffusion
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'scans' ? 'active' : '' }}" wire:click="setTab('scans')">
                <i class="bi bi-qr-code-scan me-1"></i>Scans
                @if ($scansATraiter > 0)
                    <span class="badge bg-primary ms-1">{{ $scansATraiter }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $tab === 'resultats' ? 'active' : '' }}" wire:click="setTab('resultats')">
                <i class="bi bi-bar-chart me-1"></i>Résultats
            </button>
        </li>
    </ul>

    @if ($tab === 'suivi')
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Invités</div>
                    <div class="h4 mb-0">{{ $campagne->invitations_count }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Réponses</div>
                    <div class="h4 mb-0">{{ $campagne->soumises_count }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small">Taux de réponse</div>
                    <div class="h4 mb-0">
                        @if ($campagne->invitations_count > 0)
                            {{ round($campagne->soumises_count / $campagne->invitations_count * 100) }} %
                        @else
                            —
                        @endif
                    </div>
                </div></div>
            </div>
        </div>

        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="ps-3">Participant</th>
                    <th class="text-center">Statut</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invitations as $inv)
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
                            @if ($inv->statut !== \App\Enums\StatutInvitation::Soumis && $campagne->statut === \App\Enums\StatutCampagne::Ouverte)
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
                            @if ($inv->statut === \App\Enums\StatutInvitation::Soumis && !$campagne->anonymise)
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="rouvrirInvitation({{ $inv->id }})"
                                        wire:confirm="Rouvrir cette réponse ?">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rouvrir
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if ($scanPourInvitationId === (int) $inv->id)
                        <tr class="table-light">
                            <td colspan="3" class="ps-4 pe-3 py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <x-zone-depot>
                                        <input type="file"
                                               wire:model="scanFichier"
                                               accept=".png,.jpg,.jpeg,.pdf"
                                               class="form-control form-control-sm" style="max-width:300px">
                                    </x-zone-depot>
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
                @empty
                    <tr><td colspan="3" class="text-muted text-center py-4">Aucune invitation.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($tab === 'diffusion')
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-envelope me-1"></i>Invitations par email</h2>
                    <p class="text-muted small mb-2">Composer et envoyer les invitations ou les relances.</p>
                    <a href="{{ route('questionnaires.campagnes.envoi', $campagne) }}" class="btn btn-sm btn-primary">
                        Envoyer les invitations
                    </a>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-printer me-1"></i>Papier</h2>
                    <p class="text-muted small mb-2">Imprimer le questionnaire à remplir à la main (QR de réponse en ligne inclus).</p>
                    @if ($campagne->anonymise)
                        <a href="{{ route('questionnaires.campagnes.pdf-anonyme', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            Imprimer (anonyme)
                        </a>
                    @else
                        <a href="{{ route('questionnaires.campagnes.pdf', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            PDF papier
                        </a>
                    @endif
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-eye me-1"></i>Aperçu</h2>
                    <p class="text-muted small mb-2">Voir le questionnaire comme un répondant, sans rien enregistrer.</p>
                    <a href="{{ route('questionnaires.campagnes.apercu', $campagne) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                        Aperçu répondant
                    </a>
                </div></div>
            </div>
        </div>
    @endif

    @if ($tab === 'scans')
        <livewire:questionnaire.scan-upload :campagne="$campagne" :key="'scans-'.$campagne->id" />
    @endif

    @if ($tab === 'resultats')
        <livewire:questionnaire.campagne-resultats :campagne="$campagne" :key="'resultats-'.$campagne->id" />
    @endif
</div>
