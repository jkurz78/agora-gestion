<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\Association;
use App\Models\TypeOperation;
use App\Support\CurrentAssociation;
use Illuminate\Support\Facades\Storage;

final class EmailLogo
{
    /** Tags HTML autorisés dans les corps d'email (inclut img pour les logos). */
    public const ALLOWED_TAGS = '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div><table><tr><td><th><img>';

    /** CID name used for the association logo inline attachment. */
    public const CID_ASSO = 'logo-asso';

    /** CID name used for the type-operation logo inline attachment. */
    public const CID_OP = 'logo-op';

    /**
     * Returns file path + MIME type for the association logo, or null if unavailable.
     *
     * @return array{path: string, mime: string}|null
     */
    public static function resolve(?Association $association = null): ?array
    {
        $association = $association ?? CurrentAssociation::tryGet();
        if (! $association) {
            return null;
        }

        $fullPath = $association->brandingLogoFullPath();
        if (! $fullPath || ! Storage::disk('local')->exists($fullPath)) {
            return null;
        }

        return [
            'path' => Storage::disk('local')->path($fullPath),
            'mime' => Storage::disk('local')->mimeType($fullPath) ?: 'image/png',
        ];
    }

    /**
     * Returns file path + MIME type for a TypeOperation logo, or null if unavailable.
     *
     * @return array{path: string, mime: string}|null
     */
    public static function resolveForTypeOperation(?int $typeOperationId): ?array
    {
        if (! $typeOperationId) {
            return null;
        }

        $typeOp = TypeOperation::find($typeOperationId);
        if (! $typeOp) {
            return null;
        }

        $fullPath = $typeOp->typeOpLogoFullPath();
        if (! $fullPath || ! Storage::disk('local')->exists($fullPath)) {
            return null;
        }

        return [
            'path' => Storage::disk('local')->path($fullPath),
            'mime' => Storage::disk('local')->mimeType($fullPath) ?: 'image/png',
        ];
    }

    /**
     * Returns variable substitutions {logo} and {logo_operation} using inline CID references.
     *
     * @return array<string, string> Variables {logo} et {logo_operation}
     */
    public static function variables(?int $typeOperationId = null): array
    {
        $logoAsso = self::buildCidImgTag(self::resolve(), self::CID_ASSO, 'Logo');

        $logoOp = '';
        if ($typeOperationId) {
            $typeOp = TypeOperation::find($typeOperationId);
            $logoOp = self::buildCidImgTag(
                self::resolveForTypeOperation($typeOperationId),
                self::CID_OP,
                'Logo '.($typeOp?->nom ?? ''),
            );
        }

        return [
            '{logo}' => $logoAsso,
            '{logo_operation}' => $logoOp ?: $logoAsso, // fallback sur logo association
        ];
    }

    /**
     * Pour l'aperçu navigateur uniquement : remplace les références cid:logo-asso
     * et cid:logo-op par des data URLs base64, afin que les <img> s'affichent
     * dans un contexte HTML hors email (modale Aperçu, vue TinyMCE…).
     *
     * À ne PAS utiliser dans le corps réellement envoyé : le CID est plus
     * efficace (attachement inline unique) et standard pour les mails.
     */
    public static function previewSwap(string $html, ?int $typeOperationId = null): string
    {
        $asso = self::resolve();
        if ($asso !== null) {
            $dataUrl = self::toDataUrl($asso);
            if ($dataUrl !== null) {
                $html = str_replace('cid:'.self::CID_ASSO, $dataUrl, $html);
            }
        }

        if ($typeOperationId !== null) {
            $op = self::resolveForTypeOperation($typeOperationId);
            if ($op !== null) {
                $dataUrl = self::toDataUrl($op);
                if ($dataUrl !== null) {
                    $html = str_replace('cid:'.self::CID_OP, $dataUrl, $html);
                }
            }
        }

        return $html;
    }

    /**
     * @param  array{path: string, mime: string}  $resolved
     */
    private static function toDataUrl(array $resolved): ?string
    {
        $bytes = @file_get_contents($resolved['path']);
        if ($bytes === false) {
            return null;
        }

        return 'data:'.$resolved['mime'].';base64,'.base64_encode($bytes);
    }

    /**
     * Builds a CID <img> tag if the logo resolves, empty string otherwise.
     *
     * @param  array{path: string, mime: string}|null  $resolved
     */
    private static function buildCidImgTag(?array $resolved, string $cid, string $alt): string
    {
        if (! $resolved) {
            return '';
        }

        return '<img src="cid:'.htmlspecialchars($cid).'" alt="'.htmlspecialchars($alt).'" style="height:80px;width:auto;">';
    }
}
