# HandwerkRechnung (Version 1.0)

Rechnungsprogramm für Handwerksbetriebe – als **Einzelinstallation** oder als **SaaS-Plattform** mit Superadmin, Tarifen und Zahlungen (Kreditkarte/PayPal).
PHP 8 + SQLite/MySQL, keine Abhängigkeiten, läuft auf jedem normalen Webspace.

| Paket | Installer | Beschreibung |
|---|---|---|
| Einzelinstallation | `install.php` | ein Betrieb, kein Superadmin – siehe `docs/README-Einzel.md` |
| SaaS | `superinstall.php` | Mandanten, Superadmin, Tarife, PayPal/Stripe – siehe `docs/README-SaaS.md` |

Fertige Pakete: `dist/` (per `php tools/build_release.php` erzeugt). Sicherheit: `SECURITY.md`.

## Funktionen
Kunden · Leistungen & Artikel (Katalog mit CSV-Import/-Export, in Angebot/Rechnung/Lieferschein per Klick übernehmen) · Angebote (→ Rechnung) · Rechnungen mit PDF (Zahlungsstatus, Storno, Kopie, Kleinunternehmer) · Lieferscheine · Mahnwesen (Erinnerung, 1./2. Mahnung, Gebühren, Zinsen) ·
E-Rechnung ZUGFeRD/Factur-X (PDF/A-3, EN 16931) und XRechnung · DATEV-Buchungsstapel und CSV · E-Mail-Versand mit PDF (SMTP/mail()) ·
Benutzer & frei definierbare Rollen · Zwei-Faktor-Anmeldung · Passwort-Reset · Sicherheitsprotokoll · Backups (manuell, Cron, Download/Upload/Restore) ·
Umstellung SQLite ⇄ MySQL (Einzelinstallation) · Update-System mit signierten, kumulativen Update-Dateien.

## Entwicklung & Releases
- Version in `app/version.php`, Änderungsprotokoll in `tools/changelog.php`.
- **Bei jeder Änderung**: Version erhöhen → `php tools/build_update.php <version>` (erzeugt `updates/update-<version>.rgu`, kumulativ seit 1.0, signiert) → `php tools/build_release.php` (Verkaufspakete) → committen.
- Tests: `python3 tests/run_tests.py single single-mysql saas dbswitch update` (benötigt PHP-CLI, Python 3, für MySQL-Tests einen MariaDB/MySQL-Server).
- Schlüssel zum Signieren: `tools/keys/update-signing.private` (geheim halten, siehe `SECURITY.md`).


## Ansicht (ab Version 1.1)
Unter *Einstellungen → Darstellung* lässt sich zwischen der **oberen Menüleiste** und einer **Seitenleiste mit Modulen** wählen. Jeder Benutzer kann das unter *Mein Profil → Ansicht* für sich überschreiben.
