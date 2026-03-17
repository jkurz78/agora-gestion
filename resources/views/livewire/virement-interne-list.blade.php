<div>
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($virements->isEmpty())
        <p class="text-muted">Aucun virement enregistré pour cet exercice.</p>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
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
                            <td>
                                <div class="d-flex gap-1 justify-content-end">
                                    <button wire:click="$dispatch('edit-virement', { id: {{ $virement->id }} })"
                                            class="btn btn-sm btn-outline-primary" title="Modifier"
                                            style="padding:.15rem .35rem;font-size:.75rem">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null)
                                        <button class="btn btn-sm btn-outline-danger" disabled
                                                title="Dépointez ce virement avant de le supprimer."
                                                style="padding:.15rem .35rem;font-size:.75rem">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    @else
                                        <button wire:click="delete({{ $virement->id }})"
                                                wire:confirm="Supprimer ce virement ?"
                                                class="btn btn-sm btn-outline-danger" title="Supprimer"
                                                style="padding:.15rem .35rem;font-size:.75rem">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <x-per-page-selector :paginator="$virements" storageKey="virements" wire:model.live="perPage" />
            {{ $virements->links() }}
        </div>
    @endif
</div>
