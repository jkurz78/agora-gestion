@extends('formulaire.layout')

@section('content')
<div x-data="formulaireWizard()" class="mb-4">
    {{-- Progress bar --}}
    <div class="progress mb-4">
        <div class="progress-bar" role="progressbar" :aria-valuenow="step" aria-valuemin="1" aria-valuemax="7"
             :style="'width: ' + Math.round((step / 7) * 100) + '%'">
            Étape <span x-text="step"></span> / 7
        </div>
    </div>

    {{-- Server-side validation errors --}}
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('formulaire.store') }}" enctype="multipart/form-data" @submit.prevent="submitForm($event)">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @include('formulaire.steps.step-1')
        @include('formulaire.steps.step-2')
        @include('formulaire.steps.step-3')
        @include('formulaire.steps.step-4')
        @include('formulaire.steps.step-5')
        @include('formulaire.steps.step-6')
        @include('formulaire.steps.step-7')

        {{-- Navigation buttons --}}
        <div class="d-flex justify-content-between mt-4">
            <button type="button" class="btn btn-outline-secondary" x-show="step > 1" x-cloak @click="prevStep()">
                <i class="bi bi-arrow-left"></i> Précédent
            </button>
            <div x-show="step === 1"></div>
            <button type="button" class="btn btn-primary" x-show="step < 7" @click="nextStep()">
                Suivant <i class="bi bi-arrow-right"></i>
            </button>
            <button type="submit" class="btn btn-success" x-show="step === 7" x-cloak>
                <i class="bi bi-check-lg"></i> Valider et envoyer
            </button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
function formulaireWizard() {
    return {
        step: 1,
        skipSteps: @json($tarif && (float) $tarif->montant > 0 ? [] : [5]),
        errors: {},

        nextStep() {
            if (this.validateStep(this.step)) {
                let next = this.step + 1;
                while (this.skipSteps.includes(next) && next < 7) next++;
                this.step = next;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        prevStep() {
            let prev = this.step - 1;
            while (this.skipSteps.includes(prev) && prev > 1) prev--;
            this.step = prev;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        validateStep(n) {
            this.errors = {};
            const section = document.querySelector(`[data-step="${n}"]`);
            if (!section) return true;

            let valid = true;

            // Check required text/select/date inputs
            const requiredInputs = section.querySelectorAll('input[data-required]:not([type="checkbox"]):not([type="radio"]), select[data-required], textarea[data-required]');
            requiredInputs.forEach(field => {
                const name = field.getAttribute('name');
                if (!field.value || field.value.trim() === '') {
                    this.errors[name] = 'Ce champ est obligatoire';
                    valid = false;
                }
            });

            // Check required checkboxes
            const requiredCheckboxes = section.querySelectorAll('input[type="checkbox"][data-required]');
            requiredCheckboxes.forEach(field => {
                const name = field.getAttribute('name');
                if (!field.checked) {
                    this.errors[name] = 'Cet engagement est obligatoire';
                    valid = false;
                }
            });

            // Check required radio groups
            const checkedGroups = new Set();
            const requiredRadios = section.querySelectorAll('input[type="radio"][data-required-radio]');
            requiredRadios.forEach(field => {
                const group = field.getAttribute('data-required-radio');
                if (!checkedGroups.has(group)) {
                    const anyChecked = section.querySelector(`input[name="${group}"]:checked`);
                    if (!anyChecked) {
                        this.errors[group] = 'Veuillez faire un choix';
                        valid = false;
                    }
                    checkedGroups.add(group);
                }
            });

            // Email format validation
            const emails = section.querySelectorAll('input[type="email"]');
            emails.forEach(field => {
                if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                    this.errors[field.name] = "Format d'email invalide";
                    valid = false;
                }
            });

            return valid;
        },

        hasError(name) {
            return this.errors[name] !== undefined;
        },

        submitForm(event) {
            if (this.validateStep(this.step)) {
                event.target.submit();
            }
        }
    };
}
</script>
@endsection
