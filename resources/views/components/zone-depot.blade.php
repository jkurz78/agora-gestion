{{--
    Zone de glisser-déposer autour d'un champ fichier existant.

    Le composant n'REMPLACE rien : il ENVELOPPE le `<label>` et l'`<input
    type="file">` déjà en place, qui restent la voie normale au clic. Le
    glisser-déposer s'ajoute par-dessus, si bien qu'un navigateur sans
    glisser-déposer, ou un utilisateur au clavier, garde exactement le
    comportement d'avant.

    Pourquoi passer par un DataTransfer : la propriété `input.files` n'accepte
    qu'un FileList, objet qu'on ne peut pas construire directement. On recompose
    donc un DataTransfer, on lui ajoute le fichier déposé, et on affecte son
    FileList. Puis on émet un événement `change` — c'est lui, et lui seul, que
    Livewire écoute pour démarrer le téléversement de `wire:model`.

    Un seul fichier est retenu, même si l'utilisateur en dépose plusieurs : les
    champs qui utilisent ce composant sont tous à fichier unique, et prendre
    silencieusement le premier vaut mieux que téléverser un fichier que
    l'utilisateur n'aura pas vu partir.

    Usage :
        <x-zone-depot>
            <label class="btn btn-primary">
                Choisir un fichier
                <input type="file" wire:model="pieceJointe" class="d-none">
            </label>
        </x-zone-depot>
--}}
{{-- Pour une zone sans texte d'aide, passer une chaîne VIDE (`aide=""`) et
     non `:aide="null"` : @props traite un null explicite comme un attribut
     absent et réapplique la valeur par défaut. --}}
@props(['aide' => 'ou glissez-déposez le fichier ici'])

<div
    x-data="{
        survol: false,
        deposer(evenement) {
            this.survol = false;

            const fichiers = evenement.dataTransfer?.files;
            if (! fichiers || fichiers.length === 0) {
                return;
            }

            const champ = this.$refs.contenu?.querySelector('input[type=file]');
            if (! champ || champ.disabled) {
                return;
            }

            const paquet = new DataTransfer();
            paquet.items.add(fichiers[0]);
            champ.files = paquet.files;
            champ.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    @dragover.prevent="survol = true"
    @dragenter.prevent="survol = true"
    @dragleave.prevent="survol = false"
    @drop.prevent="deposer($event)"
    :class="survol ? 'zone-depot zone-depot-survol' : 'zone-depot'"
    {{ $attributes }}
>
    <div x-ref="contenu">{{ $slot }}</div>

    @if ($aide)
        <div class="zone-depot-aide small text-muted mt-2">
            <i class="bi bi-arrow-down-circle me-1"></i>{{ $aide }}
        </div>
    @endif
</div>

{{-- Style en ligne, et non via @push('styles') : le layout ne porte qu'une
     pile `scripts`, un @push('styles') serait silencieusement perdu. Le @once
     garantit qu'il n'est rendu qu'une fois par page, même si la zone est
     utilisée plusieurs fois. --}}
@once
        <style>
            .zone-depot {
                border: 2px dashed transparent;
                border-radius: .75rem;
                padding: 1rem;
                transition: border-color .12s ease, background-color .12s ease;
            }
            /* Le pointillé n'apparaît qu'au survol d'un fichier : au repos la
               zone doit rester invisible, sans quoi elle encombre un formulaire
               où le dépôt n'est qu'une commodité parmi d'autres. */
            .zone-depot-survol {
                border-color: #3d5473;
                background-color: rgba(61, 84, 115, .06);
            }
            .zone-depot-survol .zone-depot-aide {
                color: #3d5473 !important;
                font-weight: 600;
            }
        </style>
@endonce
