-- Anmeldung auch mit Benutzernamen (neben der E-Mail-Adresse); gespeichert in Kleinbuchstaben

ALTER TABLE "User" ADD COLUMN "username" TEXT;
ALTER TABLE "PortalAccount" ADD COLUMN "username" TEXT;

CREATE UNIQUE INDEX "User_username_key" ON "User"("username");
CREATE UNIQUE INDEX "PortalAccount_username_key" ON "PortalAccount"("username");
