<div>
    <div>
        <button wire:click="togglePanel" type="button" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload"></i> Importer {{ $type === 'depense' ? 'dépenses' : 'recettes' }}
        </button>
    </div>

    @if ($showPanel)
        <div class="position-fixed top-0 start-0 w-100 h-100"
             style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto"
             wire:click.self="togglePanel">
            <div class="container py-4">
                <div class="card mb-4" style="max-width:600px;margin:auto">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Importer des {{ $type === 'depense' ? 'dépenses' : 'recettes' }} — CSV</span>
                        <button wire:click="togglePanel" type="button" class="btn-close"></button>
                    </div>
                    <div class="card-body">
                        @if ($successMessage)
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> {{ $successMessage }}
                            </div>
                        @endif

                        @if ($importErrors)
                            <div class="alert alert-danger mb-3">
                                <strong><i class="bi bi-x-circle-fill"></i> {{ count($importErrors) }} erreur(s) détectée(s) — aucune donnée importée</strong>
                                <table class="table table-sm mt-2 mb-0">
                                    <thead><tr><th>Ligne</th><th>Erreur</th></tr></thead>
                                    <tbody>
                                        @foreach ($importErrors as $error)
                                            <tr>
                                                <td>{{ $error['line'] }}</td>
                                                <td>{{ $error['message'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <form wire:submit="import">
                            <div class="mb-3">
                                <input type="file" wire:model="csvFile"
                                       class="form-control @error('csvFile') is-invalid @enderror"
                                       accept=".csv">
                                @error('csvFile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-upload"></i> Lancer l'import
                                </button>
                                <a href="{{ route('transactions.import.template', ['type' => $type]) }}"
                                   class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-download"></i> Télécharger le modèle
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
