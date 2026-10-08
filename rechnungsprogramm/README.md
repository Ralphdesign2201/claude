# Rechnungsprogramm für kleine Handwerksbetriebe

PHP 8 + SQLite, **ohne Framework und ohne Composer-Abhängigkeiten** – läuft auf jedem normalen Webspace.
Die PDF-Erzeugung ist eingebaut (kein Fremdcode, keine Bibliothek nötig).

## Funktionen
Dashboard (offen, überfällig, Zahlungseingang, Jahresumsatz) · Kundenverwaltung (Firma, Ansprechpartner, Telefon, E-Mail, Suche) ·
Rechnungen mit Positionen, Mengen, Einheiten, 19/7/0 % USt, automatischer Nummer (`RE-2026-0001`, je Jahr fortlaufend) ·
PDF nach DIN-5008-Aufbau mit Logo, Bankdaten und Fußzeile · Status offen/bezahlt/storniert (Storno-Stempel, Nummer bleibt belegt) ·
Rechnung kopieren · **Lieferscheine** (mit Unterschriftsfeld, aus Rechnung vorbefüllbar, per E-Mail) · **DATEV-Export** (Buchungsstapel EXTF mit Rechnungen, Stornos und Zahlungseingängen, frei einstellbare Konten) und Rechnungsliste als CSV · **XRechnung 3.0** (Leitweg-ID beim Kunden, für Behörden) · **E-Rechnung (ZUGFeRD/Factur-X, Profil EN 16931)**: jedes PDF ist ein PDF/A-3 mit eingebettetem XML, zusätzlich XML-Download ·
**Angebote** (eigener Nummernkreis, Gültigkeit, Status, mit einem Klick in Rechnung umwandeln) ·
**Mahnwesen** (Zahlungserinnerung, 1. und 2. Mahnung als PDF, Mahngebühren, optional Verzugszinsen) · **E-Mail-Versand** von Rechnung, Angebot und Mahnung mit PDF-Anhang (SMTP mit SSL/STARTTLS oder PHP `mail()`, Versandprotokoll, Kopie an sich selbst) · Kleinunternehmer-Modus (§ 19 UStG) · Anmeldung mit **Benutzern und frei definierbaren Rollen** (Rechte je Bereich: kein Zugriff / lesen / ändern) · **Backups** (manuell, automatisch per Cronjob täglich/wöchentlich/monatlich mit Höchstzahl, Download, Upload, Wiederherstellung) · **Umstellung SQLite ⇄ MySQL/MariaDB** in beide Richtungen.
Anschrift und Beträge werden pro Rechnung festgehalten; Kunden mit Rechnungen sind nicht löschbar.

## Installation auf dem Webspace
1. Ordner `rechnungsprogramm/` per FTP hochladen.
2. Ordner `storage/` beschreibbar machen (`chmod 775`, ggf. 777).
3. Im Browser aufrufen, den ersten Zugang (Administrator) anlegen, unter *Verwaltung → Einstellungen* Firmendaten, IBAN und Logo eintragen.

**Besser:** Document-Root der Domain auf `public/` stellen. Zeigt der Webspace auf den Projektordner, funktioniert es auch
(Wurzel-`index.php`); `app/` und `storage/` sind dann per `.htaccess` gesperrt (Apache/LiteSpeed).
**Bei nginx** (keine .htaccess) `app/` und `storage/` in der Serverkonfiguration sperren – oder Document-Root auf `public/` setzen.

Voraussetzungen: PHP ≥ 8.0 mit `pdo_sqlite` (Standard) oder `pdo_mysql`, und `mbstring`; `zlib` für komprimierte Backups; `gd` empfohlen (PNG-Logos werden automatisch in JPG gewandelt).

## Benutzer und Rollen
*Verwaltung → Benutzer / Rollen*. Mitgeliefert: **Administrator** (fest, darf alles), **Büro** (alles außer Benutzer und System) und **Lesezugriff**. Eigene Rollen lassen sich anlegen, ändern und löschen
(eine Rolle mit zugeordneten Benutzern nicht). Der letzte aktive Administrator kann weder gelöscht noch gesperrt werden. Jeder Benutzer ändert sein Passwort unter *Mein Konto*.
Anmeldeversuche werden pro IP gebremst (5 Fehlversuche → Wartezeit).

## Backups und Cronjob
*Verwaltung → Datenbank & Backups*: Backup erstellen, herunterladen, hochladen, wiederherstellen (mit Passwortabfrage; vorher entsteht automatisch eine Sicherheitskopie).
Ein Backup ist eine einzelne komprimierte Datei mit allen Daten und dem Logo und passt in SQLite **und** MySQL.
Automatisch: Intervall und *maximale Anzahl* aufbewahrter Backups einstellen und beim Hoster einen Cronjob anlegen, z. B. täglich 02:00 Uhr:
`php /pfad/zum/projekt/public/cron.php` oder als Webcron die auf der Seite angezeigte URL mit Token. Ohne Cronjob-Möglichkeit gibt es die Option „beim Seitenaufruf prüfen“.
Die Backup-Dateien liegen in `storage/backups/` – bitte zusätzlich regelmäßig extern sichern.

## Datenbank umstellen
Standard ist SQLite (nichts einzurichten). Für MySQL/MariaDB beim Hoster eine leere Datenbank samt Benutzer anlegen, die Zugangsdaten unter *Datenbank & Backups* eintragen, „Verbindung testen“, dann umstellen.
Alle Daten werden kopiert und zeilenweise gezählt, erst dann wird umgeschaltet; die alte Datenbank bleibt als Rückfall unverändert. Zurück auf SQLite geht ebenso.
Die Zugangsdaten stehen in `storage/config.php` (von außen nicht lesbar). Notfall: dort `'driver' => 'sqlite'` setzen.

## Lokal testen
`php -S localhost:8000 -t public`

## Aufbau
`public/` (index.php, cron.php, CSS/JS) · `app/` (bootstrap, helpers, db, dbtools, auth, pdf, pages, views) · `storage/` (SQLite-Datenbank, config.php, backups, Logo, Sessions)
· Tabellen: `customers`, `invoices`, `invoice_items`, `settings`. Beträge werden als Cent-Ganzzahlen gespeichert.

## E-Rechnung
Die eingebettete XML-Datei wurde mit dem Open-Source-Validator Mustang (Schema, EN-16931-Schematron, PDF/A-3 über veraPDF) geprüft: gültig.
Für die Kennung des Verkäufers (Pflichtangabe) bitte USt-IdNr. oder zumindest die Steuernummer in den Einstellungen eintragen.
Die Schrift Liberation Sans (SIL OFL, `app/fonts/`) wird eingebettet, daher ist ein PDF ca. 400 KB groß.

## DATEV
*Export* im Menü: Zeitraum wählen, Beraternummer, Mandantennummer und Konten eintragen (Voreinstellung SKR03), Datei an den Steuerberater geben.
Gebucht wird je Steuersatz Debitor an Erlöskonto (Automatikkonto). Das Format ist der DATEV-Buchungsstapel (EXTF 700, Kategorie 21); es wurde nicht gegen eine echte DATEV-Installation geprüft –
bitte beim ersten Export vom Steuerberater gegenprüfen lassen. Debitorenkonten (Basis + Kunden-Nr.) müssen dort angelegt sein.

## XRechnung
Beim Kunden die Leitweg-ID eintragen; an der Rechnung erscheint dann „XRechnung (XML)“. Pflichtangaben (Telefon, E-Mail, IBAN, Steuernummer/USt-IdNr.) werden vor dem Download geprüft. Das XML wurde mit Mustang validiert.
Die Übermittlung an das Behördenportal (z. B. ZRE/OZG-RE) erfolgt weiterhin durch Sie.

## Noch nicht enthalten
Eingang/Prüfung fremder E-Rechnungen, Skonto, Abschlags- und Schlussrechnungen.
