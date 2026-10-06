-- Lizenz-Berechtigungen (Paket, Funktionen, Support- und Update-Zeitraum), Nutzungsprotokoll, Replay-Schutz und Software-Releases

ALTER TABLE "Product" ADD COLUMN "licenseSlug" TEXT;
ALTER TABLE "Product" ADD COLUMN "licensePlan" TEXT;
ALTER TABLE "Product" ADD COLUMN "licenseFeatures" TEXT;
ALTER TABLE "Product" ADD COLUMN "licenseSupportDays" INTEGER CHECK ("licenseSupportDays" IS NULL OR "licenseSupportDays" >= 0);
ALTER TABLE "Product" ADD COLUMN "licenseUpdateDays" INTEGER CHECK ("licenseUpdateDays" IS NULL OR "licenseUpdateDays" >= 0);

ALTER TABLE "ProductOrder" ADD COLUMN "licensePlan" TEXT;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseFeatures" TEXT;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseSupportDays" INTEGER;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseUpdateDays" INTEGER;

ALTER TABLE "License" ADD COLUMN "slug" TEXT;
ALTER TABLE "License" ADD COLUMN "plan" TEXT;
ALTER TABLE "License" ADD COLUMN "features" TEXT;
ALTER TABLE "License" ADD COLUMN "supportDays" INTEGER;
ALTER TABLE "License" ADD COLUMN "updateDays" INTEGER;
ALTER TABLE "License" ADD COLUMN "supportUntil" TEXT;
ALTER TABLE "License" ADD COLUMN "updatesUntil" TEXT;

-- Auf welchen Domains wurde ein Schlüssel zuletzt benutzt (Hinweis auf Weitergabe)
CREATE TABLE "LicenseHost" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "licenseId" TEXT NOT NULL,
    "domain" TEXT NOT NULL,
    "version" TEXT,
    "checks" INTEGER NOT NULL DEFAULT 1,
    "firstSeenAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "lastSeenAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("licenseId") REFERENCES "License" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE UNIQUE INDEX "LicenseHost_unique" ON "LicenseHost"("licenseId", "domain");

-- Bereits gesehene Anfrage-Zufallswerte (Replay-Schutz): id = Zufallswert, createdAt in Unix-Sekunden
CREATE TABLE "LicenseNonce" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "createdAt" INTEGER NOT NULL
);

CREATE INDEX "LicenseNonce_created_idx" ON "LicenseNonce"("createdAt");

-- Veröffentlichte Software-Versionen (signierte ZIP-Pakete) für die Update-Funktion
CREATE TABLE "Release" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "product" TEXT NOT NULL DEFAULT 'crm',
    "version" TEXT NOT NULL,
    "channel" TEXT NOT NULL DEFAULT 'stable' CHECK ("channel" IN ('stable', 'beta')),
    "notes" TEXT,
    "fileName" TEXT NOT NULL,
    "size" INTEGER NOT NULL DEFAULT 0,
    "sha256" TEXT NOT NULL,
    "signature" TEXT NOT NULL,
    "minPhp" TEXT,
    "published" INTEGER NOT NULL DEFAULT 0 CHECK ("published" IN (0, 1)),
    "downloads" INTEGER NOT NULL DEFAULT 0,
    "releasedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE UNIQUE INDEX "Release_version_key" ON "Release"("product", "version");
