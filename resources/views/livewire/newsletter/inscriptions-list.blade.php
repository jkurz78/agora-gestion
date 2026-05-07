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
            <div class="text-muted">Liste des désinscriptions à traiter (à venir).</div>
        @endif
    </div>
</div>
