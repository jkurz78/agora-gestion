<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">
            <i class="bi bi-file-earmark-check me-2"></i>Factures à comptabiliser
        </h1>
    </div>

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

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
            @switch($onglet)
                @case('a_traiter')
                    Aucune facture en attente de traitement.
                    @break
                @case('traitees')
                    Aucune facture comptabilisée.
                    @break
                @case('rejetees')
                    Aucune facture rejetée.
                    @break
                @default
                    Aucun dépôt enregistré.
            @endswitch
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
                            $estSoumise = $statut === \App\Enums\StatutFactureDeposee::Soumise;
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
                                <a href="{{ route('back-office.factures-partenaires.pdf', ['depot' => $depot]) }}"
                                   class="btn btn-outline-secondary btn-sm"
                                   target="_blank">
                                    <i class="bi bi-file-pdf me-1"></i>Voir PDF
                                </a>
                                @if ($estSoumise)
                                    <button class="btn btn-outline-success btn-sm"
                                            wire:click="comptabiliser({{ $depot->id }})"
                                            title="Comptabiliser ce dépôt">
                                        <i class="bi bi-check-circle me-1"></i>Comptabiliser
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm"
                                            wire:click="ouvrirRejet({{ $depot->id }})"
                                            title="Rejeter ce dépôt">
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

    {{-- Modale de rejet --}}
    <div class="modal fade @if($showRejectModal) show d-block @endif" tabindex="-1"
         style="@if($showRejectModal) display:block; @endif">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rejeter le dépôt</h5>
                    <button type="button" class="btn-close" wire:click="fermerRejet"></button>
                </div>
                <div class="modal-body">
                    <label for="motifRejet" class="form-label">Motif (obligatoire)</label>
                    <textarea id="motifRejet"
                              class="form-control @error('motifRejet') is-invalid @enderror"
                              wire:model="motifRejet"
                              rows="4"
                              maxlength="1000"></textarea>
                    @error('motifRejet')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="fermerRejet">Annuler</button>
                    <button type="button" class="btn btn-danger" wire:click="confirmerRejet">Confirmer le rejet</button>
                </div>
            </div>
        </div>
    </div>
    @if($showRejectModal)
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
