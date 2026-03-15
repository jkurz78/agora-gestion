<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Opérations</h1>
        <a href="{{ route('operations.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Ajouter une opération
        </a>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nom</th>
                        <th>Date début</th>
                        <th>Date fin</th>
                        <th>Séances</th>
                        <th>Statut</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($operations as $operation)
                        <tr>
                            <td>{{ $operation->nom }}</td>
                            <td>{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $operation->nombre_seances ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ $operation->statut->label() }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('operations.show', $operation) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="{{ route('operations.edit', $operation) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">Aucune opération enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
