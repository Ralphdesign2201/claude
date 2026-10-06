# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Projekt: CRM für Webdesigner (PHP ≥ 8.1, SQLite oder MySQL/MariaDB, eigenes Mini-Framework, **keine Composer-Abhängigkeiten**). UI, Fehlermeldungen, Doku und Commit-Texte sind auf Deutsch – beim Schreiben von Meldungen dabei bleiben. Ausführliche Feature-Doku: `README.md`, Lizenz-Tools: `Lizenz-tools/README.md`.

## Befehle

```bash
cp .env.example .env && php bin/migrate.php     # DB anlegen (Migrationen laufen auch beim Update automatisch)
php bin/seed.php                                 # optional Demo-Daten
php -S localhost:4000 -t public public/index.php # Dev-Server (oder: composer serve)

php tests/run.php                  # API-Integrationstest (startet eigenen PHP-Server, ~2 Min.)
TEST_DB=mysql php tests/run.php    # dasselbe gegen MySQL (TEST_MYSQL_* , Standard crm@127.0.0.1)
php tests/product.php              # Update-Server + Installations-Kopien: signierte Updates, verpasste Versionen, Installer
```

Es gibt kein Lint/Build und kein Test-Framework: `tests/*.php` sind eigenständige Skripte mit `check()/expect()`; einzelne Tests lassen sich nur ausführen, indem man das Skript kürzt oder eine Sektion (`echo "Name\n"`-Blöcke) isoliert. Aktuell alles grün (run.php 655, product.php 115). `php -l datei.php` und `node --check public/assets/app.js` für schnelle Syntax-Checks. UI-Prüfung erfolgt mit Playwright-Skripten gegen einen frisch migrierten Dev-Server (Chromium liegt unter `/opt/pw-browsers`).

Releases entstehen im Admin (System → Versionen & Updates, `PackageBuilder`); vorher `VERSION` hochzählen. Das ZIP für neue Installationen: „Installationspaket herunterladen“ ebendort.

## Architektur

**Backend** (`src/`, PSR-4-artiger Autoloader in `src/bootstrap.php`, Namespace `App\`):
- `public/index.php` → `App::run()` → `src/App.php` registriert *alle* Routen in `router()` (`Router::PUBLIC` / `Router::ADMIN` / Standard = eingeloggtes Team). Neue Endpunkte immer dort eintragen.
- Schichten: `Controllers/` (dünn, validieren mit `Support\Validator`) → `Services/` (Geschäftslogik) → `Support/Db` (PDO-Wrapper, `insert/update/find/require`, Spalte `id` wird automatisch erzeugt). Fehler als `Http\ApiError`.
- Konfiguration: `Support\Env::get` Reihenfolge = echte Umgebungsvariable → `database/settings.json` (Admin-UI, `SettingsService::schema()` definiert änderbare Felder) → `.env` → Default. Neue Einstellungen gehören ins Schema, nicht in `.env`-only.
- Datenbank: Migrationen `database/migrations/NNN_*.sql` sind in **SQLite-Syntax** geschrieben; `Support\Migrator` übersetzt sie automatisch für MySQL (ANSI_QUOTES). Tabellen mit `updatedAt` in `Db::HAS_UPDATED_AT` eintragen. `DatabaseSwitch`/`DatabaseTransfer` kopieren Daten zwischen SQLite und MySQL – neue Tabellen müssen dort funktionieren. Zeitstempel immer über `Support\Dates::now()` (streng monoton, ISO-ms).
- Zwei Auth-Welten: Team (JWT, Rollen ADMIN/Mitarbeiter, Login per Benutzername oder E-Mail) und Kundenportal (`/api/portal/*`, Token nur als Hash gespeichert, `PortalAccount`).

**Update-System** (bereichsübergreifend; das frühere Lizenzsystem wurde bewusst komplett entfernt – nicht wieder einführen):
- Jede Installation kann *Update-Server* sein (`ReleaseService`, `UpdateApiController`, Signatur über `UpdateSigner`, Ed25519-Schlüssel `database/update.key`, fällt auf altes `license.key` zurück) und *Client* (`product.json` mit `server` + `publicKeys`; `Support\Product`, `Support\UpdateClient`, `UpdateService`).
- `ReleaseService::plan()` wählt das Ziel inkl. `minFrom`-Kette; `UpdateService::installAll()` installiert in Schleife (Download, SHA-256 + Signatur, Whitelist-Verzeichnisse, Backup, Rollback). Jedes Release ist ein **vollständiger Stand** (Code + alle Migrationen), damit Versionen übersprungen werden können. `product.json`, `public/install.php`, Daten und Einstellungen werden nie überschrieben.
- `PackageBuilder` baut Update-Paket und Installationspaket aus den Dateien des laufenden Servers; das Manifest-Format teilen sich `PackageBuilder`, `ReleaseService::readManifest` und `UpdateService::extract`.
- Alte DB-Spalten/Tabellen des Lizenzsystems (`License*`, `Product.license*`, `Ticket.licenseId` …) bleiben ungenutzt bestehen (Migration 011 entfernt nur `LicenseNonce`); die API liefert sie nicht mehr aus.

**Weitere Querschnittsthemen:** `LegalService` (Impressum/Datenschutz/AGB-Generator, Markdown-light→HTML, öffentliche Seiten `/impressum` etc.) und `HealthService` (rote/gelbe Dashboard-Hinweise, Cron-Zeitstempel in `database/cron-state.json`) hängen an den Einstellungen und fast allen Feature-Flags; Support-Tickets (`TicketService`, SLA, Anhänge in `uploads/tickets/`); Backups (`BackupService`, optional verschlüsselt); Cron-Arbeit läuft über `bin/cron.php` **oder** `POST /api/cron/run` mit `CRON_TOKEN`.

**Frontend:** Vanilla-JS-SPA ohne Build-Schritt: `public/assets/app.js` (Admin, `VIEWS`-Map + ein großer delegierter Click-Handler mit `data-act`), `portal.js` (Kundenportal), `app.css`. Menü-Gruppen in `NAV_GROUPS`; neue Ansicht = `vXyz()` in `VIEWS` + Eintrag in `NAV_GROUPS`.

**Installer:** `public/install.php` (Assistent, sperrt sich über `database/installed.lock`). Apache-`.htaccess`-Dateien schützen `database/ src/ bin/ tests/ uploads/` etc.

## Konventionen / Stolperfallen

- Admin-Passwörter oder Zugangsdaten des Nutzers nie in Repo-Dateien schreiben.
- Keine PR anlegen, außer ausdrücklich verlangt; Entwicklung auf dem vorgegebenen Feature-Branch.
