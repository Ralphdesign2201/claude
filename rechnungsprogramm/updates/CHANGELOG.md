# Änderungsprotokoll

Aktuelle Update-Datei: `update-1.2.rgu` (kumulativ ab Version 1.0).

## Version 1.2 (2026-10-10)
- Neu: Der Superadmin legt fest, ob die Kunden-Konten mit oberer Menüleiste oder mit Seitenleiste arbeiten – als Vorgabe oder verbindlich für alle
- Neu: Der Superadmin-Bereich selbst kann ebenfalls mit Seitenleiste dargestellt werden

## Version 1.1 (2026-10-09)
- Neu: Leistungen & Artikel (Katalog) für Handwerker – mit Nummer, Einheit, Preis, USt, Einkaufspreis/Marge, CSV-Import und -Export
- Neu: Leistungen und Artikel lassen sich per Suche direkt in Rechnungen, Angebote und Lieferscheine einfügen
- Neu: wählbare Ansicht – obere Menüleiste oder Seitenleiste mit allen Modulen (Standard unter Einstellungen, persönlich unter Mein Konto)
- Neues Recht „Leistungen & Artikel“ in den Rollen (bestehende Rollen übernehmen automatisch die Stufe von „Rechnungen“)

## Version 1.0 (2026-10-08)
- Erstveröffentlichung: Kunden, Angebote, Rechnungen, Lieferscheine, Mahnwesen
- E-Rechnung (ZUGFeRD/Factur-X, XRechnung), DATEV-Export, E-Mail-Versand
- Benutzer und Rollen, Backups (manuell und per Cronjob), Umstellung SQLite/MySQL
- Update-System mit signierten, kumulativen Update-Dateien
- Einzelinstallation (install.php) und SaaS-Betrieb mit Superadmin (superinstall.php), Zahlung per Kreditkarte (Stripe) und PayPal
- Sicherheit: Zwei-Faktor-Anmeldung, Passwort-Reset, Konto-Sperre, verschlüsselte Zugangsdaten, Sicherheitsprotokoll, Rate-Limits, strikte CSP

