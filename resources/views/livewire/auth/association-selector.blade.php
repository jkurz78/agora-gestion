<div class="container py-5">
    <h1 class="h3 mb-4">Mes associations</h1>
    <p class="text-muted">Sélectionnez l'association sur laquelle vous souhaitez travailler.</p>

    <div class="row g-3">
        @forelse ($associations as $asso)
            <div class="col-md-6 col-lg-4">
                <button wire:click="select({{ $asso->id }})" class="card w-100 h-100 text-start border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center gap-3">
                        @if ($asso->brandingLogoFullPath() && \Illuminate\Support\Facades\Storage::disk('local')->exists($asso->brandingLogoFullPath()))
                            <img src="{{ \App\Support\TenantAsset::url($asso->brandingLogoFullPath()) }}" alt="Logo {{ $asso->nom }}" style="width:48px;height:48px;object-fit:contain">
                        @else
                            <div style="width:48px;height:48px;background:#dee2e6;border-radius:4px"></div>
                        @endif
                        <div>
                            <div class="fw-semibold">{{ $asso->nom }}</div>
                            <small class="text-muted">{{ ucfirst($asso->pivot->role ?? 'consultation') }}</small>
                        </div>
                    </div>
                </button>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-warning">
                    Votre compte n'est rattaché à aucune association. Contactez un administrateur.
                </div>
            </div>
        @endforelse
    </div>
</div>
