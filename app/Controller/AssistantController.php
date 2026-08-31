<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\FeatureFlags;
use App\WebMcp\ToolCatalog;

final class AssistantController
{
    public function index(): void
    {
        Auth::requireUser();
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie');

        $masterEnabled = FeatureFlags::enabled(FeatureFlags::WEBMCP_ENABLED);
        $readEnabled = FeatureFlags::enabled(FeatureFlags::WEBMCP_READ_ENABLED);

        \render('assistant', [
            'toolCatalog' => $readEnabled ? ToolCatalog::readOnly() : [],
            'webMcpAdapter' => $readEnabled,
            'webMcpMasterEnabled' => $masterEnabled,
            'webMcpReadEnabled' => $readEnabled,
        ], 'Ассистент');
    }
}
