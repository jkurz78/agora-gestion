<div class="container py-4">
    <h1 class="h3 mb-4">
        <i class="bi bi-envelope-heart"></i> Inscriptions newsletter
    </h1>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'inscriptions' ? 'active' : '' }}"
                    type="button"
                    wire:click="setTab('inscriptions')">
                Inscriptions à traiter
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'desinscriptions' ? 'active' : '' }}"
                    type="button"
                    wire:click="setTab('desinscriptions')">
                Désinscriptions à traiter
            </button>
        </li>
    </ul>

    <div class="tab-content">
        @if ($tab === 'inscriptions')
            @if ($this->inscriptionsRows->isEmpty())
                <div class="alert alert-secondary">Aucune inscription en attente.</div>
            @else
                <table class="table table-hover">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Date</th>
                            <th>Email</th>
                            <th>Prénom</th>
                            <th>Nom</th>
                            <th>Match</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->inscriptionsRows as $row)
                            @php($req = $row['request'])
                            @php($match = $row['match'])
                            <tr wire:key="ins-{{ $req->id }}">
                                <td data-sort="{{ optional($req->confirmed_at)->format('Y-m-d') }}">
                                    {{ optional($req->confirmed_at)->format('d/m/Y') }}
                                </td>
                                <td>{{ $req->email }}</td>
                                <td>{{ $req->prenom }}</td>
                                <td>{{ $req->nom }}</td>
                                <td>
                                    @if ($match)
                                        <span class="badge bg-warning text-dark">Match : {{ $match->displayName() }}</span>
                                    @else
                                        <span class="text-muted small">Aucun</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($match)
                                        <button type="button" class="btn btn-sm btn-warning"
                                                wire:click="openMergeModal({{ $req->id }}, {{ $match->id }})">
                                            Fusionner avec {{ $match->displayName() }}
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-success"
                                                wire:click="openCreateModal({{ $req->id }})">
                                            Créer le tiers
                                        </button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            wire:click="ignore({{ $req->id }})"
                                            wire:confirm="Ignorer cette inscription ? Elle ne deviendra pas un Tiers.">
                                        Ignorer
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @else
            @error('delete') <div class="alert alert-danger">{{ $message }}</div> @enderror
            @if ($this->desinscriptionsRows->isEmpty())
                <div class="alert alert-secondary">Aucune désinscription en attente.</div>
            @else
                <table class="table table-hover">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Date désinscription</th>
                            <th>Email</th>
                            <th>Tiers lié</th>
                            <th>Dépendances</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->desinscriptionsRows as $row)
                            @php($req = $row['request'])
                            @php($tiers = $row['tiers'])
                            @php($deps = $row['deps'])
                            <tr wire:key="des-{{ $req->id }}">
                                <td data-sort="{{ optional($req->unsubscribed_at)->format('Y-m-d') }}">
                                    {{ optional($req->unsubscribed_at)->format('d/m/Y') }}
                                </td>
                                <td>{{ $req->email }}</td>
                                <td>
                                    @if ($tiers)
                                        <a href="{{ route('tiers.transactions', $tiers->id) }}">{{ $tiers->displayName() }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($deps > 0)
                                        <span class="badge bg-secondary">{{ $deps }}</span>
                                    @else
                                        <span class="text-muted">aucune</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-warning"
                                            wire:click="applyOptout({{ $req->id }})"
                                            wire:confirm="Désabonner {{ $tiers?->displayName() }} ? Le Tiers ne recevra plus d'emails.">
                                        Désabonner
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            @if ($deps > 0) disabled title="{{ $deps }} dépendance(s) — suppression impossible" @endif
                                            wire:click="applyDelete({{ $req->id }})"
                                            wire:confirm="Supprimer le tiers {{ $tiers?->displayName() }} ?">
                                        Supprimer Tiers
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            wire:click="applyNoop({{ $req->id }})"
                                            wire:confirm="Acter cette désinscription sans modifier le Tiers ?">
                                        Acter sans action
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>

    <livewire:newsletter.create-tiers-modal />
    <livewire:tiers-merge-modal />
</div>
