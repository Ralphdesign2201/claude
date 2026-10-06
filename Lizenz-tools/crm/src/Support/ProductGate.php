<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;
use App\Http\Request;
use App\Services\ProductLicense;

/**
 * Schranke der lizenzierten Auslieferung:
 *  – Funktionen, die die Lizenz nicht enthält, sind gesperrt (403),
 *  – ist die Lizenz ungültig, bleibt die Software les- und exportierbar, Änderungen sind gesperrt (402).
 */
final class ProductGate
{
    /** Pfad-Präfix → Funktion der Lizenz */
    private const FEATURES = [
        '/api/tickets' => 'support', '/api/canned' => 'support', '/api/faq' => 'support',
        '/api/portal/tickets' => 'support', '/api/portal/attachments' => 'support', '/api/portal/faq' => 'support',
        '/api/products' => 'shop', '/api/categories' => 'shop', '/api/orders' => 'shop', '/api/catalog' => 'shop',
        '/api/portal/products' => 'shop', '/api/portal/orders' => 'shop',
        '/api/recurring' => 'recurring',
        '/api/licenses' => 'licenses', '/api/license' => 'licenses', '/api/license-entitlements' => 'licenses', '/api/releases' => 'licenses',
        '/api/portal/licenses' => 'licenses',
    ];

    /** Diese Pfade funktionieren auch bei ungültiger Lizenz (Anmeldung, Einstellungen, Lizenz, Backups) */
    private const ALWAYS = [
        '/api/auth', '/api/settings', '/api/system', '/api/backups',
        '/api/portal/login', '/api/portal/logout', '/api/portal/forgot', '/api/portal/reset', '/api/portal/verify', '/api/portal/verify-info',
        '/api/portal/register', '/api/portal/password', '/api/portal/config',
    ];

    public static function check(Request $request): void
    {
        $path = $request->path;
        foreach (self::FEATURES as $prefix => $feature) {
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && !ProductLicense::feature($feature)) {
                $state = ProductLicense::state();
                throw new ApiError(403, $state['valid']
                    ? 'Diese Funktion ist in deiner Lizenz' . ($state['plan'] ? ' (Paket „' . (\App\Services\Entitlements::PLANS[$state['plan']]['name'] ?? $state['plan']) . '“)' : '') . ' nicht enthalten. Ein Upgrade schaltet sie frei.'
                    : 'Die Lizenz ist nicht gültig: ' . ProductLicense::message($state['reason']), ['code' => 'FEATURE_REQUIRED', 'feature' => $feature]);
            }
        }
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return;
        }
        foreach (self::ALWAYS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return;
            }
        }
        if (!ProductLicense::allowed()) {
            $state = ProductLicense::state();
            throw new ApiError(402, 'Die Lizenz ist nicht gültig – ' . ProductLicense::message($state['reason']) . ' Bis dahin sind Änderungen gesperrt; deine Daten bleiben les- und exportierbar. Unter Einstellungen → Lizenz & Updates kannst du das beheben.', ['code' => 'LICENSE_REQUIRED', 'reason' => $state['reason']]);
        }
    }
}
