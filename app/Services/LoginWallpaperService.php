<?php

declare(strict_types=1);

namespace BIomie\Services;

/**
 * Legacy wrapper — delegates to LoginUnsplashWallpaperService.
 * Kept for backward-compatible imports; do not add new logic here.
 */
class LoginWallpaperService
{
    public function getWallpaper(): array
    {
        return (new LoginUnsplashWallpaperService())->getWallpaper();
    }
}
