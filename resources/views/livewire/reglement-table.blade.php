<div>
    @if($participants->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-people" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucun participant inscrit à cette opération.</p>
        </div>
    @elseif($seances->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-calendar-week" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucune séance définie pour cette opération.</p>
        </div>
    @else
        {{-- Grid placeholder — built in Task 4 --}}
        <p>{{ $participants->count() }} participants × {{ $seances->count() }} séances</p>
        @foreach($participants as $participant)
            @foreach($seances as $seance)
                @php $realise = $realiseMap[$participant->id . '-' . $seance->id] ?? null; @endphp
                @if($realise !== null)
                    <span>{{ number_format($realise, 2, ',', ' ') }}</span>
                @endif
            @endforeach
        @endforeach
    @endif
</div>
