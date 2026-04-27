<div style="font-size:.85rem;">
    {{-- Onglets --}}
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $onglet === 'a_traiter' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'a_traiter')"
                    type="button" role="tab">
                À traiter
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $onglet === 'validees' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'validees')"
                    type="button" role="tab">
                Validées
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $onglet === 'rejetees' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'rejetees')"
                    type="button" role="tab">
                Rejetées
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $onglet === 'toutes' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'toutes')"
                    type="button" role="tab">
                Toutes
            </button>
        </li>
    </ul>

    @if ($notes->isEmpty())
        <div class="alert alert-info">
            Aucune note de frais dans cet onglet.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Tiers</th>
                        <th>Libellé</th>
                        <th class="text-end">Montant</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notes as $note)
                        @php
                            $total = $note->lignes->sum('montant');
                            $statut = $note->statut;
                        @endphp
                        <tr style="cursor:pointer"
                            onclick="window.location='{{ route('comptabilite.ndf.show', $note) }}'">
                            <td data-sort="{{ $note->date?->format('Y-m-d') }}">
                                {{ $note->date?->format('d/m/Y') }}
                            </td>
                            <td>
                                {{ $note->tiers?->prenom }} {{ $note->tiers?->nom }}
                            </td>
                            <td>{{ $note->libelle }}</td>
                            <td class="text-end" data-sort="{{ number_format((float) $total, 2, '.', '') }}">
                                {{ number_format((float) $total, 2, ',', ' ') }} €
                            </td>
                            <td>
                                @switch($statut->value)
                                    @case('soumise')
                                        <span class="badge bg-warning text-dark">{{ $statut->label() }}</span>
                                        @break
                                    @case('rejetee')
                                        <span class="badge bg-danger">{{ $statut->label() }}</span>
                                        @break
                                    @case('validee')
                                        <span class="badge bg-success">{{ $statut->label() }}</span>
                                        @break
                                    @case('payee')
                                        <span class="badge bg-info text-dark">{{ $statut->label() }}</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                @endswitch
                            </td>
                            <td class="text-end">
                                <a href="{{ route('comptabilite.ndf.show', $note) }}"
                                   class="btn btn-outline-primary btn-sm"
                                   onclick="event.stopPropagation()">
                                    <i class="bi bi-eye me-1"></i>Traiter
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
