<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche participant — {{ $participant->tiers->prenom ?? '' }} {{ $participant->tiers->nom ?? '' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #212529;
            line-height: 1.5;
            margin: 15mm 15mm 25mm 15mm;
        }

        /* Header */
        .header { margin-bottom: 14px; }
        .header .logo { max-height: 80px; max-width: 160px; }
        .association-name { font-size: 15px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 11px; color: #6c757d; }
        .doc-title { font-size: 20px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 12px; color: #6c757d; text-align: right; margin-top: 3px; }
        .doc-type { font-size: 11px; color: #888; text-align: right; margin-top: 1px; }

        /* Sections */
        .section {
            margin-bottom: 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #fff;
            background: #3d5473;
            padding: 4px 10px;
            border-radius: 3px 3px 0 0;
        }
        .section-body {
            padding: 8px 10px;
        }

        /* Rows */
        .field-row {
            display: block;
            margin-bottom: 3px;
        }
        .field-label {
            display: inline-block;
            width: 120px;
            font-size: 10px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: top;
        }
        .field-value {
            font-size: 12px;
        }

        /* Two columns */
        .two-col { width: 100%; border-collapse: collapse; }
        .two-col td { vertical-align: top; padding: 0; }
        .two-col td:first-child { padding-right: 12px; }

        /* Divider */
        .divider { border-top: 1px solid #eee; margin: 6px 0; }

        /* Nom principal */
        .participant-name {
            font-size: 18px;
            font-weight: bold;
            color: #A9014F;
            margin-bottom: 8px;
        }

        /* Badge confidentiel */
        .badge-confidentiel {
            font-size: 9px;
            font-weight: bold;
            color: #A9014F;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Documents list */
        .doc-item {
            font-size: 11px;
            color: #444;
            margin-bottom: 2px;
        }
        .doc-item:before { content: "• "; color: #A9014F; }

    </style>
</head>
<body>
    @include('pdf.partials.footer-logos')

    {{-- Header --}}
    <table class="header" style="width:100%;border-collapse:collapse;margin-bottom:14px">
        <tr>
            <td style="width:55%;vertical-align:top">
                @if($headerLogoBase64)
                    <img class="logo" src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" alt="Logo">
                @endif
                @if($association)
                    <div class="association-name">{{ $association->nom }}</div>
                    <div class="association-address">
                        {{ $association->adresse }}
                        @if($association->code_postal || $association->ville)
                            — {{ $association->code_postal }} {{ $association->ville }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="width:45%;vertical-align:top">
                <div class="doc-title">Fiche participant</div>
                <div class="doc-subtitle">{{ $operation->nom }}</div>
                <div class="doc-type">
                    @if($typeOperation){{ $typeOperation->nom }} · @endif
                    {{ $operation->date_debut?->format('d/m/Y') }}
                    @if($operation->date_fin) → {{ $operation->date_fin->format('d/m/Y') }}@endif
                </div>
                @if($showParcours)
                    <div style="text-align:right;margin-top:3px"><span class="badge-confidentiel">Confidentiel</span></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Nom du participant --}}
    <div class="participant-name">
        {{ $participant->tiers->prenom ?? '' }} {{ $participant->tiers->nom ?? '' }}
        @if($participant->nom_jeune_fille)
            <span style="font-size:13px;color:#6c757d;font-weight:normal"> (née {{ $participant->nom_jeune_fille }})</span>
        @endif
    </div>

    {{-- Section : Coordonnées --}}
    <div class="section">
        <div class="section-title">Coordonnées</div>
        <div class="section-body">
            <table class="two-col">
                <tr>
                    <td style="width:50%">
                        @if($participant->tiers->telephone)
                            <span class="field-row">
                                <span class="field-label">Téléphone</span>
                                <span class="field-value">{{ $participant->tiers->telephone }}</span>
                            </span>
                        @endif
                        @if($participant->tiers->email)
                            <span class="field-row">
                                <span class="field-label">Email</span>
                                <span class="field-value">{{ $participant->tiers->email }}</span>
                            </span>
                        @endif
                        <span class="field-row">
                            <span class="field-label">Inscrit le</span>
                            <span class="field-value">{{ $participant->date_inscription?->format('d/m/Y') ?? '—' }}</span>
                        </span>
                    </td>
                    <td style="width:50%">
                        @if($participant->tiers->adresse_ligne1)
                            <span class="field-row">
                                <span class="field-label">Adresse</span>
                                <span class="field-value">{{ $participant->tiers->adresse_ligne1 }}</span>
                            </span>
                        @endif
                        @if($participant->tiers->code_postal || $participant->tiers->ville)
                            <span class="field-row">
                                <span class="field-label">Ville</span>
                                <span class="field-value">{{ $participant->tiers->code_postal }} {{ $participant->tiers->ville }}</span>
                            </span>
                        @endif
                        @if($participant->nationalite)
                            <span class="field-row">
                                <span class="field-label">Nationalité</span>
                                <span class="field-value">{{ $participant->nationalite }}</span>
                            </span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Section : Adressé par (prescripteur) --}}
    @if($showPrescripteur && ($participant->referePar || $participant->adresse_par_nom || $participant->adresse_par_etablissement))
    <div class="section">
        <div class="section-title">Adressé par</div>
        <div class="section-body">
            @php
                $ref = $participant->referePar;
                $etab = $ref?->etablissement ?? $participant->adresse_par_etablissement;
                $nom = $ref ? $ref->nom : $participant->adresse_par_nom;
                $prenom = $ref ? $ref->prenom : $participant->adresse_par_prenom;
                $tel = $ref ? $ref->telephone : $participant->adresse_par_telephone;
                $email = $ref ? $ref->email : $participant->adresse_par_email;
                $adresse = $ref ? $ref->adresse_ligne1 : $participant->adresse_par_adresse;
                $cp = $ref ? $ref->code_postal : $participant->adresse_par_code_postal;
                $ville = $ref ? $ref->ville : $participant->adresse_par_ville;
            @endphp
            <table class="two-col">
                <tr>
                    <td style="width:50%">
                        @if($etab)
                            <span class="field-row">
                                <span class="field-label">Établissement</span>
                                <span class="field-value">{{ $etab }}</span>
                            </span>
                        @endif
                        @if($nom || $prenom)
                            <span class="field-row">
                                <span class="field-label">Nom</span>
                                <span class="field-value">{{ $prenom }} {{ $nom }}</span>
                            </span>
                        @endif
                        @if($tel)
                            <span class="field-row">
                                <span class="field-label">Téléphone</span>
                                <span class="field-value">{{ $tel }}</span>
                            </span>
                        @endif
                        @if($email)
                            <span class="field-row">
                                <span class="field-label">Email</span>
                                <span class="field-value">{{ $email }}</span>
                            </span>
                        @endif
                    </td>
                    <td style="width:50%">
                        @if($adresse)
                            <span class="field-row">
                                <span class="field-label">Adresse</span>
                                <span class="field-value">{{ $adresse }}</span>
                            </span>
                        @endif
                        @if($cp || $ville)
                            <span class="field-row">
                                <span class="field-label">Ville</span>
                                <span class="field-value">{{ $cp }} {{ $ville }}</span>
                            </span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    </div>
    @endif

    {{-- Section : Données de santé --}}
    @if($showParcours)
    @php $med = $participant->donneesMedicales; @endphp
    <div class="section">
        <div class="section-title">Données de santé</div>
        <div class="section-body">
            <table class="two-col">
                <tr>
                    <td style="width:50%;vertical-align:top">
                        @if($med?->date_naissance)
                            @php
                                $dateNaiss = $med->dateNaissanceCarbon();
                                $age = $dateNaiss?->age;
                            @endphp
                            <span class="field-row">
                                <span class="field-label">Date naissance</span>
                                <span class="field-value">
                                    {{ $dateNaiss?->format('d/m/Y') ?? $med->date_naissance }}
                                    @if($age !== null) ({{ $age }} ans)@endif
                                </span>
                            </span>
                        @endif
                        @if($med?->sexe)
                            <span class="field-row">
                                <span class="field-label">Sexe</span>
                                <span class="field-value">{{ $med->sexe === 'F' ? 'Féminin' : 'Masculin' }}</span>
                            </span>
                        @endif
                        @if($participant->nationalite)
                            <span class="field-row">
                                <span class="field-label">Nationalité</span>
                                <span class="field-value">{{ $participant->nationalite }}</span>
                            </span>
                        @endif
                        @if($med?->taille)
                            <span class="field-row">
                                <span class="field-label">Taille</span>
                                <span class="field-value">{{ $med->taille }} cm</span>
                            </span>
                        @endif
                        @if($med?->poids)
                            <span class="field-row">
                                <span class="field-label">Poids</span>
                                <span class="field-value">{{ $med->poids }} kg</span>
                            </span>
                        @endif
                    </td>
                    </td>
                </tr>
            </table>
            @if($med?->notes)
                <div class="divider"></div>
                <span class="field-label">Notes médicales</span>
                <div style="font-size:11px;color:#444;line-height:1.4;margin-top:2px">{!! $med->notes !!}</div>
            @endif
        </div>
    </div>

    {{-- Section : Contacts médicaux --}}
    @php
        $medecinNom = $participant->medecinTiers?->nom ?? $med?->medecin_nom;
        $medecinPrenom = $participant->medecinTiers?->prenom ?? $med?->medecin_prenom;
        $medecinTel = $participant->medecinTiers?->telephone ?? $med?->medecin_telephone;
        $medecinEmail = $participant->medecinTiers?->email ?? $med?->medecin_email;
        $medecinAdresse = $participant->medecinTiers?->adresse_ligne1 ?? $med?->medecin_adresse;
        $medecinCp = $participant->medecinTiers?->code_postal ?? $med?->medecin_code_postal;
        $medecinVille = $participant->medecinTiers?->ville ?? $med?->medecin_ville;

        $therNom = $participant->therapeuteTiers?->nom ?? $med?->therapeute_nom;
        $therPrenom = $participant->therapeuteTiers?->prenom ?? $med?->therapeute_prenom;
        $therTel = $participant->therapeuteTiers?->telephone ?? $med?->therapeute_telephone;
        $therEmail = $participant->therapeuteTiers?->email ?? $med?->therapeute_email;
        $therAdresse = $participant->therapeuteTiers?->adresse_ligne1 ?? $med?->therapeute_adresse;
        $therCp = $participant->therapeuteTiers?->code_postal ?? $med?->therapeute_code_postal;
        $therVille = $participant->therapeuteTiers?->ville ?? $med?->therapeute_ville;
    @endphp
    <table style="width:100%;border-collapse:separate;border-spacing:6px 0;">
        <tr>
            <td style="width:50%;vertical-align:top">
                <div class="section" style="margin-top:0">
                    <div class="section-title">Médecin traitant</div>
                    <div class="section-body">
                        @if($medecinNom || $medecinPrenom)
                            <span class="field-row"><span class="field-label">Nom</span><span class="field-value">{{ $medecinPrenom }} {{ $medecinNom }}</span></span>
                        @endif
                        @if($medecinTel)
                            <span class="field-row"><span class="field-label">Tél.</span><span class="field-value">{{ $medecinTel }}</span></span>
                        @endif
                        @if($medecinEmail)
                            <span class="field-row"><span class="field-label">Email</span><span class="field-value">{{ $medecinEmail }}</span></span>
                        @endif
                        @if($medecinAdresse || $medecinCp || $medecinVille)
                            <span class="field-row"><span class="field-label">Adresse</span><span class="field-value">{{ $medecinAdresse }} {{ $medecinCp }} {{ $medecinVille }}</span></span>
                        @endif
                        @if(!$medecinNom && !$medecinPrenom)
                            <span style="color:#999;font-size:10px">Non renseigné</span>
                        @endif
                    </div>
                </div>
            </td>
            <td style="width:50%;vertical-align:top">
                <div class="section" style="margin-top:0">
                    <div class="section-title">Thérapeute référent</div>
                    <div class="section-body">
                        @if($therNom || $therPrenom)
                            <span class="field-row"><span class="field-label">Nom</span><span class="field-value">{{ $therPrenom }} {{ $therNom }}</span></span>
                        @endif
                        @if($therTel)
                            <span class="field-row"><span class="field-label">Tél.</span><span class="field-value">{{ $therTel }}</span></span>
                        @endif
                        @if($therEmail)
                            <span class="field-row"><span class="field-label">Email</span><span class="field-value">{{ $therEmail }}</span></span>
                        @endif
                        @if($therAdresse || $therCp || $therVille)
                            <span class="field-row"><span class="field-label">Adresse</span><span class="field-value">{{ $therAdresse }} {{ $therCp }} {{ $therVille }}</span></span>
                        @endif
                        @if(!$therNom && !$therPrenom)
                            <span style="color:#999;font-size:10px">Non renseigné</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>
    @endif

    {{-- Section : Engagements --}}
    <div class="section">
        <div class="section-title">Engagements &amp; consentements</div>
        <div class="section-body">
            <table class="two-col">
                <tr>
                    <td style="width:50%;vertical-align:top">
                        @if($showParcours)
                            <span class="field-row">
                                <span class="field-label">Contact médecin</span>
                                <span class="field-value">{{ $participant->autorisation_contact_medecin ? 'Autorisé' : 'Non autorisé' }}</span>
                            </span>
                        @endif
                        @if($showDroitImage && $participant->droit_image)
                            <span class="field-row">
                                <span class="field-label">Droit à l'image</span>
                                <span class="field-value">{{ $participant->droit_image->label() }}</span>
                            </span>
                        @endif
                        @if($participant->rgpd_accepte_at)
                            <span class="field-row">
                                <span class="field-label">RGPD accepté</span>
                                <span class="field-value">{{ $participant->rgpd_accepte_at->format('d/m/Y') }}</span>
                            </span>
                        @endif
                        @if($participant->formulaireToken?->rempli_at)
                            <span class="field-row">
                                <span class="field-label">Formulaire soumis</span>
                                <span class="field-value">{{ $participant->formulaireToken->rempli_at->format('d/m/Y à H:i') }}</span>
                            </span>
                        @endif
                    </td>
                    <td style="width:50%;vertical-align:top">
                        @if($showParcours && $participant->mode_paiement_choisi)
                            <span class="field-row">
                                <span class="field-label">Mode paiement</span>
                                <span class="field-value">{{ $participant->mode_paiement_choisi === 'comptant' ? 'Comptant' : 'Par séance' }}</span>
                            </span>
                        @endif
                        @if($showParcours && $participant->moyen_paiement_choisi)
                            <span class="field-row">
                                <span class="field-label">Moyen paiement</span>
                                <span class="field-value">{{ ucfirst($participant->moyen_paiement_choisi) }}</span>
                            </span>
                        @endif
                        @if($showParcours && $participant->typeOperationTarif)
                            <span class="field-row">
                                <span class="field-label">Tarif</span>
                                <span class="field-value">
                                    {{ $participant->typeOperationTarif->libelle }}
                                    — {{ number_format((float) $participant->typeOperationTarif->montant, 2, ',', ' ') }} €
                                </span>
                            </span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Section : Documents --}}
    @if($showParcours && count($documents) > 0)
    <div class="section">
        <div class="section-title">Documents joints</div>
        <div class="section-body">
            @foreach($documents as $doc)
                <div class="doc-item">{{ $doc }}</div>
            @endforeach
        </div>
    </div>
    @endif

    @if($showParcours)
        <div style="position: fixed; top: 5mm; right: 10mm; font-size: 9px; color: #A9014F; font-weight: bold; letter-spacing: 1px;">
            CONFIDENTIEL
        </div>
    @endif
</body>
</html>
