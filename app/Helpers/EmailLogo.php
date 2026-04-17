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
