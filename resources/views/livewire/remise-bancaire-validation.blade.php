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
        <a href="{{ route('compta.banques.remises.selection', $remise) }}" class="btn btn-outline-secondary">
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
                    <span class="text-muted small">Nb éléments</span><br>
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

    {{-- Tableau des règlements séances --}}
    @if ($reglements->isNotEmpty())
        <h6>Règlements séances</h6>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>N°</th>
                        <th>Participant</th>
                        <th>Opération</th>
                        <th>Séance</th>
                        <th class="text-end">Montant</th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($reglements as $index => $reglement)
                        <tr>
                            <td class="small">{{ $index + 1 }}</td>
                            <td class="small">{{ $reglement->participant->tiers->displayName() }}</td>
                            <td class="small">{{ $reglement->seance->operation->nom }}</td>
                            <td class="small">S{{ $reglement->seance->numero }}</td>
                            <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $reglement->montant_prevu, 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="4" class="text-end">Sous-total</td>
                        <td class="text-end text-nowrap">{{ number_format((float) $reglements->sum('montant_prevu'), 2, ',', "\u{00A0}") }}&nbsp;€</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Tableau des transactions hors séances --}}
    @if ($transactionsDirectes->isNotEmpty())
        <h6 class="{{ $reglements->isNotEmpty() ? 'mt-3' : '' }}">Transactions (hors séances)</h6>
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
                @foreach($transactionsDirectes as $tx)
                <tr>
                    <td class="small">{{ $loop->iteration }}</td>
                    <td class="small">{{ $tx->date->format('d/m/Y') }}</td>
                    <td class="small">{{ $tx->libelle }}</td>
                    <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                    <td class="small">{{ $tx->compte->nom }}</td>
                    <td class="text-end small fw-semibold text-nowrap">{{ number_format($tx->montant_total, 2, ',', "\u{00A0}") }}&nbsp;€</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="5" class="text-end">Sous-total</td>
                    <td class="text-end text-nowrap">{{ number_format((float) $transactionsDirectes->sum('montant_total'), 2, ',', "\u{00A0}") }}&nbsp;€</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- Actions --}}
    <div class="d-flex gap-2 mt-3">
        @if ($this->canEdit)
            <button wire:click="comptabiliser"
                    wire:confirm="Comptabiliser cette remise ? Les transactions comptables et le virement interne seront créés."
                    class="btn btn-success"
                    @disabled($countTotal === 0)>
                <i class="bi bi-check-circle"></i>
                {{ $remise->virement_id !== null ? 'Modifier la remise' : 'Comptabiliser' }}
            </button>
        @endif
    </div>
</div>
