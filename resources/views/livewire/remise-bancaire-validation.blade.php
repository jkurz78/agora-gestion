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
        <a href="{{ route('gestion.remises-bancaires.selection', $remise) }}" class="btn btn-outline-secondary">
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
                    <span class="text-muted small">Nb règlements</span><br>
                    <strong>{{ $reglements->count() }}</strong>
                </div>
                <div class="col-md-2">
                    <span class="text-muted small">Montant total</span><br>
                    <strong>{{ number_format((float) $totalMontant, 2, ',', ' ') }} €</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau des règlements --}}
    @if ($reglements->isEmpty())
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> Aucun règlement sélectionné.
        </div>
    @else
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
                        <td colspan="4" class="text-end">Total</td>
                        <td class="text-end text-nowrap">{{ number_format((float) $totalMontant, 2, ',', ' ') }} €</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Actions --}}
    <div class="d-flex gap-2 mt-3">
        @if ($this->canEdit)
            <button wire:click="comptabiliser"
                    wire:confirm="Comptabiliser cette remise ? Les transactions comptables et le virement interne seront créés."
                    class="btn btn-success"
                    @disabled($reglements->isEmpty())>
                <i class="bi bi-check-circle"></i>
                {{ $remise->virement_id !== null ? 'Modifier la remise' : 'Comptabiliser' }}
            </button>
        @endif
        <a href="{{ route('gestion.remises-bancaires.selection', $remise) }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    </div>
</div>
