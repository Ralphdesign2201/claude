# Kundenverwaltung – Backend für Webdesigner

Professionelles REST-API-Backend zur Kunden-, Projekt-, Aufgaben- und Rechnungsverwaltung – speziell zugeschnitten auf Webdesigner/Freelancer und kleine Agenturen.

## Features

- **Auth**: Registrierung/Login mit JWT, Rollen (Admin/Mitarbeiter)
- **Kunden**: Firmen- & Kontaktdaten, Status (Lead/Aktiv/Inaktiv/Archiviert), Tags, mehrere Ansprechpartner
- **Projekte**: Status, Budget, Stundensatz, Start-/Fälligkeitsdatum, verknüpft mit Kunde
- **Aufgaben (Tasks)**: Kanban-fähig (Status, Priorität, Position, Fälligkeit, Zuweisung)
- **Zeiterfassung**: Zeiteinträge pro Projekt/Aufgabe, abrechenbar oder nicht
- **Rechnungen**: Positionen, Steuersatz, Rabatt, automatische Nummerierung (`RE-2026-0001`), Zahlungen, automatischer Status "Bezahlt"
- **Verträge**: Status, Laufzeit, Wert, Unterschrift
- **Notizen**: An Kunden oder Projekte anheftbar, pinnbar
- **Dokumente**: Datei-Upload (Verträge, Angebote, Briefings) via Multer
- **Dashboard**: Umsatz (bezahlt/offen/überfällig), aktive Projekte, offene Aufgaben, letzte Aktivitäten
- **Activity-Log**: Automatisches Protokoll wichtiger Änderungen je Kunde/Projekt
- **Benutzerverwaltung**: Admin kann Team-Mitglieder verwalten

## Tech-Stack

- Node.js + TypeScript + Express
- Prisma ORM (SQLite als Standard, per `DATABASE_URL` einfach auf PostgreSQL/MySQL umstellbar)
- JWT-Auth, bcrypt-Passwort-Hashing
- Zod-Validierung
- Helmet, CORS, Rate-Limiting

## Setup

```bash
npm install
cp .env.example .env   # ggf. anpassen (JWT_SECRET etc.)
npx prisma migrate dev --name init
npm run seed            # legt Demo-Admin + Beispielkunde an
npm run dev              # Server auf http://localhost:4000
```

Demo-Login nach `npm run seed`:

```
E-Mail:    admin@example.com
Passwort:  admin1234
```

## Wichtige Endpunkte

Alle Endpunkte (außer `/api/auth/*`) benötigen den Header `Authorization: Bearer <token>`.

| Bereich       | Endpunkt                                  |
|---------------|--------------------------------------------|
| Auth          | `POST /api/auth/register`, `POST /api/auth/login`, `GET /api/auth/me` |
| Kunden        | `GET/POST /api/clients`, `GET/PATCH/DELETE /api/clients/:id`, `POST /api/clients/:id/contacts` |
| Projekte      | `GET/POST /api/projects`, `GET/PATCH/DELETE /api/projects/:id`, `GET/POST /api/projects/:id/tasks` |
| Aufgaben      | `GET /api/tasks`, `PATCH/DELETE /api/tasks/:id` |
| Zeiterfassung | `GET/POST /api/time-entries`, `PATCH/DELETE /api/time-entries/:id` |
| Rechnungen    | `GET/POST /api/invoices`, `GET/PATCH/DELETE /api/invoices/:id`, `POST /api/invoices/:id/payments` |
| Verträge      | `GET/POST /api/contracts`, `GET/PATCH/DELETE /api/contracts/:id` |
| Notizen       | `GET/POST /api/notes`, `PATCH/DELETE /api/notes/:id` |
| Dokumente     | `GET /api/documents`, `POST /api/documents` (multipart `file`) |
| Dashboard     | `GET /api/dashboard/summary` |
| Benutzer      | `GET /api/users`, `PATCH/DELETE /api/users/:id` (nur Admin) |

Listen-Endpunkte unterstützen `?page=&pageSize=&search=&status=...` für Filter und Pagination.

## Produktion

- `DATABASE_URL` auf PostgreSQL umstellen und `provider = "postgresql"` in `prisma/schema.prisma` setzen
- `JWT_SECRET` durch einen langen, zufälligen Wert ersetzen
- `npm run build && npm start`
