-- Kundenkonten (Registrierung mit Passwort) und Domain-Lizenzen

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

-- Lizenzoptionen am Produkt (nur für Einmal- und Mietprodukte)
ALTER TABLE "Product" ADD COLUMN "licenseEnabled" INTEGER NOT NULL DEFAULT 0 CHECK ("licenseEnabled" IN (0, 1));
ALTER TABLE "Product" ADD COLUMN "licenseSubdomains" INTEGER NOT NULL DEFAULT 0 CHECK ("licenseSubdomains" IN (0, 1));
ALTER TABLE "Product" ADD COLUMN "licensePayFirst" INTEGER NOT NULL DEFAULT 1 CHECK ("licensePayFirst" IN (0, 1));
ALTER TABLE "Product" ADD COLUMN "licenseDays" INTEGER CHECK ("licenseDays" IS NULL OR "licenseDays" > 0);

-- Schnappschuss der Lizenzoptionen und die gewünschte Domain in der Bestellung
ALTER TABLE "ProductOrder" ADD COLUMN "domain" TEXT;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseEnabled" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseSubdomains" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "ProductOrder" ADD COLUMN "licensePayFirst" INTEGER NOT NULL DEFAULT 1;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseDays" INTEGER;
ALTER TABLE "ProductOrder" ADD COLUMN "licenseId" TEXT;

-- status: PENDING = wartet auf Zahlung, ACTIVE, SUSPENDED = ausgesetzt, REVOKED = widerrufen.
-- „Abgelaufen“ wird aus validUntil abgeleitet. paidThrough = bezahlt bis (nur Miete), validUntil = paidThrough + Kulanzfrist.
CREATE TABLE "License" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "licenseKey" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "orderId" TEXT,
    "productId" TEXT,
    "productName" TEXT NOT NULL,
    "domain" TEXT NOT NULL,
    "subdomains" INTEGER NOT NULL DEFAULT 0 CHECK ("subdomains" IN (0, 1)),
    "status" TEXT NOT NULL DEFAULT 'ACTIVE' CHECK ("status" IN ('PENDING', 'ACTIVE', 'SUSPENDED', 'REVOKED')),
    "payFirst" INTEGER NOT NULL DEFAULT 1 CHECK ("payFirst" IN (0, 1)),
    "invoiceId" TEXT,
    "recurringId" TEXT,
    "intervalUnit" TEXT,
    "licenseDays" INTEGER,
    "activatedAt" TEXT,
    "paidThrough" TEXT,
    "validUntil" TEXT,
    "domainChanges" INTEGER NOT NULL DEFAULT 0,
    "lastCheckedAt" TEXT,
    "checkCount" INTEGER NOT NULL DEFAULT 0,
    "note" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("orderId") REFERENCES "ProductOrder" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("productId") REFERENCES "Product" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("invoiceId") REFERENCES "Invoice" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("recurringId") REFERENCES "Recurring" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE UNIQUE INDEX "License_licenseKey_key" ON "License"("licenseKey");
CREATE INDEX "License_clientId_idx" ON "License"("clientId");
CREATE INDEX "License_invoiceId_idx" ON "License"("invoiceId");
CREATE INDEX "License_recurringId_idx" ON "License"("recurringId");
CREATE INDEX "License_domain_idx" ON "License"("domain");

-- Welche bezahlte Rechnung hat welche Lizenz verlängert (jede Rechnung verlängert nur ein Mal)
CREATE TABLE "LicensePayment" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "licenseId" TEXT NOT NULL,
    "invoiceId" TEXT NOT NULL,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("licenseId") REFERENCES "License" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX "LicensePayment_unique" ON "LicensePayment"("licenseId", "invoiceId");

-- Fehlgeschlagene Prüfungen bekannter Lizenzen (z. B. falsche Domain): Hinweis auf Weitergabe
CREATE TABLE "LicenseAttempt" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "licenseId" TEXT NOT NULL,
    "domain" TEXT NOT NULL,
    "reason" TEXT NOT NULL,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("licenseId") REFERENCES "License" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX "LicenseAttempt_licenseId_idx" ON "LicenseAttempt"("licenseId", "createdAt");
