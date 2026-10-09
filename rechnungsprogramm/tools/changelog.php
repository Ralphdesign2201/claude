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
    '1.1' => ['date' => '2026-10-09', 'notes' => [
        'Neu: Leistungen & Artikel (Katalog) für Handwerker – mit Nummer, Einheit, Preis, USt, Einkaufspreis/Marge, CSV-Import und -Export',
        'Neu: Leistungen und Artikel lassen sich per Suche direkt in Rechnungen, Angebote und Lieferscheine einfügen',
        'Neu: wählbare Ansicht – obere Menüleiste oder Seitenleiste mit allen Modulen (Standard unter Einstellungen, persönlich unter Mein Konto)',
        'Neues Recht „Leistungen & Artikel“ in den Rollen (bestehende Rollen übernehmen automatisch die Stufe von „Rechnungen“)',
    ]],
    '1.2' => ['date' => '2026-10-10', 'notes' => [
        'Neu: Der Superadmin legt fest, ob die Kunden-Konten mit oberer Menüleiste oder mit Seitenleiste arbeiten – als Vorgabe oder verbindlich für alle',
        'Neu: Der Superadmin-Bereich selbst kann ebenfalls mit Seitenleiste dargestellt werden',
    ]],
    '1.3' => ['date' => '2026-10-10', 'notes' => [
        'Neu: Der Superadmin kann sich über die normale Anmeldeseite anmelden – Firmen-ID einfach leer lassen (inklusive Zwei-Faktor-Abfrage und Anmeldeschutz)',
    ]],
    '1.4' => ['date' => '2026-10-10', 'notes' => [
        'Geändert: Leistungen und Artikel werden jetzt direkt im Beschreibungsfeld ausgewählt – Nummer oder Text tippen, passende Einträge erscheinen zur Auswahl (Maus oder Pfeiltasten + Enter)',
        'Behoben: Die Übernahme aus dem Katalog in Rechnungen, Angebote und Lieferscheine funktionierte nicht zuverlässig',
        'Behoben: Das Feld „Einzelpreis“ in Rechnungen und Angeboten wurde in der SaaS-Version zu groß dargestellt',
    ]],
    '1.5' => ['date' => '2026-10-10', 'notes' => [
        'Behoben: Nach einem Update zeigte der Browser teils noch das alte Skript aus dem Zwischenspeicher (z. B. keine Katalog-Auswahl im Beschreibungsfeld) – Skripte und Styles werden jetzt bei jeder Änderung automatisch neu geladen',
    ]],
];