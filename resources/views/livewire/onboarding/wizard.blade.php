<div class="row">
    <div class="col-md-3">
        <div class="list-group mb-4">
            @foreach (['Identité', 'Exercice', 'Compte bancaire', 'SMTP', 'HelloAsso', 'IMAP', 'Plan comptable', 'Type d\'opération', 'Récapitulatif'] as $i => $label)
                @php $n = $i + 1; @endphp
                <button wire:click="goToStep({{ $n }})"
                        @class([
                            'list-group-item list-group-item-action text-start',
                            'active' => $n === $currentStep,
                            'text-muted' => $n > $currentStep,
                            'disabled' => $n > $currentStep,
                        ])
                        @disabled($n > $currentStep)
                        type="button">
                    <strong>{{ $n }}.</strong> {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="col-md-9">
        <div class="card">
            <div class="card-body">
                <h3>Étape {{ $currentStep }} sur {{ \App\Livewire\Onboarding\Wizard::TOTAL_STEPS }}</h3>
                <p class="text-muted">Contenu de l'étape à compléter par les tasks suivantes.</p>
            </div>
        </div>
    </div>
</div>
