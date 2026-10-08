# Sicherheit – Maßnahmen und Betreiber-Checkliste

Kein Programm kann „absolute“ Sicherheit garantieren. Dieses Paket setzt die üblichen Schutzmaßnahmen konsequent um, wurde mit einer
automatischen Testsuite (siehe unten) geprüft und dokumentiert, was Sie als Betreiber selbst beitragen müssen.
Vor dem Verkauf bzw. Betrieb mit echten Kundendaten empfehlen wir zusätzlich einen **externen Penetrationstest**.

## Eingebaute Schutzmaßnahmen
**Anmeldung & Sitzungen**
- Passwörter mit `password_hash` (bcrypt/Standard), Passwortregeln (Länge, Liste häufiger Passwörter), Sperre des Kontos nach 5 Fehlversuchen (steigende Wartezeit) und Bremse pro IP.
- Zwei-Faktor-Anmeldung (TOTP, mit Wiederholungsschutz und Wiederherstellungscodes); für Superadmins standardmäßig verpflichtend.
- „Passwort vergessen“ mit Einmal-Link (nur Hash gespeichert, 60 Minuten, einmal nutzbar, ratenbegrenzt, neutrale Antworten).
- Sitzungen: Strict-Mode, `HttpOnly`, `SameSite=Lax`, `Secure` unter HTTPS, Leerlauf- (8 h) und Maximaldauer (24 h), Bindung an den Browser, neue Sitzungs-ID bei Anmeldung/Rechteänderung, Abmeldung nur per POST.
- Optional HTTPS-Erzwingung (Installer) und HSTS.

**Anfragen & Daten**
- Alle Schreibaktionen nur per POST mit CSRF-Token; Rechteprüfung auf dem Server je Route (Standard = verweigern), getestet über eine Rechte-Matrix.
- Ausgaben durchgängig HTML-escaped, strikte Content-Security-Policy (keine Inline-Skripte/-Styles), weitere Header (X-Frame-Options, Referrer-Policy, Permissions-Policy, `no-store`).
- Datenbankzugriffe ausschließlich über Prepared Statements; Eingaben (Beträge, Mengen, Datum, Textlängen) werden validiert; Array-/Typ-Manipulationen werden verworfen.
- Uploads: Logo wird neu kodiert (nur echte Bilder), Backups/Updates werden formal und per Signatur geprüft; Downloads nur über feste Namensmuster.
- Geheimnisse (SMTP-/Stripe-/PayPal-Zugangsdaten, 2FA-Schlüssel, DB-Passwort) werden mit libsodium verschlüsselt (`storage/secret.key`); Backups enthalten keine Geheimnisse.
- Links in E-Mails verwenden die beim Installieren festgelegte Basis-URL (Schutz vor Host-Header-Angriffen).
- Fehlermeldungen enthalten keine Interna; Details stehen in `storage/logs/`.

**Missbrauch & Spam**
- E-Mail-Versand nur nach bestätigter Adresse (SaaS), Limits (30/Stunde, 150/Tag je Betrieb), Header-Injection-Schutz.
- Registrierung: Honeypot, Zeitfalle, IP-Limits, Wegwerf-Adressen gesperrt, E-Mail-Bestätigung; Ratenbegrenzung für teure Endpunkte (PDF, Export, Backup).
- Webhooks von Stripe/PayPal: Signaturprüfung, Zeitfenster gegen Replays, Idempotenz; Zahlungsereignisse können eine Sperre nicht aufheben.

**Updates & Mandanten**
- Programm-Updates sind mit Ed25519 signiert; nur Pakete des Herstellers werden installiert; Pfad-Whitelist, Sicherung der ersetzten Dateien, automatischer Rollback.
- SaaS: jeder Mandant hat eine eigene Datenbankdatei; Mandantenwechsel nur über geprüfte Sitzung; Support-Zugriff (Impersonation) nur für Superadmins und für Mandanten sichtbar protokolliert.
- Sicherheitsprotokoll (Anmeldungen, Fehlversuche, Rollen/Benutzer, Backups, Updates, Support-Zugriff) für Mandanten und Superadmin.

## Checkliste für Betreiber
1. **HTTPS** einrichten und die Installation über `https://` durchführen (Option „HTTPS erzwingen“ aktiv lassen).
2. Document-Root auf `public/` stellen, sonst sicherstellen, dass `app/`, `storage/` nicht erreichbar sind (der Installer prüft das und warnt). Bei nginx: `location ~ ^/(app|storage)/ { deny all; }`.
3. `install.php` bzw. `superinstall.php` nach der Installation **löschen** (der Installer bietet es an) und die Installation unmittelbar nach dem Hochladen durchführen.
4. **2FA** für alle Administratoren aktivieren (Mein Konto → Zwei-Faktor-Anmeldung).
5. **Backups**: automatische Sicherung + Cronjob einrichten und regelmäßig eine Kopie **außerhalb** des Webspace aufbewahren; Wiederherstellung einmal testen.
6. **Updates** zeitnah einspielen (Verwaltung → Updates bzw. Superadmin → Updates).
7. Hinter einem Reverse-Proxy in `storage/config.php` `'trust_proxy' => true` setzen, damit IP-Bremsen die echte Besucher-IP sehen.
8. SaaS: Zahlungsanbieter zuerst im Test-/Sandbox-Modus prüfen; Webhooks einrichten; Rechtstexte (Impressum, Datenschutz, AGB, AV-Vertrag) rechtlich prüfen lassen.
9. PHP aktuell halten (≥ 8.1 empfohlen) und Dateirechte restriktiv setzen (`storage/` nur für den Webserver-Benutzer).

## Update-Signaturschlüssel (nur Hersteller)
Der **private** Schlüssel liegt in `tools/keys/update-signing.private`. Er signiert Update-Dateien. Wer ihn besitzt, kann Code auf allen Installationen einspielen.
- Repository privat halten, die Datei zusätzlich außerhalb des Repositories sichern und **nicht** in Kundenpakete aufnehmen (die Build-Skripte tun das nicht).
- Schlüsselwechsel: neues Paar erzeugen, `app/update_pubkey.php` ersetzen und mit einem letzten Update (noch mit dem alten Schlüssel signiert) ausliefern.

## Automatische Prüfungen (`tests/run_tests.py`)
`python3 tests/run_tests.py single single-mysql saas dbswitch update` startet temporäre Instanzen und prüft u. a.: Installer, Anmeldung/Sperren/2FA/Passwort-Reset, Rechte-Matrix, CSRF auf allen Schreibaktionen,
XSS- und SQL-Injection-Payloads, Betrags-Randfälle, parallele Rechnungserstellung, Backups/Restore, Datenbank-Umstellung, Update-Pakete (Signatur, Pfade, Rollback, kumulativ),
Mandanten-Isolation, Limits, Zugangssperren, Stripe-/PayPal-Abläufe mit Attrappen-Servern inkl. Webhook-Signaturen, Fuzzing aller Routen.
