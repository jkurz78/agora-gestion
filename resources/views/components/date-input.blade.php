{{-- Champ date en jj/mm/aaaa (flatpickr fr). Le champ date natif du navigateur suit la langue de
     l'interface du navigateur, pas celle de la page (MM/DD/YYYY sous un Firefox en anglais) :
     le back-office passe par ce composant (cf. tests/Feature/ChampsDateTest.php). --}}
@props([
    'id'       => null,
    'name'     => '',
    'value'    => '',
    'disabled' => false,
    'size'     => null,
])

@php
    // Propriété Livewire liée (wire:model, wire:model.live…), ou null hors de Livewire.
    $proprieteLiee = $attributes->whereStartsWith('wire:model')->first();
    $petit = $size === 'sm';
@endphp

@if ($disabled)
    <input type="text"
           value="{{ $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '' }}"
           class="form-control{{ $petit ? ' form-control-sm' : '' }} bg-light" disabled>
@else
    <div class="input-group{{ $petit ? ' input-group-sm' : '' }}" wire:ignore
         @if($id) id="{{ $id }}" @endif
         x-data="{
             fp: null,
             propriete: @js($proprieteLiee),
             init() {
                 const hidden = this.$refs.hidden;
                 this.fp = flatpickr(this.$refs.input, {
                     locale: 'fr',
                     dateFormat: 'd/m/Y',
                     allowInput: true,
                     disableMobile: true,
                     defaultDate: hidden.value || null,
                     parseDate(str) { return window.parseFlatpickrDate(str); },
                     onChange(dates) {
                         if (!dates.length) return;
                         const d = dates[0];
                         const iso = d.getFullYear() + '-'
                             + String(d.getMonth()+1).padStart(2,'0') + '-'
                             + String(d.getDate()).padStart(2,'0');
                         hidden.value = iso;
                         hidden.dispatchEvent(new Event('input'));
                         hidden.dispatchEvent(new Event('change'));
                     },
                 });
                 // wire:ignore protège flatpickr des re-rendus, mais lui masque aussi les dates
                 // posées par le serveur (ouverture de modale, bouton « Aujourd'hui », remise
                 // à zéro) : on suit donc la propriété liée.
                 if (this.propriete) {
                     this.$wire.$watch(this.propriete, (valeur) => this.synchroniser(valeur));
                 }
             },
             synchroniser(valeur) {
                 if (!this.fp) return;
                 const iso = typeof valeur === 'string' ? valeur.slice(0, 10) : '';
                 const affichee = this.fp.selectedDates.length
                     ? this.fp.formatDate(this.fp.selectedDates[0], 'Y-m-d')
                     : '';
                 if (iso === affichee) return;
                 this.$refs.hidden.value = iso;
                 // Sans déclencher onChange : la valeur vient du serveur, inutile de la lui renvoyer.
                 if (iso === '') { this.fp.clear(false); } else { this.fp.setDate(iso, false); }
             },
             setDate(iso) {
                 if (this.fp && iso) { this.fp.setDate(iso, true); }
             },
             destroy() { if (this.fp) this.fp.destroy(); }
         }"
         @set-date.window="if ($event.detail?.name === '{{ $name }}') setDate($event.detail.value)">
        <input type="text"
               x-ref="input"
               class="form-control"
               placeholder="jj/mm/aaaa"
               autocomplete="off"
               {{ $attributes->except(['name', 'value', 'disabled', 'id'])->filter(fn($v, $k) => !str_starts_with($k, 'wire:')) }}>
        <span class="input-group-text" style="cursor:pointer"
              @click="fp && fp.toggle()">
            <i class="bi bi-calendar3"></i>
        </span>
        <input type="hidden"
               x-ref="hidden"
               name="{{ $name }}"
               value="{{ $value }}"
               {{ $attributes->filter(fn($v, $k) => str_starts_with($k, 'wire:')) }}>
    </div>
@endif
