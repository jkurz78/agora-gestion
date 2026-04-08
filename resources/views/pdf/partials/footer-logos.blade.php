{{--
    Shared fixed-position logos for PDF footers.

    Expects (all optional):
      - $footerLogoBase64 / $footerLogoMime : association logo (left side)
        — only rendered when the caller passes one (typically when the header
        already shows a type_operation logo instead of the association logo).
      - $appLogoBase64 : AgoraGestion logo (right side) — always rendered
        when available.

    The text part of the footer (pagination + "AgoraGestion · date") is
    injected on every page by App\Support\PdfFooterRenderer::render().
    Templates that include this partial must also reserve enough bottom
    margin (e.g. `body { margin: 15mm 15mm 25mm 15mm; }`) to avoid overlap.
--}}
@if(! empty($footerLogoBase64))
    <div style="position:fixed; bottom:10mm; left:15mm;">
        <img src="data:{{ $footerLogoMime ?? 'image/png' }};base64,{{ $footerLogoBase64 }}" style="height:12mm; opacity:0.6;" alt="">
    </div>
@endif
@if(! empty($appLogoBase64))
    <div style="position:fixed; bottom:5mm; right:15mm;">
        <img src="data:image/svg+xml;base64,{{ $appLogoBase64 }}" style="height:13mm; opacity:0.6;" alt="">
    </div>
@endif
