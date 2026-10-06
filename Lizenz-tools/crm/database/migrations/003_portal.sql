-- Kundenportal: persönliche Zugangslinks (nur der Hash des Schlüssels wird gespeichert)
CREATE TABLE "PortalToken" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "clientId" TEXT NOT NULL,
    "tokenHash" TEXT NOT NULL,
    "expiresAt" TEXT,
    "lastUsedAt" TEXT,
    "revokedAt" TEXT,
    "createdBy" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("createdBy") REFERENCES "User" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE UNIQUE INDEX "PortalToken_tokenHash_key" ON "PortalToken"("tokenHash");
CREATE INDEX "PortalToken_clientId_idx" ON "PortalToken"("clientId");

-- Wann der Kunde ein Angebot im Portal angenommen oder abgelehnt hat
ALTER TABLE "Quote" ADD COLUMN "respondedAt" TEXT;
