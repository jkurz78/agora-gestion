<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 14px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        /* Header */
        .header { margin-bottom: 14px; }
        .header .logo { max-height: 96px; max-width: 192px; }
        .association-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 12px; color: #6c757d; }
        .doc-title { font-size: 18px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 13px; color: #6c757d; text-align: right; margin-top: 2px; }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #999;
        }
        .footer-table { width: 100%; }
        .footer-table td { padding: 0; border: none; }
        .page-number:after { content: counter(page) " / " counter(pages); }

        /* Data table */
        .data-table { margin-top: 10px; }
        .data-table th {
            background-color: #fff;
            color: #212529;
            padding: 5px 6px;
            font-size: 12px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #212529;
        }
        .data-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            font-size: 12px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #6c757d; }
        .fw-bold { font-weight: bold; }

        /* Report-specific styles */
        .cr-section-header td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 6px 10px; }
        .cr-cat td { background: #dce6f0; color: #1e3a5f; font-weight: 600; border-bottom: 1px solid #b8ccdf; padding: 5px 10px; font-size: 12px; }
        .cr-sub td { background: #f7f9fc; color: #444; border-bottom: 1px solid #e2e8f0; padding: 4px 10px; font-size: 11px; }
        .cr-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; border-bottom: none; padding: 7px 10px; }
        .cr-result-pos { background: #2E7D32; color: #fff; font-weight: 700; font-size: 14px; padding: 10px; text-align: center; margin-top: 10px; }
        .cr-result-neg { background: #B5453A; color: #fff; font-weight: 700; font-size: 14px; padding: 10px; text-align: center; margin-top: 10px; }

        @yield('styles')
    </style>
</head>
<body>
    {{-- Footer (position fixed = every page) --}}
    <div class="footer">
        <table class="footer-table">
            <tr>
                <td style="text-align:left;width:33%;">{{ config('app.name') }}</td>
                <td style="text-align:center;width:34%;"><span class="page-number"></span></td>
                <td style="text-align:right;width:33%;">Généré le {{ now()->format('d/m/Y à H:i') }}</td>
            </tr>
        </table>
    </div>

    {{-- Header --}}
    <table class="header">
        <tr>
            <td style="width:60%">
                @if($headerLogoBase64 ?? false)
                    <img class="logo" src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" alt="Logo">
                @endif
                @if($association ?? false)
                    <div class="association-name">{{ $association->nom }}</div>
                    <div class="association-address">
                        {{ $association->adresse }}
                        @if($association->code_postal || $association->ville)
                            — {{ $association->code_postal }} {{ $association->ville }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="width:40%">
                <div class="doc-title">{{ $title }}</div>
                @if($subtitle ?? false)
                    <div class="doc-subtitle">{{ $subtitle }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Content --}}
    @yield('content')
</body>
</html>
