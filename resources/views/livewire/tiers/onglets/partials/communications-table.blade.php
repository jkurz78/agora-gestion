<div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Date</th>
                <th>Catégorie</th>
                <th>Objet</th>
                <th>Destinataire</th>
                <th>Statut</th>
                <th class="text-center">Ouvertures</th>
                <th class="text-center">PJ</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $email)
                <tr>
                    <td data-sort="{{ $email->dateEnvoi->toIso8601String() }}">
                        {{ $email->dateEnvoi->format('d/m/Y H:i') }}
                    </td>
                    <td>
                        <span class="badge text-bg-secondary">{{ $email->categorie }}</span>
                    </td>
                    <td title="{{ $email->objet }}">
                        <span class="text-truncate d-inline-block" style="max-width: 280px;">
                            {{ $email->objet }}
                        </span>
                    </td>
                    <td>{{ $email->destinataire }}</td>
                    <td>
                        @if($email->statut === 'envoye')
                            <span class="badge text-bg-success">Envoyé</span>
                        @else
                            <span class="badge text-bg-danger"
                                  data-bs-toggle="popover"
                                  data-bs-trigger="hover"
                                  data-bs-content="{{ $email->erreurMessage }}">
                                Erreur
                            </span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($email->nbOuvertures > 0)
                            <i class="bi bi-eye"
                               title="1ère ouverture {{ $email->premiereOuvertureAt?->format('d/m/Y H:i') }}"></i>
                            {{ $email->nbOuvertures }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($email->aPieceJointe)
                            <i class="bi bi-paperclip" title="{{ $email->attachmentNom }}"></i>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                wire:click="openDetail({{ $email->id }})"
                                title="Voir le détail">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
