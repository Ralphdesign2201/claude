-- Software-Updates: veröffentlichte Versionen (signierte ZIP-Pakete) und Replay-Schutz des Update-Servers

-- Bereits gesehene Anfrage-Zufallswerte: id = Zufallswert, createdAt in Unix-Sekunden
CREATE TABLE "UpdateNonce" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "createdAt" INTEGER NOT NULL
);

CREATE INDEX "UpdateNonce_created_idx" ON "UpdateNonce"("createdAt");

-- minFrom: Mindestversion, ab der dieses Paket direkt installierbar ist; ältere Installationen bekommen vorher die dazwischenliegende Version
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
    "minFrom" TEXT,
    "published" INTEGER NOT NULL DEFAULT 0 CHECK ("published" IN (0, 1)),
    "downloads" INTEGER NOT NULL DEFAULT 0,
    "releasedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE UNIQUE INDEX "Release_version_key" ON "Release"("product", "version");
