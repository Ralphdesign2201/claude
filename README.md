# Kundenverwaltung – Backend für Webdesigner (PHP + SQLite)

REST-API **und Weboberfläche** zur Kunden-, Projekt-, Aufgaben- und Rechnungsverwaltung für Webdesigner, Freelancer und kleine Agenturen.
Reines PHP 8.1+ mit SQLite (PDO) – **keine Composer-Abhängigkeiten**, läuft auf jedem Standard-Webspace.

## Weboberfläche

Nach dem Start öffnest du `http://localhost:4000` im Browser und meldest dich an (nach `bin/seed.php`: `admin@example.com` / `admin1234`).
Die Oberfläche (`public/assets/`) ist reines HTML/CSS/JavaScript ohne Build-Schritt und ohne externe Ressourcen (Systemschriften, keine CDNs).
Sie enthält Dashboard, Kunden (mit Ansprechpartnern, Notizen, Verträgen, Dokument-Upload), Projekte mit Kanban-Board, Zeiterfassung,
Rechnungen mit Zahlungen und – für Admins – die Teamverwaltung.

## Features

- **Auth**: Login mit JWT (HS256), Rollen Admin/Mitarbeiter, Brute-Force-Schutz beim Login
- **Kunden**: Firmen- & Kontaktdaten, Status (Lead/Aktiv/Inaktiv/Archiviert), Tags, mehrere Ansprechpartner
- **Projekte**: Status, Budget, Stundensatz, Start-/Fälligkeitsdatum, verknüpft mit Kunde
- **Aufgaben**: Kanban-fähig (Status, Priorität, Position, Fälligkeit, Zuweisung)
- **Zeiterfassung**: Einträge pro Projekt/Aufgabe, abrechenbar oder nicht
- **Rechnungen**: Positionen, Steuersatz, Rabatt, automatische Nummerierung (`RE-2026-0001`), Zahlungen, automatischer Status „Bezahlt“ (auf Cent gerundet)
- **Rechnungs-PDF**: Download per Klick im Browser, deutsches Geschäftsbrief-Layout (Absender, Empfänger, Positionen, MwSt., Bankverbindung, Mehrseitig mit Seitenzahlen)
- **E-Mail-Versand**: Rechnungen und Angebote mit PDF-Anhang direkt aus dem System senden (eigener SMTP-Client, Versandprotokoll)
- **Angebote**: Nummern `AN-JJJJ-0001`, PDF, E-Mail, mit einem Klick in eine Rechnung umwandeln
- **Mahnwesen**: Übersicht überfälliger Rechnungen, drei Stufen (Zahlungserinnerung, 1. Mahnung, letzte Mahnung) mit editierbarem Text, Gebühr und Frist, Mahnbrief als PDF
- **Abos (wiederkehrende Rechnungen)**: Domains, Hosting, Wartung, Homepage-Miete – monatlich bis jährlich, automatisch per Cron
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

## E-Mail-Versand einrichten

Trage in der `.env` die SMTP-Zugangsdaten deines Mail-Anbieters ein (`SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD`, `MAIL_FROM`).
Danach erscheint bei Rechnungen und Angeboten „Per E-Mail senden“: Empfänger (aus dem Kunden), Betreff und Text sind vorbelegt und änderbar,
das PDF hängt automatisch an. Jeder Versand steht im E-Mail-Verlauf der Rechnung bzw. des Angebots, Fehler (z. B. falsches Passwort,
abgelehnte Adresse) werden mit der Meldung des Mailservers angezeigt. Mit `MAIL_DRIVER=file` kannst du alles ohne Mailserver ausprobieren;
die Mails landen dann als `.eml`-Dateien in `database/outbox/`. Die Zertifikate des Mailservers werden geprüft (`SMTP_INSECURE=true` nur für Tests).

## Angebote

Angebote anlegen, als PDF herunterladen oder per E-Mail senden. „In Rechnung umwandeln“ erzeugt eine Rechnung (Entwurf) mit denselben
Positionen und markiert das Angebot als angenommen; eine zweite Umwandlung ist gesperrt. Versendete Angebote werden nach Ablauf der
Gültigkeit als „Abgelaufen“ angezeigt.

## Mahnwesen

Unter „Mahnwesen“ stehen alle versendeten, überfälligen und noch offenen Rechnungen mit Tagen überfällig und Mahnstand.
Pro Rechnung schlägt das System die nächste Stufe vor (Zahlungserinnerung → 1. Mahnung → letzte Mahnung). Text, Mahngebühr und neue Frist
sind vor dem Versand änderbar; die Mahnung geht wahlweise per E-Mail raus (Mahnbrief und Rechnung als PDF) oder wird nur gespeichert,
um sie auszudrucken. Gebühren und Fristen stellst du über `REMINDER_FEE_1..3` und `REMINDER_DAYS` ein (Standard: keine Gebühr, 7 Tage).
Eine Mahnung versendet das System nie von selbst. **Die Textvorschläge und Gebühren sind keine Rechtsberatung** – bitte einmal vom
Steuerberater oder Anwalt prüfen lassen (Mahngebühren und Verzugszinsen sind nur unter bestimmten Voraussetzungen zulässig).

## Abos (wiederkehrende Rechnungen)

Ein Abo besteht aus Kunde, Positionen, Rhythmus (monatlich, vierteljährlich, halbjährlich, jährlich), Startdatum, optional Enddatum und
Zahlungsziel. Ist ein Abo fällig, erzeugt das System die Rechnung automatisch – als Entwurf, oder bei „automatisch senden“ direkt per E-Mail.
Der Zeitplan wird immer vom Startdatum aus gerechnet, deshalb driftet nichts ab (31.01. → 28.02. → 31.03.). Versäumte Zeiträume werden als
Entwürfe nachgeholt; automatisch gesendet wird nur, wenn genau ein Zeitraum fällig ist.

In den Positionstexten ersetzt das System `{monat}`, `{jahr}`, `{zeitraum}`, `{von}` und `{bis}`, z. B.
„Hosting Paket M – {zeitraum}“ → „Hosting Paket M – 01.11.2026 – 30.11.2026“.

Fällige Abos werden erzeugt durch

- einen täglichen **Cron-Job**: `0 6 * * *  php /pfad/zum/projekt/bin/cron.php`
- oder, wenn dein Hosting nur URL-Cronjobs kennt: `curl -X POST -H "X-Cron-Token: <CRON_TOKEN>" https://deine-domain.de/api/cron/run`
  (Token mit mindestens 16 Zeichen in der `.env` als `CRON_TOKEN` setzen, sonst ist der Endpunkt gesperrt)
- oder per Klick auf „Fällige jetzt abrechnen“ in der Oberfläche.

## Rechnungs-PDF

Auf der Rechnungsseite lädt „PDF herunterladen“ die Rechnung als PDF (`GET /api/invoices/:id/pdf`, mit Token).
Die Absenderdaten (Name, Adresse, Bank, IBAN, USt-IdNr. …) stehen in der `.env` unter `COMPANY_*` – siehe `.env.example`.
Das PDF wird ohne Bibliotheken erzeugt (`src/Pdf/`). Der Hinweis bei 0 % Umsatzsteuer (`INVOICE_ZERO_TAX_NOTE`) ist ein
Standardtext; bitte rechtlich mit dem Steuerberater abstimmen. Empfängeradresse und USt-IdNr. pflegst du im Kundenformular.

## Tests

```bash
php tests/run.php           # oder: composer test
```

Startet einen Server und einen kleinen SMTP-Testserver mit frischer Temp-Datenbank und prüft die komplette API per HTTP (Auth, CRUD, Rechnungslogik, Upload, Rechte, Sicherheit, E-Mail-Versand mit Anhängen, Angebote, Mahnwesen, Abo-Zeitplan).

## Endpunkte

Alle Endpunkte (außer `/`, `/health`, `/uploads/*`, `/api/auth/register|login`) benötigen `Authorization: Bearer <token>`.

| Bereich       | Endpunkt |
|---------------|----------|
| Auth          | `POST /api/auth/register`, `POST /api/auth/login`, `GET /api/auth/me` |
| Kunden        | `GET/POST /api/clients`, `GET/PATCH/DELETE /api/clients/:id`, `POST /api/clients/:id/contacts`, `PATCH/DELETE /api/clients/:id/contacts/:contactId` |
| Projekte      | `GET/POST /api/projects`, `GET/PATCH/DELETE /api/projects/:id`, `GET/POST /api/projects/:id/tasks` |
| Aufgaben      | `GET /api/tasks`, `PATCH/DELETE /api/tasks/:id` |
| Zeiterfassung | `GET/POST /api/time-entries`, `PATCH/DELETE /api/time-entries/:id` |
| Rechnungen    | `GET/POST /api/invoices`, `GET/PATCH/DELETE /api/invoices/:id`, `GET /api/invoices/:id/pdf`, `GET /api/invoices/:id/email-draft`, `POST /api/invoices/:id/send`, `POST /api/invoices/:id/payments`, `DELETE /api/invoices/:id/payments/:paymentId` |
| Angebote      | `GET/POST /api/quotes`, `GET/PATCH/DELETE /api/quotes/:id`, `GET /api/quotes/:id/pdf`, `GET /api/quotes/:id/email-draft`, `POST /api/quotes/:id/send`, `POST /api/quotes/:id/convert` |
| Mahnwesen     | `GET /api/reminders/overview`, `GET /api/invoices/:id/reminder-draft?level=`, `POST /api/invoices/:id/reminders`, `GET /api/reminders/:id/pdf`, `DELETE /api/reminders/:id` |
| Abos          | `GET/POST /api/recurring`, `GET/PATCH/DELETE /api/recurring/:id`, `POST /api/recurring/:id/run`, `POST /api/recurring/run-due`, `POST /api/cron/run` (Token) |
| Einstellungen | `GET /api/settings` (Mail eingerichtet? Firmendaten gesetzt?) |
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
public/assets/          Weboberfläche (index.html, app.js, app.css)
src/App.php             Routen, CORS, Security-Header, Fehlerbehandlung
src/Controllers/        ein Controller je Bereich
src/Http/               Request, Response, Router, ApiError
src/Support/            DB (PDO), Validator, JWT, Auth, Rate-Limit, Rechnungsmathe
src/Pdf/                PDF-Schreiber, Layouts für Rechnung, Angebot und Mahnung
src/Mail/               SMTP-Client (STARTTLS/SSL, Anhänge)
src/Services/           Rechnungslogik, Mailvorlagen, Abo-Zeitplan
database/migrations/    SQL-Migrationen (werden von bin/migrate.php angewendet)
bin/                    migrate.php, seed.php, cron.php (fällige Abos)
uploads/                hochgeladene Dateien (außerhalb des Document Root)
tests/run.php           End-to-End-Tests
```

## Produktion

- Document Root auf `public/` setzen (Apache: `.htaccess` liegt bei; nginx: alle Requests an `index.php` weiterleiten und `Authorization` durchreichen)
- `JWT_SECRET` durch einen langen, zufälligen Wert ersetzen, `CORS_ORIGIN` auf die Frontend-URL setzen
- HTTPS erzwingen (Token laufen im Header); `database/` und `uploads/` müssen für den Webserver-Benutzer schreibbar sein
- Hinter einem Reverse-Proxy sieht das Rate-Limiting nur die Proxy-IP (`REMOTE_ADDR`) – dort ggf. selbst begrenzen
- Backup: die Datei `database/app.db` (SQLite im WAL-Modus: auch `-wal`/`-shm` mitsichern oder `sqlite3 app.db ".backup ..."` nutzen)
