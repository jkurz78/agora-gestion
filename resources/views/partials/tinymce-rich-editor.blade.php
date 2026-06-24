{{--
    Shared rich-text editor using the tuned TinyMCE config from the email composer.

    Parameters (pass via @include(..., [...])):
      $id      — unique textarea id (string)
      $model   — Livewire wire property name to sync into (string)
      $content — initial HTML (string)
      $groups  — assoc array ['GroupLabel' => ['{var}' => 'Human label', ...], ...]
      $height  — editor height in px (int, default 320)
--}}
@php
    $height ??= 320;
@endphp

<div wire:ignore>
    <textarea id="{{ $id }}" rows="10" style="width:100%">{!! $content !!}</textarea>
</div>

@script
<script>
    (function () {
        // ---- Shared helpers (defined once per page, guarded) ----

        if (!window.__qgTinyMce) {
            window.__qgTinyMce = true;

            window.__qgStripVarSpans = function (html) {
                // Callback form — avoids dollar-N corruption in Livewire SupportAutoInjectedAssets.
                return html.replace(
                    /<span\b[^>]*\bqg-mce-var\b[^>]*>(\{[^}]+\})<\/span>/g,
                    function (_match, token) { return token; }
                );
            };

            window.__qgWrapVars = function (html, variables) {
                variables.forEach(function (v) {
                    var escaped = v.replace(/[{}]/g, '\\$&');
                    var regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?![^<]*<\\/span>)', 'g');
                    html = html.replace(regex, '<span class="qg-mce-var mce-noneditable">' + v + '</span>');
                });
                return html;
            };
        }

        // ---- Per-editor init ----

        var editorId = @json($id);
        var wireProp  = @json($model);

        // Build variable list and groups from PHP-passed data.
        var groups = @json($groups);
        var allVars = [];
        Object.values(groups).forEach(function (g) {
            Object.keys(g).forEach(function (v) { allVars.push(v); });
        });

        // Build nested menu items (one nestedmenuitem per group).
        function buildMenuItems(insertFn) {
            return Object.entries(groups).map(function (entry) {
                var groupName = entry[0];
                var vars = entry[1];
                return {
                    type: 'nestedmenuitem',
                    text: groupName,
                    getSubmenuItems: function () {
                        return Object.entries(vars).map(function (ve) {
                            var key   = ve[0];
                            var label = ve[1];
                            return {
                                type: 'menuitem',
                                text: key + ' — ' + label,
                                onAction: function () { insertFn(key); },
                            };
                        });
                    },
                };
            });
        }

        function initEditor() {
            if (typeof tinymce === 'undefined') {
                setTimeout(initEditor, 200);
                return;
            }

            // Already initialized — skip.
            if (tinymce.get(editorId)) return;

            var textarea = document.getElementById(editorId);
            if (!textarea) return;

            var menuItems = buildMenuItems(function (key) {
                var ed = tinymce.get(editorId);
                if (ed) {
                    ed.insertContent('<span class="qg-mce-var mce-noneditable">' + key + '</span>&nbsp;');
                }
            });

            tinymce.init({
                target: textarea,
                language: 'fr_FR',
                language_url: '/vendor/tinymce/langs/fr_FR.js',
                height: @json($height),
                menubar: 'edit insert format table',
                statusbar: true,
                promotion: false,
                plugins: 'lists link noneditable table image media code fullscreen',
                toolbar: [
                    'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor',
                    'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image media link | variablesBtn | code fullscreen',
                ],
                noneditable_class: 'qg-mce-var',
                block_formats: 'Paragraphe=p; Titre 1=h1; Titre 2=h2; Titre 3=h3; Titre 4=h4',
                font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Courier New=courier new,courier,monospace; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
                font_size_formats: '10px 12px 14px 16px 18px 20px 24px 28px 32px 36px',
                table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
                table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
                table_default_attributes: { border: '1', cellpadding: '6', cellspacing: '0' },
                image_title: true,
                image_caption: true,
                image_advtab: true,
                image_class_list: [
                    { title: 'En ligne (défaut)', value: '' },
                    { title: 'Flottante à gauche', value: 'img-float-left' },
                    { title: 'Flottante à droite', value: 'img-float-right' },
                    { title: 'Centrée (bloc)', value: 'img-center' },
                ],
                automatic_uploads: false,
                images_upload_handler: function (blobInfo) {
                    return new Promise(function (resolve) {
                        var reader = new FileReader();
                        reader.onload = function () { resolve(reader.result); };
                        reader.readAsDataURL(blobInfo.blob());
                    });
                },
                file_picker_types: 'image',
                file_picker_callback: function (callback, value, meta) {
                    if (meta.filetype === 'image') {
                        var input = document.createElement('input');
                        input.setAttribute('type', 'file');
                        input.setAttribute('accept', 'image/*');
                        input.addEventListener('change', function () {
                            var file = this.files[0];
                            var reader = new FileReader();
                            reader.onload = function () {
                                callback(reader.result, { alt: file.name, title: file.name });
                            };
                            reader.readAsDataURL(file);
                        });
                        input.click();
                    }
                },
                content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .qg-mce-var { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 3px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; } table { border-collapse: collapse; } td, th { border: 1px solid #ccc; padding: 6px; } .img-float-left { float: left; margin: 0 16px 12px 0; } .img-float-right { float: right; margin: 0 0 12px 16px; } .img-center { display: block; margin: 12px auto; } img { max-width: 100%; height: auto; }',
                setup: function (editor) {
                    editor.ui.registry.addMenuButton('variablesBtn', {
                        text: 'Variables',
                        fetch: function (callback) { callback(menuItems); },
                    });

                    // Preserve aspect ratio on image resize.
                    editor.on('ObjectResized', function (e) {
                        if (e.target.nodeName === 'IMG') {
                            e.target.style.height = 'auto';
                            e.target.removeAttribute('height');
                        }
                    });

                    editor.on('init', function () {
                        editor.setContent(window.__qgWrapVars(editor.getContent(), allVars));
                    });

                    // Sync stripped content to Livewire on every change.
                    editor.on('change input', function () {
                        $wire.set(wireProp, window.__qgStripVarSpans(editor.getContent()));
                    });
                },
            });
        }

        initEditor();

        // Expose a sync helper on window so the save button can force-flush before calling Livewire.
        window['__qgSync_' + editorId] = function () {
            var ed = tinymce.get(editorId);
            if (ed) {
                $wire.set(wireProp, window.__qgStripVarSpans(ed.getContent()));
            }
        };
    })();
</script>
@endscript
