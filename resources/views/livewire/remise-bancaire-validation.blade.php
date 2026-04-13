<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <h1 class="mb-0">Validation de la remise</h1>
        <a href="{{ route('banques.remises.selection', $remise) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Retour à la sélection
        </a>
    </div>

    {{-- Récapitulatif --}}
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title">Récapitulatif</h6>
            <div class="row">
                <div class="col-md-3">
                    <span class="text-muted small">Date</span><br>
                    <strong>{{ $remise->date->format('d/m/Y') }}</strong>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small">Banque cible</span><br>
                    <strong>{{ $remise->compteCible->nom }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Type</span><br>
                    <strong>{{ $remise->mode_paiement->label() }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Nb transactions</span><br>
                    <strong>{{ $countTotal }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Montant total</span><br>
                    <strong>{{ number_format((float) $totalMontant, 2, ',', "\u{00A0}") }}&nbsp;€</strong>
                </div>
            </div>
        </div>
    </div>

    @if ($countTotal === 0)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> Aucun élément sélectionné.
        </div>
    @endif

    {{-- Tableau des transactions --}}
    @if ($transactions->isNotEmpty())
        <h6>Transactions</h6>
        <table class="table table-sm table-striped align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th>Compte</th>
                    <th class="text-end">Montant</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @foreach($transactions as $tx)
                <tr>
                    <td class="small">{{ $loop->iteration }}</td>
                    <td class="small">{{ $tx->date->format('d/m/Y') }}</td>
                    <td class="small">{{ $tx->libelle }}</td>
                    <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                    <td class="small">{{ $tx->compte->nom }}</td>
                    <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $tx->montant_total, 2, ',', "\u{00A0}") }}&nbsp;€</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="5" class="text-end">Total</td>
                    <td class="text-end text-nowrap">{{ number_format((float) $transactions->sum('montant_total'), 2, ',', "\u{00A0}") }}&nbsp;€</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- Actions --}}
    <div class="d-flex gap-2 mt-3">
        @if ($this->canEdit)
            <button data-bs-toggle="modal" data-bs-target="#modalComptabiliser"
                    class="btn btn-success"
                    @disabled($countTotal === 0)>
                <i class="bi bi-check-circle"></i>
                Comptabiliser
            </button>
        @endif
    </div>

    {{-- Modal confirmation comptabilisation --}}
    <div class="modal fade" id="modalComptabiliser" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <p>Comptabiliser cette remise ?<br>
                    <small class="text-muted">Les transactions seront passées au statut « Reçu ».</small></p>
                    <button class="btn btn-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                    <button wire:click="comptabiliser" data-bs-dismiss="modal" class="btn btn-success">Comptabiliser</button>
                </div>
            </div>
        </div>
    </div>
</div>
