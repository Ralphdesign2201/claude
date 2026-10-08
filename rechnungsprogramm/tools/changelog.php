<?php
/** Änderungsprotokoll je Version (neueste unten anfügen). Wird in Update-Pakete übernommen und dem Kunden vor der Installation angezeigt. */
return [
    '1.0' => ['date' => '2026-10-08', 'notes' => [
        'Erstveröffentlichung: Kunden, Angebote, Rechnungen, Lieferscheine, Mahnwesen',
        'E-Rechnung (ZUGFeRD/Factur-X, XRechnung), DATEV-Export, E-Mail-Versand',
        'Benutzer und Rollen, Backups (manuell und per Cronjob), Umstellung SQLite/MySQL',
        'Update-System mit signierten, kumulativen Update-Dateien',
        'Einzelinstallation (install.php) und SaaS-Betrieb mit Superadmin (superinstall.php), Zahlung per Kreditkarte (Stripe) und PayPal',
        'Sicherheit: Zwei-Faktor-Anmeldung, Passwort-Reset, Konto-Sperre, verschlüsselte Zugangsdaten, Sicherheitsprotokoll, Rate-Limits, strikte CSP',
    ]],
];
