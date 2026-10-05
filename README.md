# Kundenverwaltung – Backend für Webdesigner (PHP + SQLite)

REST-API zur Kunden-, Projekt-, Aufgaben- und Rechnungsverwaltung für Webdesigner, Freelancer und kleine Agenturen.
Reines PHP 8.1+ mit SQLite (PDO) – **keine Composer-Abhängigkeiten**, läuft auf jedem Standard-Webspace.

## Features

- **Auth**: Login mit JWT (HS256), Rollen Admin/Mitarbeiter, Brute-Force-Schutz beim Login
- **Kunden**: Firmen- & Kontaktdaten, Status (Lead/Aktiv/Inaktiv/Archiviert), Tags, mehrere Ansprechpartner
- **Projekte**: Status, Budget, Stundensatz, Start-/Fälligkeitsdatum, verknüpft mit Kunde
- **Aufgaben**: Kanban-fähig (Status, Priorität, Position, Fälligkeit, Zuweisung)
- **Zeiterfassung**: Einträge pro Projekt/Aufgabe, abrechenbar oder nicht
- **Rechnungen**: Positionen, Steuersatz, Rabatt, automatische Nummerierung (`RE-2026-0001`), Zahlungen, automatischer Status „Bezahlt“ (auf Cent gerundet)
- **Verträge**, **Notizen** (pinnbar), **Dokumente** (Upload bis 25 MB)
- **Dashboard**: Umsatz (bezahlt/offen/überfällig), aktive Projekte, offene Aufgaben, letzte Aktivitäten
- **Activity-Log** je Kunde/Projekt
- **Benutzerverwaltung** durch Admins

## Setup

Voraussetzung: PHP ≥ 8.1 mit den Erweiterungen `pdo_sqlite` und `mbstring`.

```bash
cp .env.example .env        # JWT_SECRET anpassen!
php bin/migrate.php         # legt die SQLite-Datenbank database/app.db an
php bin/seed.php            # optional: Demo-Admin + Beispieldaten
composer serve              # oder: php -S localhost:4000 -t public public/index.php
```

Demo-Login nach `bin/seed.php`: `admin@example.com` / `admin1234` (Passwort per `SEED_ADMIN_PASSWORD` änderbar).

Ohne Seed: Der **erste** über `POST /api/auth/register` angelegte Benutzer wird Admin. Danach ist die
Selbstregistrierung gesperrt (außer `ALLOW_REGISTRATION=true`); weitere Benutzer legt der Admin per `POST /api/users` an.

## Tests

```bash
php tests/run.php           # oder: composer test
```

Startet einen Server mit frischer Temp-Datenbank und prüft die komplette API per HTTP (Auth, CRUD, Rechnungslogik, Upload, Rechte, Sicherheit).

## Endpunkte

Alle Endpunkte (außer `/health`, `/uploads/*`, `/api/auth/register|login`) benötigen `Authorization: Bearer <token>`.

| Bereich       | Endpunkt |
|---------------|----------|
| Auth          | `POST /api/auth/register`, `POST /api/auth/login`, `GET /api/auth/me` |
| Kunden        | `GET/POST /api/clients`, `GET/PATCH/DELETE /api/clients/:id`, `POST /api/clients/:id/contacts`, `PATCH/DELETE /api/clients/:id/contacts/:contactId` |
| Projekte      | `GET/POST /api/projects`, `GET/PATCH/DELETE /api/projects/:id`, `GET/POST /api/projects/:id/tasks` |
| Aufgaben      | `GET /api/tasks`, `PATCH/DELETE /api/tasks/:id` |
| Zeiterfassung | `GET/POST /api/time-entries`, `PATCH/DELETE /api/time-entries/:id` |
| Rechnungen    | `GET/POST /api/invoices`, `GET/PATCH/DELETE /api/invoices/:id`, `POST /api/invoices/:id/payments`, `DELETE /api/invoices/:id/payments/:paymentId` |
| Verträge      | `GET/POST /api/contracts`, `GET/PATCH/DELETE /api/contracts/:id` |
| Notizen       | `GET/POST /api/notes`, `PATCH/DELETE /api/notes/:id` |
| Dokumente     | `GET /api/documents`, `POST /api/documents` (multipart, Feld `file`), `DELETE /api/documents/:id` |
| Dashboard     | `GET /api/dashboard/summary` |
| Benutzer      | `GET /api/users`; `POST`, `PATCH/DELETE /api/users/:id` (nur Admin) |

Listen unterstützen `?page=&pageSize=&search=&status=…` (Filter je nach Bereich) und liefern `{ items, meta }`.
Fehler kommen als `{ "error": "…", "details": … }` mit passendem HTTP-Status.

## Projektstruktur

```
public/index.php        Einstiegspunkt (Document Root zeigt auf public/)
src/App.php             Routen, CORS, Security-Header, Fehlerbehandlung
src/Controllers/        ein Controller je Bereich
src/Http/               Request, Response, Router, ApiError
src/Support/            DB (PDO), Validator, JWT, Auth, Rate-Limit, Rechnungsmathe
database/migrations/    SQL-Migrationen (werden von bin/migrate.php angewendet)
bin/                    migrate.php, seed.php
uploads/                hochgeladene Dateien (außerhalb des Document Root)
tests/run.php           End-to-End-Tests
```

## Produktion

- Document Root auf `public/` setzen (Apache: `.htaccess` liegt bei; nginx: alle Requests an `index.php` weiterleiten und `Authorization` durchreichen)
- `JWT_SECRET` durch einen langen, zufälligen Wert ersetzen, `CORS_ORIGIN` auf die Frontend-URL setzen
- HTTPS erzwingen (Token laufen im Header); `database/` und `uploads/` müssen für den Webserver-Benutzer schreibbar sein
- Hinter einem Reverse-Proxy sieht das Rate-Limiting nur die Proxy-IP (`REMOTE_ADDR`) – dort ggf. selbst begrenzen
- Backup: die Datei `database/app.db` (SQLite im WAL-Modus: auch `-wal`/`-shm` mitsichern oder `sqlite3 app.db ".backup ..."` nutzen)
