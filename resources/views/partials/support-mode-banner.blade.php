@if (session('support_mode'))
    @php
        $supportAssoId = session('support_association_id');
        $supportAsso = $supportAssoId ? \App\Models\Association::find($supportAssoId) : null;
    @endphp
    <div class="alert alert-danger rounded-0 mb-0 d-flex justify-content-between align-items-center" style="position: sticky; top: 0; z-index: 1080;">
        <div>
            <strong>Mode support actif</strong> — vous consultez <strong>{{ $supportAsso?->nom ?? 'une association' }}</strong> en lecture seule. Toute écriture est bloquée.
        </div>
        <form method="POST" action="{{ route('super-admin.support.exit') }}" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-light">Quitter le mode support</button>
        </form>
    </div>
@endif
