<div>
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 class="mb-1">{{ $rapprochement->compte->nom }}</h4>
            <span class="text-muted">Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</span>
            @if ($rapprochement->isVerrouille())
                <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Verrouillé</span>
            @else
                <span class="badge bg-warning text-dark ms-2"><i class="bi bi-pencil"></i> En cours</span>
            @endif
        </div>
        <a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    </div>

    {{-- Bandeau de soldes --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde ouverture</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde fin (relevé)</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde pointé</div>
                    <div class="fw-bold">{{ number_format($soldePointage, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-{{ $ecart == 0 ? 'success' : 'danger' }}">
                <div class="card-body py-2">
                    <div class="text-muted small">Écart</div>
                    <div class="fw-bold {{ $ecart == 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($ecart, 2, ',', ' ') }} €
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    @if ($rapprochement->isEnCours())
        <div class="d-flex gap-2 mb-4">
            <a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-floppy"></i> Enregistrer et quitter
            </a>
            @if ($ecart == 0)
                <button wire:click="verrouiller"
                        wire:confirm="Verrouiller ce rapprochement ? Cette action est irréversible. Les champs Date, Montant et Compte bancaire des écritures pointées ne pourront plus être modifiés."
                        class="btn btn-danger">
                    <i class="bi bi-lock"></i> Verrouiller
                </button>
            @else
                <button class="btn btn-danger" disabled
                        title="L'écart doit être nul pour verrouiller.">
                    <i class="bi bi-lock"></i> Verrouiller (écart non nul)
                </button>
            @endif
        </div>
    @endif

    {{-- Table des transactions --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Réf.</th>
                    <th class="text-end">Débit</th>
                    <th class="text-end">Crédit</th>
                    <th class="text-center">Pointé</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $tx)
                    <tr class="{{ $tx['pointe'] ? 'table-success' : '' }}">
                        <td class="text-nowrap">{{ $tx['date']->format('d/m/Y') }}</td>
                        <td>
                            @switch($tx['type'])
                                @case('depense') <span class="badge bg-danger">Dépense</span> @break
                                @case('recette') <span class="badge bg-success">Recette</span> @break
                                @case('don') <span class="badge bg-info text-dark">Don</span> @break
                                @case('cotisation') <span class="badge bg-warning text-dark">Cotisation</span> @break
                                @case('virement_source') <span class="badge bg-secondary">Virement ↑</span> @break
                                @case('virement_destination') <span class="badge bg-secondary">Virement ↓</span> @break
                            @endswitch
                        </td>
                        <td>{{ $tx['label'] }}</td>
                        <td class="text-muted small">{{ $tx['reference'] ?? '—' }}</td>
                        <td class="text-end text-danger">
                            @if ($tx['montant_signe'] < 0)
                                {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-end text-success">
                            @if ($tx['montant_signe'] > 0)
                                {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($rapprochement->isEnCours())
                                <input type="checkbox"
                                       wire:click="toggle('{{ $tx['type'] }}', {{ $tx['id'] }})"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       class="form-check-input">
                            @else
                                <input type="checkbox"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       disabled
                                       class="form-check-input">
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            Aucune transaction disponible pour ce compte.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
