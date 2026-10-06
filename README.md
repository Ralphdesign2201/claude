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
- **Produktkatalog**: beliebig viele Kategorien und Produkte in drei Arten: Einmalkauf (Webseite, Skripte, Druckaufträge), Mietprodukte (Hosting, Domains, Miethomepage, Wartung, SEO) und Zeitprodukte (Projekte auf Stundenbasis)
- **Bestellungen**: Kunden bestellen im Portal, du nimmst an oder lehnst ab; aus einer Annahme entstehen automatisch Rechnung, Abo oder Projekt
- **Kundenportal**: Kunden sehen ihre Rechnungen und Angebote selbst, laden PDFs herunter und nehmen Angebote online an – per persönlichem Link, ohne Passwort
- **Datensicherung**: automatische, geprüfte und auf Wunsch verschlüsselte Backups (Datenbank + Dokumente) mit Aufbewahrungsregel und Wiederherstellung
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

## Produkte und Bestellungen

Unter „Produkte“ legst du **beliebig viele Kategorien und Produkte** an (kein Limit). Jedes Produkt hat eine von drei Arten:

| Art | Beispiele | Preis | Beim Annehmen einer Bestellung entsteht |
|---|---|---|---|
| **Einmalig** | Webseitenerstellung, Skripte, Druckaufträge | Festpreis (optional mit Einheit, z. B. „Stück“, und Mindestmenge) | eine **Rechnung** (Entwurf) |
| **Miete** | Webhosting, Domain, Miethomepage, Wartung, SEO | Preis je Zeitraum (monatlich bis jährlich), optional einmalige Einrichtungsgebühr | ein **Abo** (wiederkehrende Rechnung), eine Rechnung für die Einrichtung und auf Wunsch gleich die erste Abo-Rechnung |
| **Nach Stunden** | Projekte auf Stundenbasis | Stundensatz | ein **Projekt** mit Stundensatz und Budget aus der geschätzten Stundenzahl, abgerechnet wird später nach den gebuchten Zeiten |

Unter dem Reiter „Produkte“ im Kundenportal sehen Kunden die **aktiven** Produkte nach Kategorien und bestellen mit Menge und Anmerkung. Der Preis
wird im Bestelldialog live berechnet (netto, MwSt., brutto). Die Bestellung erscheint bei dir unter „Bestellungen“ (mit Zähler im Menü und Hinweis auf dem Dashboard);
du bekommst eine E-Mail, der Kunde eine Eingangsbestätigung (wenn E-Mail eingerichtet ist). Dort **nimmst du an oder lehnst mit Begründung ab**; der Kunde sieht den Stand im Portal
und per E-Mail. Solange eine Bestellung offen ist, kann der Kunde sie zurückziehen. Du kannst Bestellungen auch **für Kunden erfassen** (z. B. nach einem Telefonat).

- **Preise werden bei der Bestellung festgehalten.** Spätere Preisänderungen oder das Löschen des Produkts ändern bestehende Bestellungen nicht.
- Inaktive Produkte und Produkte in ausgeblendeten Kategorien sehen Kunden nicht; in Angebote, Rechnungen und Abos kannst du sie weiter einfügen („Produkt aus dem Katalog einfügen“).
- Aus einer Bestellung entstehen **nur Entwürfe**: nichts wird ohne dein Zutun versendet (außer du aktivierst beim Abo „automatisch senden“).
- Das Löschen einer Kategorie löscht ihre Produkte nicht; sie erscheinen dann unter „Ohne Kategorie“ bzw. im Portal unter „Weitere Produkte“.
- Pro Kunde sind höchstens 20 offene Bestellungen gleichzeitig möglich (Schutz vor Missbrauch).
- **Schnellstart:** Auf der leeren Produktseite legt „Beispielkatalog anlegen“ (oder `php bin/seed-catalog.php`) deine typischen Produkte mit Preisvorschlägen an. Alles ist zunächst inaktiv, bis du es prüfst und aktivierst.

## Kundenportal

Beim Kunden findest du die Karte „Kundenportal“. „Zugang erstellen“ erzeugt einen persönlichen Link der Form
`https://deine-domain.de/portal#<Schlüssel>`; auf Wunsch geht er direkt per E-Mail an den Kunden. Der Kunde braucht dafür kein Passwort (alternativ: Konto, siehe unten).

Im Portal sieht er **nur seine eigenen** Daten:
- Rechnungen (versendet, überfällig, bezahlt) mit Positionen, Zahlungen und offenem Betrag; **keine Entwürfe und keine stornierten Rechnungen**
- die Bankverbindung mit Verwendungszweck, damit er direkt überweisen kann
- Angebote (ohne Entwürfe), die er mit Bestätigung **annehmen oder ablehnen** kann; die Firma bekommt eine E-Mail, und der Status ist sofort im System sichtbar
- PDF-Download aller Dokumente

Zur Sicherheit: Der Schlüssel ist 256 Bit lang und wird nur als Hash gespeichert; der Link wird deshalb **nur einmal beim Erstellen** angezeigt.
Ein neuer Link macht den alten ungültig, „Zugang sperren“ wirkt sofort, Links laufen nach `PORTAL_TOKEN_DAYS` (Standard 365) ab. Der Schlüssel steht im
Teil nach dem `#` der Adresse und wird so nicht in Server-Protokolle geschrieben. Wiederholte falsche Schlüssel werden gebremst.
Setze `APP_URL` auf die öffentliche Adresse, sonst wird sie aus der Anfrage abgeleitet.

## Kundenkonten (Registrierung)

Kunden können sich im Portal (`/portal`) auch **selbst registrieren** – das setzt eingerichteten E-Mail-Versand voraus.
Ablauf: Name und E-Mail eintragen → Bestätigungslink per E-Mail (48 Stunden gültig) → **über den Link das Passwort festlegen** (mind. 10 Zeichen).
Das Passwort wird bewusst erst nach der Bestätigung gewählt, damit niemand mit einer fremden Adresse ein Konto „vorregistrieren“ kann.
Erst dann wird der Kunde angelegt (Status „Interessent“, Quelle „Portal-Registrierung“) – oder mit einem **bestehenden Kunden mit derselben E-Mail-Adresse verknüpft**. Nach der ersten angenommenen Bestellung wird er automatisch „Aktiv“.
Außerdem: Anmelden (optional „angemeldet bleiben“), Passwort vergessen/zurücksetzen (Link 2 Stunden gültig), Passwort ändern (beendet alle anderen Anmeldungen).
Die Antworten verraten nicht, welche Adressen schon registriert sind; Versuche werden gebremst, ein Honigtopf-Feld hält einfache Bots fern. Beim Kunden kannst du Konten sperren, löschen oder einen Reset-Link senden.
Die Portal-Links ohne Passwort (siehe oben) funktionieren weiter.

| Einstellung | Bedeutung |
|---|---|
| `PORTAL_REGISTRATION` | `off` schaltet die Selbstregistrierung ab (Standard: offen, sobald E-Mail eingerichtet ist) |
| `TERMS_URL`, `PRIVACY_URL` | Links zu AGB bzw. Datenschutz; sind sie gesetzt, muss der Kunde der Registrierung zustimmen (Zeitpunkt wird gespeichert) |
| `PORTAL_SESSION_DAYS` | Dauer einer Anmeldung (Standard 14 Tage) |
| `REGISTER_RATE_LIMIT_MAX`, `LOGIN_RATE_LIMIT_MAX` | Registrierungen je IP und Stunde (Standard 10) bzw. Fehlversuche beim Anmelden (Standard 10) |

## Domain-Lizenzen

Produkte (Einmalkauf oder Miete) können mit einer **Lizenz für eine Domain** verkauft werden – z. B. Skripte, Themes, Plugins.
Im Produkt-Dialog: „Mit Lizenz für eine Domain verkaufen“, dazu optional Subdomains, „erst nach bezahlter Rechnung freischalten“ (Standard) und bei Einmalkäufen eine Gültigkeit in Tagen.
Zeitprodukte (nach Stunden) können keine Lizenz haben.

**Ablauf:** Der Kunde gibt beim Bestellen seine Domain an (wird bereinigt: ohne `https://`, Pfad, Port, `www.`; Umlaut-Domains werden zu Punycode) →
du nimmst die Bestellung an → die Lizenz entsteht mit einem Schlüssel `XXXXX-XXXXX-XXXXX-XXXXX-XXXXX` →
sobald die Rechnung **vollständig bezahlt** ist (Zahlung erfassen oder Status „Bezahlt“), wird sie aktiv und der Kunde bekommt den Schlüssel per E-Mail (und im Portal unter „Lizenzen“).
Kostenlose Produkte und Produkte ohne „erst nach Zahlung“ sind sofort aktiv. Bei **Mietprodukten** verlängert jede bezahlte Abo-Rechnung die Lizenz um einen Abrechnungszeitraum; zusätzlich gilt eine Kulanzfrist (`LICENSE_GRACE_DAYS`, 14 Tage).
Jede Rechnung wirkt nur einmal (erneutes Markieren als bezahlt verlängert nicht doppelt). Unter „Lizenzen“ kannst du Domain, Status (aktiv/gesperrt/widerrufen), Ablaufdatum ändern, den Schlüssel neu erzeugen, per E-Mail senden und von Hand Lizenzen ausstellen.
Der Kunde kann seine Domain im Portal selbst ändern (`LICENSE_DOMAIN_CHANGES`, Standard 2 Mal).

**Prüfung in der verkauften Software:** `examples/license-client/LicenseClient.php` (auch unter `/license/client.php`) in die Software legen:

```php
require __DIR__ . '/LicenseClient.php';
$lic = new LicenseClient(
    'https://crm.example.com',        // dein CRM (APP_URL)
    'ÖFFENTLICHER-SCHLÜSSEL',         // https://crm.example.com/api/license/public-key
    $kundenSchluessel,                // vom Kunden eingetragen
    __DIR__ . '/license.cache'        // beschreibbare Datei
);
$lic->require();                      // beendet mit Meldung, wenn die Lizenz nicht gilt (oder: if ($lic->check()) { … })
```

Technik: `POST /api/license/verify {key, domain, nonce}` antwortet mit einer **Ed25519-signierten** Nutzlast (libsodium). Der Client prüft Signatur, Nonce, Domain und Zeitstempel; eine gefälschte „gültig“-Antwort (z. B. von einem Fake-Server oder aus der Datei `hosts`) wird abgelehnt.
Gültige Antworten werden `LICENSE_CACHE_HOURS` (24) zwischengespeichert; ist der Server nicht erreichbar, läuft die Software noch `LICENSE_OFFLINE_DAYS` (7) Tage weiter. Der Zwischenspeicher ist signiert und wird bei jedem Lesen neu geprüft.
Entwicklungsadressen (`localhost`, `*.test`, `*.local`, `127.x`) sind erlaubt (`LICENSE_ALLOW_DEV=false` schaltet das ab). Unbekannte Schlüssel werden pro IP gebremst; abgelehnte Prüfungen siehst du im Lizenz-Detail.

**Der Signaturschlüssel** wird beim ersten Gebrauch erzeugt und in `database/license.key` gespeichert (oder `LICENSE_SECRET_KEY` bzw. `LICENSE_KEY_FILE`). **Geht er verloren, funktioniert keine ausgelieferte Software mehr** – er ist deshalb im Backup enthalten (bei Verschlüsselung: `BACKUP_PASSPHRASE` setzen!). Die Datei gehört nie ins Git.

**Ehrliche Grenzen:** Eine Lizenzprüfung in PHP-Code, den der Kunde besitzt, kann jemand mit Programmierkenntnissen aus der Software entfernen. Sie verhindert Weitergabe an ehrliche Dritte und zeigt dir, wo die Software läuft – echten Schutz bringt nur, was der Kunde nicht selbst ändern kann (Updates, Support, Funktionen über deine API). Sinnvoll ist, die Prüfung an mehreren Stellen einzubauen und Quellcode ggf. zu verschleiern.

## Support (Tickets)

Kunden schreiben im Kundenportal unter **„Support“** eine Anfrage (Betreff, Kategorie, Dringlichkeit, optional eine betroffene Lizenz, bis zu 5 Dateien) und verfolgen sie dort. Beim Schreiben schlägt das Portal passende **Hilfe-Artikel** vor.
Sie bekommen eine Eingangsbestätigung, jede Antwort des Teams per E-Mail (mit Portal-Link), sehen ungelesene Antworten, können das Ticket schließen oder wieder öffnen und nach der Lösung 1–5 Sterne vergeben. Antwortet der Kunde auf ein gelöstes/geschlossenes Ticket, öffnet es sich von selbst wieder.

Für das Team gibt es im Menü **„Support“** (mit Zähler für neue Tickets):
- Kennzahlen: offen, überfällig, nicht zugewiesen, Ø erste Antwortzeit, Zufriedenheit; Klick auf eine Kachel filtert die Liste.
- Filter: offene, mir zugewiesen, nicht zugewiesen, neu/ungelesen, überfällig, gelöst, geschlossen, alle – plus Suche (Nummer, Betreff, Kunde, **Nachrichtentext**), Priorität und Kategorie. **Sammelaktionen** für Status, Priorität, Zuweisung und (Admin) Löschen.
- Ticket-Ansicht: Verlauf als Unterhaltung, **interne Notizen** (nie für den Kunden sichtbar, auch ihre Anhänge nicht), automatischer Änderungsverlauf (Status, Zuweisung, Priorität …), Antworten mit Dateien, **Textbausteine** mit Platzhaltern (`{kunde}`, `{ticket}`, `{betreff}`, `{mitarbeiter}`, `{firma}`) und Status-Wechsel direkt beim Senden (z. B. „Wartet auf Kunde“). Seitenleiste: Status, Priorität, Bearbeiter (bekommt eine Mail), Kategorie, Lizenz, Stichworte, Kundendaten, weitere Tickets des Kunden, Zeiten und Bewertung.
- **Fälligkeiten (SLA):** `TICKET_FIRST_RESPONSE_HOURS` (24) und `TICKET_RESOLVE_HOURS` (72); bei „Hoch“ gilt die halbe, bei „Dringend“ ein Viertel, bei „Niedrig“ die doppelte Zeit. „Wartet auf Kunde“ und „Zurückgestellt“ stoppen die Uhr. Überfällige Tickets sind markiert und filterbar.
- Tickets lassen sich auch **im Namen des Kunden erfassen** (z. B. nach einem Anruf), optional mit Benachrichtigung.
- **Automatik per Cron** (`bin/cron.php`): gelöste Tickets werden nach `TICKET_AUTOCLOSE_DAYS` (7) geschlossen, „Wartet auf Kunde“ ohne Reaktion nach `TICKET_PENDING_DAYS` (14) als gelöst markiert.
- Alles Weitere (Kategorien, Zeiten, Benachrichtigungsadresse, Anhangsgröße) unter Einstellungen → Support.

Anhänge liegen **nicht** im öffentlichen Upload-Ordner, sondern in `uploads/tickets/` und werden nur nach Prüfung der Berechtigung ausgeliefert (Kunde nur eigene, ohne interne Notizen). Erlaubt sind Bilder, PDF, Text, Office und ZIP; HTML/SVG/Skripte werden abgelehnt, Bilder auf Echtheit geprüft. Sie sind im Backup enthalten.
Missbrauchsbremse im Portal: höchstens 15 neue Tickets und 60 Nachrichten pro Kunde und Stunde. Ehrlich: Antworten per E-Mail-Antwort (Inbound-Mail) gibt es nicht – Kunden antworten im Portal.

## Einstellungen und Datenbank

Oben rechts in der Kopfleiste (nur für Admins) öffnet **„Einstellungen“** eine Seite für Firmendaten, Zahlung/Mahnwesen, E-Mail (SMTP, mit Testmail), Kundenportal, Lizenzen, Datensicherung und die Datenbank.
Änderungen gelten sofort und stehen in `database/settings.json` (Rechte 0600, nicht im Git, nicht im Web-Verzeichnis). Reihenfolge der Quellen: echte Server-Umgebungsvariable → Einstellungsseite → `.env` → Standardwert.
Von der Server-Umgebung vorgegebene Werte sind in der Oberfläche gesperrt; Passwörter werden nie wieder angezeigt (leer lassen = unverändert). Bewusst nicht änderbar: `JWT_SECRET`, `CRON_TOKEN`, Speicherorte und der Lizenz-Signaturschlüssel.
Die Zugangsdaten liegen im Klartext in der Einstellungsdatei – sie ist nur durch Dateirechte geschützt und gehört nicht in Backups, die du weitergibst.

**Datenbank wechseln (SQLite ↔ MySQL/MariaDB, in beide Richtungen):** Unter „Datenbank“ Zugangsdaten eintragen, „Verbindung testen“, dann „Zu MySQL wechseln“. Der Ablauf:
Vorab-Backup → Schema im Ziel anlegen → alle Tabellen kopieren → Zeilenzahlen prüfen → erst dann umstellen. Scheitert etwas, bleibt alles beim Alten. Die bisherige Datenbank bleibt unverändert als Rückfall bestehen, und du bleibst angemeldet.
Enthält das Ziel schon Daten, wird nur nach ausdrücklicher Bestätigung überschrieben (eine vorhandene SQLite-Datei wird vorher als `….vor-wechsel-<Zeit>` beiseitegelegt). Während des Kopierens (Sekunden) werden Schreibzugriffe gesperrt.
Voraussetzungen für MySQL: MySQL 8.0.13+ oder MariaDB 10.3+, PHP-Erweiterung `pdo_mysql`, eine bereits angelegte leere Datenbank und ein Benutzer mit dem Recht, Tabellen anzulegen. Das Schema wird aus denselben `database/migrations/*.sql` erzeugt (automatisch übersetzt), beide Datenbanken haben daher immer dieselben Tabellen.
Backups bleiben auch unter MySQL portable SQLite-Dateien und lassen sich in beide Datenbanktypen zurückspielen (`bin/restore.php`).
Ohne Oberfläche geht es auch per Umgebung: `DB_DRIVER=mysql`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, danach `php bin/migrate.php` – dann ist die Datenbank allerdings „vom Server festgelegt“ und in der Oberfläche nicht umschaltbar. Daten übernimmst du in dem Fall über einen vorherigen Wechsel in der Oberfläche oder ein Backup/Restore.
Die Tests laufen gegen beide: `php tests/run.php` und `TEST_DB=mysql php tests/run.php` (MySQL-Zugang über `TEST_MYSQL_HOST/PORT/USER/PASSWORD`).

## Datensicherung (Backup)

Ein Backup ist eine ZIP-Datei mit einem **konsistenten Schnappschuss der Datenbank** (auch bei laufendem Betrieb), allen **hochgeladenen Dokumenten** und
einem Manifest mit Prüfsumme. Jedes Backup wird nach dem Erstellen automatisch geprüft (Datenbank-Integrität, Prüfsumme, Archiv lesbar).

- **Automatisch:** `bin/cron.php` (derselbe tägliche Cron-Aufruf wie für Abos) erstellt ein Backup, wenn das letzte älter als `BACKUP_INTERVAL_HOURS` (24) ist. Alternativ nur sichern: `bin/backup.php`.
- **Von Hand:** Team → „Datensicherung“ → „Backup jetzt erstellen“, Herunterladen und Löschen ebenfalls dort (nur für Admins).
- **Aufbewahrung:** die neuesten `BACKUP_KEEP` (14) Backups und je Monat das neueste der letzten `BACKUP_KEEP_MONTHS` (12) Monate; der Rest wird gelöscht.
- **Verschlüsselung:** Mit `BACKUP_PASSPHRASE` wird das Archiv per AES-256 verschlüsselt (öffnbar z. B. mit 7-Zip). Ohne Passwort ist es **nicht** verschlüsselt, und die Datenbank enthält alle Kundendaten und Passwort-Hashes. Das Passwort bitte separat im Passwortmanager aufbewahren: ohne es lässt sich das Backup nicht wiederherstellen.
- **Zweitkopie:** `BACKUP_COPY_DIR` legt jede Sicherung zusätzlich an einem anderen Ort ab (Cloud-Ordner, Netzlaufwerk, externe Platte). **Backups, die nur auf demselben Server liegen, schützen nicht vor Serverausfall** – bitte unbedingt eine Kopie an einen anderen Ort bringen.
- Die `.env` (Zugangsdaten) ist **nicht** im Backup enthalten und sollte separat gesichert werden.

**Wiederherstellen:**

```bash
php bin/restore.php /pfad/zu/crm-backup-20261005-023000.zip --yes
```

Der bisherige Stand wird vorher als `vor-wiederherstellung-….sqlite` im Backup-Ordner gesichert. Bei verschlüsselten Backups muss `BACKUP_PASSPHRASE` gesetzt sein.
Falsches Passwort, beschädigte Archive und falsche Prüfsummen brechen die Wiederherstellung ab, ohne etwas zu verändern. Während der Wiederherstellung sollte niemand
im System arbeiten. Es lohnt sich, die Wiederherstellung einmal in Ruhe auszuprobieren (z. B. mit `DATABASE_PATH=/tmp/test.db UPLOAD_DIR=/tmp/up php bin/restore.php … --yes`), bevor man sie braucht.

## Rechnungs-PDF

Auf der Rechnungsseite lädt „PDF herunterladen“ die Rechnung als PDF (`GET /api/invoices/:id/pdf`, mit Token).
Die Absenderdaten (Name, Adresse, Bank, IBAN, USt-IdNr. …) stehen in der `.env` unter `COMPANY_*` – siehe `.env.example`.
Das PDF wird ohne Bibliotheken erzeugt (`src/Pdf/`). Der Hinweis bei 0 % Umsatzsteuer (`INVOICE_ZERO_TAX_NOTE`) ist ein
Standardtext; bitte rechtlich mit dem Steuerberater abstimmen. Empfängeradresse und USt-IdNr. pflegst du im Kundenformular.

## Tests

```bash
php tests/run.php           # oder: composer test
```

Startet einen Server und einen kleinen SMTP-Testserver mit frischer Temp-Datenbank und prüft die komplette API per HTTP (Auth, CRUD, Rechnungslogik, Upload, Rechte, Sicherheit, E-Mail-Versand mit Anhängen, Angebote, Mahnwesen, Abo-Zeitplan, Katalog und Bestellungen, Kundenportal mit Mandantentrennung, Backup, Verschlüsselung und Wiederherstellung).

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
| Kundenportal  | Verwaltung: `GET/POST/DELETE /api/clients/:id/portal`; Kunden (Header `X-Portal-Token`): `GET /api/portal/me`, `/api/portal/invoices`, `/api/portal/invoices/:id/pdf`, `/api/portal/quotes`, `/api/portal/quotes/:id/pdf`, `POST /api/portal/quotes/:id/accept|decline` |
| Katalog       | `GET/POST /api/categories`, `PATCH/DELETE /api/categories/:id`, `GET/POST /api/products`, `GET/PATCH/DELETE /api/products/:id`, `POST /api/products/:id/duplicate`, `POST /api/catalog/examples` |
| Bestellungen  | `GET/POST /api/orders`, `GET /api/orders/:id`, `POST /api/orders/:id/accept`, `POST /api/orders/:id/reject`; Portal: `GET /api/portal/products`, `GET/POST /api/portal/orders`, `POST /api/portal/orders/:id/cancel` |
| Kundenkonten  | Portal: `GET /api/portal/config`, `POST /api/portal/register`, `/verify-info`, `/verify`, `/login`, `/logout`, `/forgot`, `/reset`, `/password`; Verwaltung: `POST /api/portal-accounts/:id/active`, `/reset`, `DELETE /api/portal-accounts/:id` |
| Lizenzen      | `GET/POST /api/licenses`, `GET/PATCH/DELETE /api/licenses/:id`, `POST /api/licenses/:id/regenerate`, `POST /api/licenses/:id/send`; öffentlich: `POST /api/license/verify`, `GET /api/license/public-key`, `GET /license/client.php`; Portal: `GET /api/portal/licenses`, `POST /api/portal/licenses/:id/domain` |
| Support       | Team: `GET/POST /api/tickets`, `GET /api/tickets/stats|meta`, `GET/PATCH/DELETE /api/tickets/:id`, `POST /api/tickets/:id/messages` (multipart), `POST /api/tickets/bulk`, `GET /api/tickets/attachments/:id`, `/api/canned`, `/api/faq`; Portal: `GET/POST /api/portal/tickets`, `GET /api/portal/tickets/:id`, `POST …/messages|close|reopen|rating`, `GET /api/portal/attachments/:id`, `GET /api/portal/faq` |
| Einstellungen | `GET/PUT /api/settings/all`, `POST /api/settings/test-mail`, `POST /api/settings/database/test`, `POST /api/settings/database/switch` (nur Admin) |
| Backups       | `GET/POST /api/backups`, `GET/DELETE /api/backups/:name` (nur Admin) |
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
public/assets/          Weboberfläche (index.html, app.js, app.css) und Kundenportal (portal.html, portal.js)
src/App.php             Routen, CORS, Security-Header, Fehlerbehandlung
src/Controllers/        ein Controller je Bereich
src/Http/               Request, Response, Router, ApiError
src/Support/            DB (PDO), Validator, JWT, Auth, Rate-Limit, Rechnungsmathe
src/Pdf/                PDF-Schreiber, Layouts für Rechnung, Angebot und Mahnung
src/Mail/               SMTP-Client (STARTTLS/SSL, Anhänge)
src/Services/           Rechnungslogik, Mailvorlagen, Abo-Zeitplan, Portal-Zugang, Backups
database/migrations/    SQL-Migrationen (werden von bin/migrate.php angewendet)
bin/                    migrate.php, seed.php, seed-catalog.php, cron.php (Abos + Backup), backup.php, restore.php
uploads/                hochgeladene Dateien (außerhalb des Document Root)
tests/run.php           End-to-End-Tests
```

## Produktion

- Document Root auf `public/` setzen (Apache: `.htaccess` liegt bei; nginx: alle Requests an `index.php` weiterleiten und `Authorization` durchreichen)
- `JWT_SECRET` durch einen langen, zufälligen Wert ersetzen, `CORS_ORIGIN` auf die Frontend-URL setzen
- HTTPS erzwingen (Token laufen im Header); `database/` und `uploads/` müssen für den Webserver-Benutzer schreibbar sein
- Hinter einem Reverse-Proxy sieht das Rate-Limiting nur die Proxy-IP (`REMOTE_ADDR`) – dort ggf. selbst begrenzen
- Backup: die Datei `database/app.db` (SQLite im WAL-Modus: auch `-wal`/`-shm` mitsichern oder `sqlite3 app.db ".backup ..."` nutzen)
