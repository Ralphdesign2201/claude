# Rechnungsprogramm für kleine Handwerksbetriebe

PHP 8 + SQLite, **ohne Framework und ohne Composer-Abhängigkeiten** – läuft auf jedem normalen Webspace.
Die PDF-Erzeugung ist eingebaut (kein Fremdcode, keine Bibliothek nötig).

## Funktionen
Dashboard (offen, überfällig, Zahlungseingang, Jahresumsatz) · Kundenverwaltung (Firma, Ansprechpartner, Telefon, E-Mail, Suche) ·
Rechnungen mit Positionen, Mengen, Einheiten, 19/7/0 % USt, automatischer Nummer (`RE-2026-0001`, je Jahr fortlaufend) ·
PDF nach DIN-5008-Aufbau mit Logo, Bankdaten und Fußzeile · Status offen/bezahlt/storniert (Storno-Stempel, Nummer bleibt belegt) ·
Rechnung kopieren · **E-Rechnung (ZUGFeRD/Factur-X, Profil EN 16931)**: jedes PDF ist ein PDF/A-3 mit eingebettetem XML, zusätzlich XML-Download ·
**Angebote** (eigener Nummernkreis, Gültigkeit, Status, mit einem Klick in Rechnung umwandeln) ·
**Mahnwesen** (Zahlungserinnerung, 1. und 2. Mahnung als PDF, Mahngebühren, optional Verzugszinsen) · **E-Mail-Versand** von Rechnung, Angebot und Mahnung mit PDF-Anhang (SMTP mit SSL/STARTTLS oder PHP `mail()`, Versandprotokoll, Kopie an sich selbst) · Kleinunternehmer-Modus (§ 19 UStG) · Passwortschutz · Datenbank-Backup per Klick.
Anschrift und Beträge werden pro Rechnung festgehalten; Kunden mit Rechnungen sind nicht löschbar.

## Installation auf dem Webspace
1. Ordner `rechnungsprogramm/` per FTP hochladen.
2. Ordner `storage/` beschreibbar machen (`chmod 775`, ggf. 777).
3. Im Browser aufrufen, Passwort festlegen, unter *Einstellungen* Firmendaten, IBAN und Logo eintragen.

**Besser:** Document-Root der Domain auf `public/` stellen. Zeigt der Webspace auf den Projektordner, funktioniert es auch
(Wurzel-`index.php`); `app/` und `storage/` sind dann per `.htaccess` gesperrt (Apache/LiteSpeed).
**Bei nginx** (keine .htaccess) `app/` und `storage/` in der Serverkonfiguration sperren – oder Document-Root auf `public/` setzen.

Voraussetzungen: PHP ≥ 8.0 mit `pdo_sqlite` (Standard) und `mbstring`; `gd` empfohlen (PNG-Logos werden automatisch in JPG gewandelt).

## Lokal testen
`php -S localhost:8000 -t public`

## Aufbau
`public/` (index.php, CSS/JS) · `app/` (bootstrap, helpers, db, pdf, pages, views) · `storage/` (SQLite-Datenbank, Logo, Sessions)
· Tabellen: `customers`, `invoices`, `invoice_items`, `settings`. Beträge werden als Cent-Ganzzahlen gespeichert.

## E-Rechnung
Die eingebettete XML-Datei wurde mit dem Open-Source-Validator Mustang (Schema, EN-16931-Schematron, PDF/A-3 über veraPDF) geprüft: gültig.
Für die Kennung des Verkäufers (Pflichtangabe) bitte USt-IdNr. oder zumindest die Steuernummer in den Einstellungen eintragen.
Die Schrift Liberation Sans (SIL OFL, `app/fonts/`) wird eingebettet, daher ist ein PDF ca. 400 KB groß.

## Noch nicht enthalten
Lieferscheine, DATEV-Export, Eingang/Prüfung fremder E-Rechnungen, XRechnung (Behörden; Leitweg-ID).

## E-Mail einrichten
*Einstellungen → E-Mail-Versand*: SMTP-Server, Port, Benutzer und Passwort des Postfachs eintragen, speichern, dann „Testmail senden“.
SMTP ist zuverlässiger als `mail()` (landet seltener im Spam). Ohne SMTP-Daten wird `mail()` des Webspace genutzt, sofern der Hoster es erlaubt.
