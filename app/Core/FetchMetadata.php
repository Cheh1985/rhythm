<?php

declare(strict_types=1);

namespace App\Core;

/** Rejects browser-declared cross-origin API requests without breaking older Safari clients. */
final class FetchMetadata
{
    public static function requireSameOriginIfPresent(?string $site = null): void
    {
        $site ??= $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site === null || $site === '') {
            return;
        }
        if (!is_string($site) || strtolower(trim($site)) !== 'same-origin') {
            throw new ApiError('cross_origin_denied', 'Запрос должен быть отправлен из этого приложения.', 403);
        }
    }
}
