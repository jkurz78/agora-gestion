<div>
    <h4 class="mb-3">Mes messages</h4>

    @if ($timeline->emails->isEmpty())
        <p class="text-muted">Vous n'avez pas encore reçu de message.</p>
    @endif

    <div class="list-group">
        @foreach ($timeline->emails as $email)
            @php
                $categorie = \App\Enums\CategorieEmail::tryFrom((string) $email->categorie);
                $badgeLabel = $categorie ? $categorie->labelPortail() : 'Autre';
                $badgeCss   = $categorie ? $categorie->cssBadgePortail() : 'bg-secondary';
            @endphp
            <div class="list-group-item">
                <button type="button"
                        wire:click="toggleMessage({{ $email->id }})"
                        class="btn btn-link text-decoration-none text-start p-0 d-flex align-items-center gap-3 w-100">
                    <span class="text-muted small text-nowrap" style="min-width:140px;">
                        {{ $email->dateEnvoi->format('d/m/Y H:i') }}
                    </span>
                    <span class="badge {{ $badgeCss }}">{{ $badgeLabel }}</span>
                    <strong>{{ $email->objet }}</strong>
                </button>

                @if ($messageOuvertId === (int) $email->id)
                    <div class="mt-3 portail-email-body p-3 bg-light rounded">
                        {!! $email->corpsHtml !!}
                    </div>

                    @if ($email->aPieceJointe && \Illuminate\Support\Facades\Route::has('portail.messages.attachment'))
                        <div class="mt-2">
                            @php
                                $pjLabel = 'Télécharger la pièce jointe';
                                if ($email->attachmentNom) {
                                    $pjLabel .= ' (' . $email->attachmentNom . ')';
                                }
                            @endphp
                            <a href="{{ \App\Support\PortailRoute::to('messages.attachment', $portailAssociation, ['emailLog' => $email->id]) }}"
                               target="_blank" rel="noopener"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-paperclip"></i>
                                {{ $pjLabel }}
                            </a>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if ($timeline->emails->hasPages())
        <div class="mt-3">
            {{ $timeline->emails->links() }}
        </div>
    @endif
</div>

<style>
    .portail-email-body { max-width: 100%; overflow-x: auto; }
    .portail-email-body img { max-width: 100%; height: auto; }
    .portail-email-body table { max-width: 100%; }
</style>
