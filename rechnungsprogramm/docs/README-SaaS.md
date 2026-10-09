# HandwerkRechnung – SaaS-Plattform (mit Superadmin)

Mehrmandanten-Betrieb: Kunden registrieren sich selbst, testen kostenlos und mieten per Kreditkarte (Stripe) oder PayPal.
Jeder Betrieb erhält eine eigene, getrennte Datenbank. Sie verwalten alles im Superadmin-Bereich.

## Installation
1. Dateien per FTP hochladen (Document-Root idealerweise `public/`), `storage/` beschreibbar machen.
2. `https://ihre-domain.de/superinstall.php` aufrufen: Produktname, Superadmin-Zugang (mind. 10 Zeichen), optional Basis-Domain für Subdomains.
3. `superinstall.php` löschen. Superadmin-Login: `https://ihre-domain.de/index.php?r=sa_login` – beim ersten Login Zwei-Faktor-Anmeldung einrichten.
4. **Cronjob (täglich)**: `php /pfad/zu/cron.php` oder die URL aus *Superadmin → Dashboard*. Er sichert alle Mandanten, aktualisiert Datenbanken nach Updates, verschickt Testphasen-Hinweise und räumt auf.

Voraussetzungen: PHP ≥ 8.0 mit `pdo_sqlite`, `mbstring`, `curl`, `openssl`, `sodium`; empfohlen `zlib`, `gd`.

## Einrichten (Superadmin → Einstellungen / Tarife)
- **Tarife** anlegen/ändern (Preis, monatlich/jährlich, Limits für Benutzer und Rechnungen pro Monat). Der Standard-Tarif gilt für neue Registrierungen.
- **Rechtstexte**: Impressum, Datenschutz, AGB eintragen (rechtlich prüfen lassen, inkl. AV-Vertrag).
- **E-Mail**: SMTP der Plattform (für Willkommens-, Bestätigungs- und Passwort-Mails; Mandanten ohne eigenen SMTP versenden darüber).
- **Kreditkarte (Stripe)**: Secret Key + Webhook-Signaturgeheimnis eintragen; im Stripe-Dashboard Webhook auf die angezeigte URL einrichten und Kundenportal aktivieren.
- **PayPal**: Client-ID, Secret, Webhook-ID eintragen; Modus zuerst *Sandbox*, nach erfolgreichem Test auf *Live*.
- Optional **Basis-Domain** (`*.ihre-domain.de`, Wildcard-DNS): Kunden melden sich dann unter `firma.ihre-domain.de` an; sonst per Firmen-ID im Login.

## Betrieb
- **Mandanten**: Status, Tarif, Testphase verlängern, sperren/freigeben, löschen, „Als Mandant anmelden“ (Support, für den Mandanten sichtbar protokolliert).
- **Zahlungen** werden automatisch über die Webhooks verbucht; abgelaufene Testphasen/Abos sperren den Zugang (Daten bleiben, Export bleibt möglich).
- **Updates**: *Superadmin → Updates* (kumulative, signierte `.rgu`-Datei). Mandanten-Datenbanken werden danach automatisch angepasst.

## Sicherheit
Siehe `SECURITY.md`.


## Neu in 1.1
- **Leistungen & Artikel**: Menü *Leistungen & Artikel* (Katalog, CSV-Import/-Export). In Angeboten, Rechnungen und Lieferscheinen über das Suchfeld „Leistung/Artikel aus Katalog“ übernehmen.
- **Ansicht**: Einstellungen → Darstellung bzw. Profil → Ansicht: obere Leiste oder Seitenleiste.
