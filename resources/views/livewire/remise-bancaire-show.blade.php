<div>
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
        <h1 class="mb-0">{{ $remise->libelle }}</h1>
        <a href="{{ route('compta.banques.remises.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
    </div>

    {{-- Carte informations --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-2">
                    <span class="text-muted small">N°</span><br>
                    <strong>{{ $remise->numero }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Date</span><br>
                    <strong>{{ $remise->date->format('d/m/Y') }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Type</span><br>
                    <strong>{{ $remise->mode_paiement->label() }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Banque cible</span><br>
                    <strong>{{ $remise->compteCible->nom }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Montant total</span><br>
                    <strong>{{ number_format((float) $totalMontant, 2, ',', ' ') }} €</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Statut</span><br>
                    @if ($remise->virement_id === null)
                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil"></i> Brouillon</span>
                    @elseif ($verrouille)
                        <span class="badge bg-secondary"><i class="bi bi-lock"></i> Verrouillée</span>
                    @else
                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Comptabilisée</span>
                    @endif
                </div>
            </div>
            @if ($remise->virement)
                <div class="mt-2 small text-muted">
                    <i class="bi bi-arrow-left-right"></i>
                    Virement interne : <strong>{{ $remise->virement->reference }}</strong>
                    — {{ number_format((float) $remise->virement->montant, 2, ',', ' ') }} €
                </div>
            @endif
        </div>
    </div>

    {{-- Tableau des transactions --}}
    @if ($transactions->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucune transaction comptable. Cette remise est en brouillon.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>N°</th>
                        <th>Référence</th>
                        <th>N° pièce</th>
                        <th>Participant</th>
                        <th>Opération</th>
                        <th>Séance</th>
                        <th class="text-end">Montant</th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($transactions as $index => $transaction)
                        <tr>
                            <td class="small">{{ $index + 1 }}</td>
                            <td class="small fw-semibold">{{ $transaction->reference }}</td>
                            <td class="small">{{ $transaction->numero_piece ?? '—' }}</td>
                            <td class="small">{{ $transaction->tiers?->displayName() ?? '—' }}</td>
                            <td class="small">{{ $transaction->lignes->first()?->operation?->nom ?? '—' }}</td>
                            <td class="small">
                                @if ($transaction->lignes->first()?->seance)
                                    S{{ $transaction->lignes->first()->seance }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $transaction->montant_total, 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="6" class="text-end">Total</td>
                        <td class="text-end text-nowrap">{{ number_format((float) $totalMontant, 2, ',', ' ') }} €</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Actions --}}
    <div class="d-flex gap-2 mt-3">
        @if ($remise->virement_id !== null)
            <a href="{{ route('compta.banques.remises.pdf', $remise) }}?mode=inline"
               class="btn btn-outline-dark" target="_blank">
                <i class="bi bi-file-pdf"></i> PDF
            </a>
        @endif
        @if (! $verrouille && $this->canEdit)
            <a href="{{ route('compta.banques.remises.selection', $remise) }}"
               class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <button wire:click="supprimer"
                    wire:confirm="Supprimer cette remise ? Les transactions et le virement associés seront supprimés."
                    class="btn btn-outline-danger">
                <i class="bi bi-trash"></i> Supprimer
            </button>
        @endif
        <a href="{{ route('compta.banques.remises.index') }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    </div>
</div>
