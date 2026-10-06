-- Rechtstexte (Impressum, Datenschutzerklärung, AGB) und erweiterte Release-Verwaltung (öffentliche Releases, Mindestversion)

CREATE TABLE "LegalDocument" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "type" TEXT NOT NULL CHECK ("type" IN ('IMPRESSUM', 'DATENSCHUTZ', 'AGB')),
    "data" TEXT,
    "extra" TEXT,
    "override" TEXT,
    "published" INTEGER NOT NULL DEFAULT 0 CHECK ("published" IN (0, 1)),
    "version" INTEGER NOT NULL DEFAULT 1,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE UNIQUE INDEX "LegalDocument_type_key" ON "LegalDocument"("type");

-- access: 'public' = jede Installation (auch ohne Lizenz), 'licensed' = nur mit gültiger Lizenz
ALTER TABLE "Release" ADD COLUMN "access" TEXT NOT NULL DEFAULT 'licensed' CHECK ("access" IN ('public', 'licensed'));
-- Mindestversion, ab der dieses Paket direkt installierbar ist; ältere Installationen bekommen vorher die dazwischenliegende Version
ALTER TABLE "Release" ADD COLUMN "minFrom" TEXT;
