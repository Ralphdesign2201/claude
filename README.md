# Lokales Kanban

Kanban-Board, das komplett lokal läuft: kein Konto, keine Cloud, keine Netzwerkzugriffe (der Server lauscht nur auf 127.0.0.1).
Alle Daten liegen in `daten/kanban.db` (SQLite).

## Starten
Voraussetzung: Node.js 22.13 oder neuer. Keine weiteren Abhängigkeiten.

- Linux/Mac: `./start.sh`
- Windows: `start.bat` (Doppelklick)
- Dann im Browser: http://localhost:4545

Optionen (Umgebungsvariablen): `PORT`, `KANBAN_DATA` (Datenordner), `KANBAN_BACKUPS` (Anzahl Sicherungen, Standard 7).

## Stufe 1 (fertig)
Mehrere Boards (Name, Farbe, Symbol, Vorlagen, Duplizieren, Archivieren), Spalten (anlegen, umbenennen, verschieben,
einklappen, archivieren, WIP-Limit mit roter Markierung), Karten (Titel, Beschreibung, Priorität, Fälligkeit, Papierkorb),
Drag & Drop mit Maus und Touch (Karten und Spalten), globale Suche, tägliche und manuelle Sicherung, JSON-Export,
Hell/Dunkel. Kürzel: `n` neue Karte, `/` Suche, `b` neues Board.

## Geplant
Stufe 2: Checklisten, Labels, Zeiterfassung, Anhänge, Kommentare, Kalender, Auswertungen. Stufe 3: Netzwerk-Freigabe.
