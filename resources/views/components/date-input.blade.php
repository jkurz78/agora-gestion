@props([
    'id'       => null,
    'name'     => '',
    'value'    => '',
    'disabled' => false,
])

@if ($disabled)
    <input type="text"
           value="{{ $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '' }}"
           class="form-control bg-light" disabled>
@else
    <div class="input-group" wire:ignore
         @if($id) id="{{ $id }}" @endif
         x-data="{
             fp: null,
             init() {
                 const hidden = this.\$refs.hidden;
                 this.fp = flatpickr(this.\$refs.input, {
                     locale: 'fr',
                     dateFormat: 'd/m/Y',
                     allowInput: true,
                     disableMobile: true,
                     defaultDate: hidden.value || null,
                     parseDate(str) { return window.svsParseFlatpickrDate(str); },
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
             },
             destroy() { if (this.fp) this.fp.destroy(); }
         }">
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
