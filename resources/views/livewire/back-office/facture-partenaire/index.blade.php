<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">
            <i class="bi bi-file-earmark-check me-2"></i>Factures à comptabiliser
        </h1>
    </div>

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
            <button class="nav-link {{ $onglet === 'traitees' ? 'active' : '' }}"
                    wire:click="$set('onglet', 'traitees')"
                    type="button" role="tab">
                Traitées
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

    @if ($depots->isEmpty())
        <div class="alert alert-info">
            Aucun dépôt dans cet onglet.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date facture</th>
                        <th>Tiers</th>
                        <th>N° facture</th>
                        <th>Déposée le</th>
                        <th>Taille PDF</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($depots as $depot)
                        @php
                            $statut = $depot->statut;
                        @endphp
                        <tr>
                            <td data-sort="{{ $depot->date_facture?->format('Y-m-d') }}">
                                {{ $depot->date_facture?->format('d/m/Y') }}
                            </td>
                            <td>
                                {{ $depot->tiers?->prenom }} {{ $depot->tiers?->nom }}
                            </td>
                            <td>{{ $depot->numero_facture }}</td>
                            <td data-sort="{{ $depot->created_at?->format('Y-m-d H:i:s') }}">
                                {{ $depot->created_at?->format('d/m/Y') }}
                            </td>
                            <td>
                                @if ($depot->pdf_taille !== null)
                                    {{ number_format($depot->pdf_taille / 1024, 0, ',', ' ') }} Ko
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @switch($statut->value)
                                    @case('soumise')
                                        <span class="badge bg-warning text-dark">{{ $statut->label() }}</span>
                                        @break
                                    @case('rejetee')
                                        <span class="badge bg-danger">{{ $statut->label() }}</span>
                                        @break
                                    @case('traitee')
                                        <span class="badge bg-success">{{ $statut->label() }}</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                @endswitch
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ URL::signedRoute('back-office.factures-partenaires.pdf', ['depot' => $depot]) }}"
                                   class="btn btn-outline-secondary btn-sm"
                                   target="_blank">
                                    <i class="bi bi-file-pdf me-1"></i>Voir PDF
                                </a>
                                @if ($statut === \App\Enums\StatutFactureDeposee::Soumise)
                                    <button class="btn btn-outline-success btn-sm" disabled title="Fonctionnalité à venir">
                                        <i class="bi bi-check-circle me-1"></i>Comptabiliser
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" disabled title="Fonctionnalité à venir">
                                        <i class="bi bi-x-circle me-1"></i>Rejeter
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
