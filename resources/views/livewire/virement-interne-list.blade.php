<div>
    @if ($virements->isEmpty())
        <p class="text-muted">Aucun virement enregistré pour cet exercice.</p>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Référence</th>
                        <th>Compte source</th>
                        <th>Compte destination</th>
                        <th class="text-end">Montant</th>
                        <th>Notes</th>
                        <th>Saisi par</th>
                        <th style="width: 100px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($virements as $virement)
                        <tr wire:key="virement-{{ $virement->id }}">
                            <td>{{ $virement->date->format('d/m/Y') }}</td>
                            <td>{{ $virement->reference ?? '—' }}</td>
                            <td>{{ $virement->compteSource->nom }}</td>
                            <td>{{ $virement->compteDestination->nom }}</td>
                            <td class="text-end">{{ number_format((float) $virement->montant, 2, ',', ' ') }} €</td>
                            <td>{{ $virement->notes ?? '—' }}</td>
                            <td>{{ $virement->saisiPar->nom }}</td>
                            <td class="text-center">
                                <button wire:click="$dispatch('edit-virement', { id: {{ $virement->id }} })"
                                        class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Modifier
                                </button>
                                <button wire:click="delete({{ $virement->id }})"
                                        wire:confirm="Supprimer ce virement ?"
                                        class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $virements->links() }}
        </div>
    @endif
</div>
