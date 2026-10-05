<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;

/** Nicht-geheime Einstellungen, damit die Oberfläche fehlende Konfiguration erklären kann. */
final class SettingsController
{
    public static function show(Request $r): Response
    {
        $co = DocumentPdf::company();
        return Response::json([
            'mailConfigured' => Mailer::configured(),
            'mailDriver' => Mailer::driver(),
            'mailFrom' => Mailer::fromAddress(),
            'company' => [
                'name' => $co['name'],
                'configured' => $co['name'] !== 'Ihr Firmenname' && $co['name'] !== 'Ihr Studio' && $co['iban'] !== '',
            ],
        ]);
    }
}
