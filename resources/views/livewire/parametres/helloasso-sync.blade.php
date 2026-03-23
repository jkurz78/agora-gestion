<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-arrow-repeat me-1"></i> Lancer la synchronisation</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label">Exercice</label>
                    <select wire:model="exercice" class="form-select form-select-sm">
                        @foreach($exercices as $ex)
                            <option value="{{ $ex }}">{{ $ex }}/{{ $ex + 1 }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button wire:click="synchroniser" class="btn btn-sm btn-success" wire:loading.attr="disabled">
                        <span wire:loading wire:target="synchroniser" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="bi bi-arrow-repeat me-1" wire:loading.remove wire:target="synchroniser"></i>
                        Synchroniser avec HelloAsso
                    </button>
                </div>
            </div>

            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif

            @if($result)
                <div class="alert {{ count($result['errors']) > 0 ? 'alert-warning' : 'alert-success' }}">
                    <strong><i class="bi bi-check-circle me-1"></i> Synchronisation terminée</strong>
                    <ul class="mb-0 mt-2">
                        <li>Transactions : <strong>{{ $result['transactionsCreated'] }} créée(s)</strong>, <strong>{{ $result['transactionsUpdated'] }} mise(s) à jour</strong></li>
                        <li>Lignes : <strong>{{ $result['lignesCreated'] }} créée(s)</strong>, <strong>{{ $result['lignesUpdated'] }} mise(s) à jour</strong></li>
                        @if($result['ordersSkipped'] > 0)
                            <li>Commandes ignorées : <strong>{{ $result['ordersSkipped'] }}</strong></li>
                        @endif
                    </ul>
                </div>

                @if(count($result['errors']) > 0)
                    <div class="alert alert-danger">
                        <strong><i class="bi bi-exclamation-triangle me-1"></i> {{ count($result['errors']) }} erreur(s) :</strong>
                        <ul class="mb-0 mt-1">
                            @foreach($result['errors'] as $error)
                                <li class="small">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
