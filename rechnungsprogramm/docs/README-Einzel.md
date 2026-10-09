# HandwerkRechnung – Einzelinstallation

Rechnungsprogramm für Handwerksbetriebe: Kunden, Angebote, Rechnungen, Lieferscheine, Mahnwesen, E-Rechnung (ZUGFeRD/XRechnung),
DATEV-Export, PDF per E-Mail, Benutzer & Rollen, Backups, Updates. PHP 8 + SQLite oder MySQL – läuft auf jedem normalen Webspace.

## Installation
1. Alle Dateien per FTP in einen Ordner Ihres Webspace hochladen (Document-Root idealerweise auf `public/` stellen).
2. `storage/` beschreibbar machen (chmod 775).
3. Im Browser `https://ihre-domain.de/install.php` öffnen und dem Assistenten folgen (Firma, Administrator, Datenbank SQLite oder MySQL).
4. `install.php` löschen (der Assistent bietet es an).
5. Unter *Verwaltung → Einstellungen* Firmendaten, IBAN, Logo eintragen; unter *Datenbank & Backups* Backup-Intervall und Cronjob einrichten.

Voraussetzungen: PHP ≥ 8.0 mit `pdo_sqlite` oder `pdo_mysql`, `mbstring`; empfohlen `sodium` (Update-Signatur, Verschlüsselung), `zlib`, `gd`.

## Updates
*Verwaltung → Updates*: Update-Datei (`update-X.Y.rgu`) hochladen. Die Dateien sind **kumulativ** – sie enthalten alle Änderungen seit Version 1.0;
installiert wird nur, was Ihnen noch fehlt. Vorher entstehen automatisch eine Datensicherung und eine Dateisicherung, bei Fehlern wird zurückgesetzt.

## Sicherheit
Siehe `SECURITY.md` (Maßnahmen und Checkliste).


## Neu in 1.1
- **Leistungen & Artikel**: Menü *Leistungen & Artikel* (Katalog, CSV-Import/-Export). In Angeboten, Rechnungen und Lieferscheinen direkt im Beschreibungsfeld auswählen (Nummer oder Text tippen, Treffer anklicken).
- **Ansicht**: Einstellungen → Darstellung bzw. Profil → Ansicht: obere Leiste oder Seitenleiste.
