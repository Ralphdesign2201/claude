# Lizenz-Tools

Werkzeuge, um Software (zuerst das **CRM**) mit Lizenz, Support und Updates auszuliefern.

```
Lizenz-tools/
  crm/                 fertige, lizenzgeschützte Auslieferung des CRM (Ordner zum Hochladen / Weitergeben)
  dist/                daraus gebaute ZIP-Dateien für Kunden            (nicht im Git)
  releases/            Update-Pakete (ZIP), die du auf den Lizenzserver hochlädst   (nicht im Git)
  build-product.php    baut crm/ und dist/crm-<Version>.zip
  build-release.php    baut ein Update-Paket releases/crm-<Version>.zip
  vendor.json          Adresse deines Lizenzservers und sein öffentlicher Schlüssel (nicht geheim)
```

## Rollen

* **Lizenzserver** = deine eigene Installation des CRM (ohne `product.json`). Hier verkaufst du Lizenzen, verwaltest Kunden,
  Support-Tickets und lädst Releases hoch. Er signiert alle Antworten mit dem geheimen Schlüssel `database/license.key`
  (**sichern! nie weitergeben!**).
* **Produkt** = die Kopie in `crm/`, die deine Kunden bekommen. Sie enthält `product.json` (Server, öffentlicher Schlüssel) und
  prüft ihre Lizenz beim Lizenzserver.

## Einmal einrichten

1. Lizenzserver betreiben (HTTPS!). Unter `https://dein-server/api/license/public-key` steht der öffentliche Schlüssel.
2. Im Lizenzserver ein **Produkt mit Lizenz** anlegen (Produkte → Lizenz): Produkt-Kennung `crm`, Paket (Starter/Pro/Agency),
   Support- und Update-Dauer, ggf. „erst nach Zahlung freischalten“.
3. Produkt bauen:

   ```
   php Lizenz-tools/build-product.php crm --server=https://lizenz.deine-domain.de --key-file=database/license.key
   ```

   (`--key-file` leitet daraus nur den **öffentlichen** Schlüssel ab; alternativ `--public-key=BASE64`.)
   Ergebnis: Ordner `Lizenz-tools/crm/` und `Lizenz-tools/dist/crm-<Version>.zip`.

## Was die Lizenz kann

| | |
|---|---|
| **Paket** | Starter (Kernfunktionen), Pro (+ Abos, Shop, Support), Agency (+ Lizenzverkauf). Einzelne Funktionen lassen sich pro Lizenz überschreiben. |
| **Funktionen** | `support`, `shop`, `recurring`, `licenses`. Nicht enthaltene Funktionen sind in der Software gesperrt (403, im Menü ausgeblendet). |
| **Support** | Zeitraum pro Lizenz (Einmalkauf: ab Freischaltung, Miete: solange bezahlt). Ist er abgelaufen, können Kunden zu dieser Lizenz keine Tickets mehr eröffnen. |
| **Updates** | Zeitraum pro Lizenz. Eine Version darf installieren, wer bei deren **Erscheinen** noch Update-Anspruch hatte. |
| **Ungültige Lizenz** | Nur-Lesen-Modus: Daten bleiben les- und exportierbar (auch Backups), Änderungen sind gesperrt. Kulanzfrist bei Server-Ausfall (Standard 7 Tage). |

## Ein Update veröffentlichen

1. `VERSION` im Projekt hochzählen (z. B. `1.1.0`), Änderungen committen.
2. Paket bauen: `php Lizenz-tools/build-release.php crm` → `Lizenz-tools/releases/crm-1.1.0.zip`
3. Im Lizenzserver unter **Lizenzen → Releases & Updates** hochladen, Änderungstext eintragen, veröffentlichen.
   Der Server prüft das Paket und **signiert** es mit dem geheimen Schlüssel.
4. Kunden sehen es unter Einstellungen → Lizenz & Updates (oder `php bin/update.php --check`) und installieren es mit einem Klick.

Das Update lädt nur mit gültiger Lizenz und gültigem Update-Zeitraum (kurzlebiges Token), prüft SHA-256 **und** Signatur
mit dem eingebauten öffentlichen Schlüssel, erlaubt nur Dateien in `src/`, `public/`, `bin/`, `examples/`, `database/migrations/`
(nie `.env`, Datenbank, Uploads, Einstellungen, `product.json`), sichert den alten Code und macht bei jedem Fehler alles rückgängig.
Rücksicherung von Hand: `php bin/update.php --rollback=backups/code-vor-update-<Version>-<Zeit>.zip`.

### Verpasste Updates

Kunden pflegen ihre Installation unterschiedlich – manche überspringen Versionen. Das ist abgesichert:

* **Jedes Paket ist ein vollständiger Stand** (aller Code + alle Datenbank-Migrationen). Wer 0.1.0 hat und 0.4.0 installiert, überspringt 0.2/0.3 –
  die Migrationen holen alles Verpasste in der richtigen Reihenfolge nach. Ein Klick (oder ein `php bin/update.php --install`) genügt immer.
* **Mindestversion (`minFrom`)**: Nur wenn du einmal etwas baust, das zwingend einen Zwischenstand braucht, trägst du beim Hochladen eine
  Mindestversion ein. Ältere Installationen bekommen dann zuerst die höchste direkt erreichbare Version und danach automatisch (im selben Aufruf) die neueste.
* **Gesamtes Änderungsprotokoll**: Vor dem Installieren sieht der Kunde die Änderungen **aller** Versionen seit seiner eigenen.
* Die Update-Prüfung läuft einmal täglich im Cron und erscheint als gelber Hinweis im Dashboard.

### Öffentliche Releases (Beta ohne Lizenz)

Beim Hochladen eines Releases legst du fest, **wer es laden darf**: „Nur Kunden mit Update-Anspruch“ (Standard) oder „Jeder – auch ohne Lizenz“.
Öffentliche Releases lädt auch eine Installation ohne Lizenzschlüssel (`POST /api/license/update-public`, Antwort ebenfalls signiert, Paket mit Prüfsumme und Signatur).
So baust du die erste Beta ohne Lizenzpflicht und lieferst später Updates:

```
php Lizenz-tools/build-product.php crm --no-license --server=https://lizenz.deine-domain.de --key-file=database/license.key
```

Ergebnis: `Lizenz-tools/dist/crm-<Version>-ohne-lizenz.zip`. Danach pro neuer Version: `VERSION` hochzählen → `build-release.php crm` → hochladen mit Zugang **Jeder**.
Wichtig: Der Update-Server (`server`) und der öffentliche Schlüssel stehen in `product.json` der ausgelieferten Fassung – bitte gleich beim ersten Bauen angeben.

## Sicherheit der Schnittstelle

* Antworten des Lizenzservers sind mit **Ed25519** signiert (inkl. Zufallswert, Domain, Zeit) – eine gefälschte „gültig“-Antwort wird abgelehnt.
* Anfragen brauchen Zeitstempel (±5 Min.) und einen **einmaligen Zufallswert** (Replay-Schutz); HTTPS ist erzwungen (`LICENSE_REQUIRE_HTTPS`), HSTS wird gesendet.
* Bremsen: je IP, bei unbekannten Schlüsseln und **je Lizenz** (`LICENSE_MAX_CHECKS_HOUR`, Standard 120/Stunde).
* Im Lizenz-Detail siehst du, auf welchen Domains ein Schlüssel benutzt wurde (Hinweis auf Weitergabe) und welche Prüfungen abgelehnt wurden.
* Schlüsselwechsel: Die Software akzeptiert mehrere öffentliche Schlüssel (`publicKeys` in `product.json`); jede Antwort nennt die Schlüsselkennung `kid`.

## Ehrliche Grenzen

Eine Prüfung in PHP-Code, den der Kunde besitzt, kann jemand mit Programmierkenntnissen aus der Software entfernen.
Durchsetzen lässt sich deshalb nur, was **dein Server** liefert: signierte Updates, Support und die Berechtigungen aus der signierten
Antwort. Die Prüfung hält ehrliche Kunden bei der Sache und zeigt dir Weitergabe; sie ersetzt keine rechtlichen Lizenzbedingungen.
Ein Update-Fehler nach einer Datenbank-Migration lässt sich per Code-Rücknahme zurücksetzen, nicht aber automatisch in der Datenbank – deshalb
entsteht vor jedem Update ein Backup.
