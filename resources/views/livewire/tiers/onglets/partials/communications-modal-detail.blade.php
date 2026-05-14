<div class="modal show d-block"
     tabindex="-1"
     style="background-color: rgba(0,0,0,0.5);"
     wire:key="email-detail-{{ $email->id }}"
     wire:click.self="closeDetail">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex flex-column">
                    <h5 class="modal-title mb-1">
                        {{ $email->objet_rendu ?: $email->objet }}
                    </h5>
                    <div class="d-flex gap-2">
                        <span class="badge text-bg-secondary">{{ $email->categorie }}</span>
                        @if($email->statut === 'envoye')
                            <span class="badge text-bg-success">Envoyé</span>
                        @else
                            <span class="badge text-bg-danger">Erreur</span>
                        @endif
                    </div>
                </div>
                <button type="button" class="btn-close" wire:click="closeDetail"></button>
            </div>
            <div class="modal-body">
                <dl class="row small">
                    <dt class="col-sm-3">Envoyé le</dt>
                    <dd class="col-sm-9">{{ $email->created_at->format('d/m/Y H:i') }}</dd>

                    <dt class="col-sm-3">Destinataire</dt>
                    <dd class="col-sm-9">
                        {{ $email->destinataire_nom ?: '—' }} &lt;{{ $email->destinataire_email }}&gt;
                    </dd>

                    @if($email->envoyePar)
                        <dt class="col-sm-3">Envoyé par</dt>
                        <dd class="col-sm-9">{{ $email->envoyePar->name }}</dd>
                    @endif

                    @if($email->emailTemplate)
                        <dt class="col-sm-3">Modèle</dt>
                        <dd class="col-sm-9">{{ $email->emailTemplate->nom }}</dd>
                    @endif

                    @if($email->campagne)
                        <dt class="col-sm-3">Campagne</dt>
                        <dd class="col-sm-9">{{ $email->campagne->nom }}</dd>
                    @endif

                    @if($email->operation)
                        <dt class="col-sm-3">Opération</dt>
                        <dd class="col-sm-9">
                            <a href="{{ route('operations.show', $email->operation) }}">
                                {{ $email->operation->nom }}
                            </a>
                        </dd>
                    @endif

                    @if($email->participant)
                        <dt class="col-sm-3">Participant</dt>
                        <dd class="col-sm-9">
                            {{ $email->participant->prenom }} {{ $email->participant->nom }}
                        </dd>
                    @endif
                </dl>

                @if($email->statut === 'erreur' && $email->erreur_message)
                    <div class="alert alert-danger small mb-3">
                        <strong>Erreur :</strong>
                        <pre class="mb-0">{{ $email->erreur_message }}</pre>
                    </div>
                @endif

                <h6>Contenu</h6>
                @if($email->corps_html)
                    <iframe sandbox=""
                            srcdoc="{{ e(\App\Helpers\EmailLogo::previewSwap($email->corps_html)) }}"
                            style="width: 100%; height: 50vh; border: 1px solid var(--bs-border-color); border-radius: 4px;"></iframe>
                @else
                    <p class="text-muted">Pas de corps HTML enregistré.</p>
                @endif

                @if($email->attachment_path)
                    <h6 class="mt-3">Pièce jointe</h6>
                    <p class="mb-0">
                        <i class="bi bi-paperclip"></i>
                        {{ basename($email->attachment_path) }}
                        {{-- Lien de téléchargement à brancher selon la route storage --}}
                    </p>
                @endif

                @if($email->opens->isNotEmpty())
                    <h6 class="mt-3">Ouvertures ({{ $email->opens->count() }})</h6>
                    <ul class="small mb-0">
                        @foreach($email->opens->sortBy('opened_at') as $open)
                            <li>
                                {{ \Carbon\Carbon::parse($open->opened_at)->format('d/m/Y H:i') }}
                                — {{ $open->ip ?? '?' }}
                                @if($open->user_agent)
                                    <span class="text-muted">— {{ \Illuminate\Support\Str::limit($open->user_agent, 60) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" wire:click="closeDetail">Fermer</button>
            </div>
        </div>
    </div>
</div>
