# Lokales Kanban

Kanban-Board, das komplett auf dem eigenen Rechner läuft: kein Konto, keine Cloud, keine Internetverbindung.
Alle Daten liegen in einer SQLite-Datei (`daten/kanban.db`), Anhänge daneben. Reines HTML/CSS/JavaScript ohne Abhängigkeiten;
der kleine Server läuft mit Node.js (22.13 oder neuer, SQLite ist eingebaut).

## Starten
- Linux/Mac: `./start.sh` · Windows: Doppelklick auf `start.bat` · oder `npm start`
- Der Browser öffnet `http://localhost:4545`. Beenden mit Strg+C (bei Verschlüsselung wird dabei gespeichert).
- Umgebungsvariablen: `PORT` (4545), `KANBAN_DATA` (Datenordner, Standard `./daten`), `KANBAN_FORCE=1` (Sperrdatei ignorieren).
- Tests: `npm test`

## Funktionen
**Boards:** mehrere Boards mit Name, Farbe, Symbol · Vorlagen (Leer, Webprojekt, Kunden-Pipeline, Persönlich, Redaktionsplan, eigene) · Duplizieren, Archivieren, Export · Swimlanes nach Person, Priorität oder Kunde (Karten per Ziehen zwischen Zeilen zuweisen).
**Spalten:** anlegen, umbenennen, verschieben, einklappen, archivieren · WIP-Limit (rot bei Überschreitung) · „Erledigt“-Spalte · Sortierung je Spalte (manuell, Datum, Priorität).
**Karten:** Markdown-Beschreibung (Werkzeugleiste, Strg+B/I/K, Listen-Fortsetzung, Vorschau) · mehrere Checklisten mit Fortschritt und sortierbaren Punkten · Start-/Fälligkeitsdatum mit Uhrzeit, Überfällig-Markierung · Priorität · Labels mit Farben · Zeitschätzung und Zeitnehmer (Start/Stopp, Nachtrag) · Anhänge (Ziehen, Einfügen, Vorschau von Bild/PDF/Text) · Kommentare (bearbeitbar) · Verknüpfungen (blockiert, gehört zu, Duplikat) · Unteraufgaben als eigene Karten · Wiederholung (täglich/wöchentlich/monatlich, neue Karte entsteht beim Erledigen) · Aktivitätsverlauf · Kopieren, Verschieben (auch in anderes Board), Archivieren, Papierkorb · Person, Kunde, CRM-Referenz.
**Bedienung:** Ziehen per Maus und Touch (Karten, Spalten, Checklistenpunkte; am Touchscreen langes Drücken) · Tastenkürzel (`?` zeigt alle) · Schnellerfassung (`q`, oder `/schnell.html` als eigenes Fenster) · Mehrfachauswahl (Strg+Klick, `x`) mit Sammelaktionen · Rückgängig/Wiederholen (Strg+Z / Strg+Y) · Hell/Dunkel · Kartengröße kompakt/bequem · Oberfläche auf Deutsch.
**Suchen & Ansichten:** globale Suche (Titel, Beschreibung, Kommentare, Checklisten) · Filter (Label, Priorität, Fälligkeit, Person, Kunde, Text), als Ansicht speicherbar · Liste/Tabelle, Kalender, Zeitleiste (Gantt-artig), Wochenplan (Termine per Ziehen verschieben).
**Überblick:** Dashboard (heute fällig, überfällig, in Arbeit, diese Woche erledigt) · Auswertung (Durchlaufzeit je Spalte, Durchsatz pro Woche, Burn-down, Zeit je Label und Kunde) · Zeitbericht mit CSV-Export · Erinnerungen als Systembenachrichtigung (am Tag, 1 Tag vorher, 1 Stunde vorher).
**Daten & Sicherheit:** tägliche automatische Sicherung mit einstellbarer Anzahl, optional zusätzlich in einen zweiten Ordner, Wiederherstellung im Programm · Import/Export: JSON (vollständig, optional mit Anhängen), CSV, Markdown · Import aus Trello (JSON) und CSV · Passwortschutz (Benutzer) · optionale Verschlüsselung (AES-256-GCM) von Datenbank, Anhängen und Sicherungen · keine Telemetrie; Einstellungen → Datenschutz zeigt Bindung, CSP und einen Selbsttest · Sperrdatei (`kanban.lock`): es schreibt immer nur ein Rechner.
**Optionen:** Freigabe im Heimnetz mit Benutzern, Zuweisung und Live-Aktualisierung (standardmäßig aus, Einstellungen → Netzwerk) · CRM-Link (URL-Vorlage mit `{ref}`) · Drucken/PDF von Board und Karte über den Druckdialog des Browsers.

## Wichtig zu wissen
- Verschlüsselung: Während das Programm läuft, liegt eine entschlüsselte Arbeitskopie im Temp-Ordner des Systems (wird beim Beenden gelöscht). Das Passwort lässt sich nicht wiederherstellen.
- Die Freigabe im Heimnetz ist unverschlüsselt (http). Nur in vertrauenswürdigen Netzen verwenden.
- Erinnerungen erscheinen, solange das Kanban-Fenster im Browser geöffnet ist.
- Mehrere Rechner: Datenordner per Sync-Dienst oder USB mitnehmen, nie gleichzeitig öffnen.

## Aufbau
`server.js` (HTTP, Sitzungen, Live-Ereignisse) · `server/` (Datenschicht, Verschlüsselung, API) · `public/` (Oberfläche, ES-Module in `public/js/`) · `test/api.test.js`.
