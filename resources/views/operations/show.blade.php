<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>{{ $operation->nom }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('compta.operations.edit', $operation) }}" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <a href="{{ route('compta.operations.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Détails de l'opération</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Nom</dt>
                        <dd class="col-sm-8">{{ $operation->nom }}</dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $operation->description ?? '—' }}</dd>

                        <dt class="col-sm-4">Date début</dt>
                        <dd class="col-sm-8">{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</dd>

                        <dt class="col-sm-4">Date fin</dt>
                        <dd class="col-sm-8">{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</dd>

                        <dt class="col-sm-4">Séances</dt>
                        <dd class="col-sm-8">{{ $operation->nombre_seances ?? '—' }}</dd>

                        <dt class="col-sm-4">Statut</dt>
                        <dd class="col-sm-8">
                            <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-primary' : 'bg-secondary' }}">
                                {{ $operation->statut->label() }}
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Bilan financier</h5>
                </div>
                <div class="card-body">
                    <table class="table mb-0">
                        <tbody>
                            <tr>
                                <td>Total dépenses</td>
                                <td class="text-end text-danger fw-bold">
                                    {{ number_format((float) $totalDepenses, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                            <tr>
                                <td>Total recettes</td>
                                <td class="text-end text-success fw-bold">
                                    {{ number_format((float) $totalRecettes, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                            <tr>
                                <td>Total dons</td>
                                <td class="text-end text-success fw-bold">
                                    {{ number_format((float) $totalDons, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                            <tr class="table-active">
                                <td class="fw-bold">Solde</td>
                                <td class="text-end fw-bold {{ $solde >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ number_format((float) $solde, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Participants --}}
    <livewire:participant-list :operation="$operation" />
</x-app-layout>
