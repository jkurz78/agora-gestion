<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-receipt me-1"></i> Vos notes de frais</h2>
        <a href="{{ route('portail.ndf.create', ['association' => $association->slug]) }}"
           class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle note de frais
        </a>
    </div>

    @if (session('portail.success'))
        <div class="alert alert-success">{{ session('portail.success') }}</div>
    @endif

    {{-- Onglets Actives / Archivées / Toutes --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $onglet === 'actives' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'actives')">
                Actives
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $onglet === 'archivees' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'archivees')">
                Archivées
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $onglet === 'toutes' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'toutes')">
                Toutes
            </button>
        </li>
    </ul>

    @if ($notes->isEmpty())
        <div class="alert alert-info">
            Aucune note de frais pour le moment.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th class="text-end">Total</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notes as $note)
                        @php
                            $total = $note->lignes->sum('montant');
                            $statut = $note->statut;
                            $archived = $note->isArchived();
                        @endphp
                        <tr>
                            <td data-sort="{{ $note->date?->format('Y-m-d') }}">
                                {{ $note->date?->format('d/m/Y') }}
                            </td>
                            <td>
                                {{ $note->libelle }}
                                @if ($archived)
                                    <span class="badge bg-secondary ms-1">Archivée</span>
                                @endif
                            </td>
                            <td class="text-end" data-sort="{{ number_format($total, 2, '.', '') }}">
                                {{ number_format((float) $total, 2, ',', ' ') }} €
                            </td>
                            <td>
                                @switch($statut->value)
                                    @case('brouillon')
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                        @break
                                    @case('soumise')
                                        <span class="badge bg-primary">{{ $statut->label() }}</span>
                                        @break
                                    @case('rejetee')
                                        <span class="badge bg-danger">{{ $statut->label() }}</span>
                                        @break
                                    @case('validee')
                                        <span class="badge bg-success">{{ $statut->label() }}</span>
                                        @break
                                    @case('payee')
                                        <span class="badge bg-success text-dark">{{ $statut->label() }}</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                @endswitch
                            </td>
                            <td class="text-end">
                                @if ($archived)
                                    {{-- NDF archivée : lecture seule --}}
                                    <a href="{{ route('portail.ndf.show', ['association' => $association->slug, 'noteDeFrais' => $note->id]) }}"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye me-1"></i>Consulter
                                    </a>
                                @elseif (in_array($statut->value, ['brouillon', 'soumise', 'rejetee']))
                                    <a href="{{ route('portail.ndf.edit', ['association' => $association->slug, 'noteDeFrais' => $note->id]) }}"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil me-1"></i>Modifier
                                    </a>
                                @else
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="{{ route('portail.ndf.show', ['association' => $association->slug, 'noteDeFrais' => $note->id]) }}"
                                           class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-eye me-1"></i>Consulter
                                        </a>
                                        @if (in_array($statut->value, ['payee', 'rejetee']))
                                            <button type="button"
                                                    class="btn btn-outline-secondary btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalArchiver-{{ $note->id }}">
                                                <i class="bi bi-archive me-1"></i>Archiver
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>

                        {{-- Modale de confirmation d'archivage --}}
                        @if (! $archived && in_array($statut->value, ['payee', 'rejetee']))
                            <div class="modal fade" id="modalArchiver-{{ $note->id }}" tabindex="-1"
                                 aria-labelledby="modalArchiverLabel-{{ $note->id }}" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="modalArchiverLabel-{{ $note->id }}">
                                                Confirmer l'archivage
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Fermer"></button>
                                        </div>
                                        <div class="modal-body">
                                            Archiver cette note de frais la masquera de la liste des notes actives.
                                            Cette action est irréversible.
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary"
                                                    data-bs-dismiss="modal">Annuler</button>
                                            <button type="button"
                                                    wire:click="archiveNdf({{ $note->id }})"
                                                    data-bs-dismiss="modal"
                                                    class="btn btn-warning">
                                                <i class="bi bi-archive me-1"></i>Archiver
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-3 text-end">
        <a href="{{ route('portail.home', ['association' => $association->slug]) }}"
           class="btn btn-link btn-sm text-muted">
            <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
        </a>
    </div>
</div>
