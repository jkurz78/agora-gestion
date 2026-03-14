<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Membres</h1>
        <a href="{{ route('membres.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Ajouter un membre
        </a>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Email</th>
                        <th>Statut</th>
                        <th>Cotisation {{ $exerciceLabel }}</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($membres as $membre)
                        <tr>
                            <td>{{ $membre->nom }}</td>
                            <td>{{ $membre->prenom }}</td>
                            <td>{{ $membre->email ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $membre->statut_membre === 'actif' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ ucfirst($membre->statut_membre ?? '—') }}
                                </span>
                            </td>
                            <td>
                                @if ($membre->cotisations->isNotEmpty())
                                    <span class="text-success fw-bold">&check;</span>
                                @else
                                    <span class="text-danger fw-bold">&cross;</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('membres.show', $membre) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="{{ route('membres.edit', $membre) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('membres.destroy', $membre) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer ce membre ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">Aucun membre enregistré.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
