-- Kundenkonten (Registrierung mit Passwort)

-- Konto eines Kunden im Portal. clientId bleibt leer, bis die E-Mail-Adresse bestätigt ist
-- (erst dann wird der Kunde angelegt bzw. ein bestehender Kunde mit dieser Adresse verknüpft).
CREATE TABLE "PortalAccount" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "clientId" TEXT,
    "email" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "company" TEXT,
    "passwordHash" TEXT NOT NULL,
    "active" INTEGER NOT NULL DEFAULT 1 CHECK ("active" IN (0, 1)),
    "termsAcceptedAt" TEXT,
    "verifiedAt" TEXT,
    "verifyTokenHash" TEXT,
    "verifyExpiresAt" TEXT,
    "resetTokenHash" TEXT,
    "resetExpiresAt" TEXT,
    "lastLoginAt" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX "PortalAccount_email_key" ON "PortalAccount"("email");
CREATE INDEX "PortalAccount_clientId_idx" ON "PortalAccount"("clientId");
CREATE INDEX "PortalAccount_verify_idx" ON "PortalAccount"("verifyTokenHash");
CREATE INDEX "PortalAccount_reset_idx" ON "PortalAccount"("resetTokenHash");

-- LINK = persönlicher Zugangslink (vom Admin erstellt), SESSION = Anmeldung mit Konto
ALTER TABLE "PortalToken" ADD COLUMN "kind" TEXT NOT NULL DEFAULT 'LINK' CHECK ("kind" IN ('LINK', 'SESSION'));
ALTER TABLE "PortalToken" ADD COLUMN "accountId" TEXT REFERENCES "PortalAccount" ("id") ON DELETE CASCADE;
CREATE INDEX "PortalToken_accountId_idx" ON "PortalToken"("accountId");
