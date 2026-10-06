-- Lizenzsystem entfernt: Nonce-Tabelle der Lizenzabfragen wird durch eine für den Update-Server ersetzt.
-- (Die Tabellen License und LicenseHost sowie die Lizenz-Spalten in Product, ProductOrder und Ticket bleiben aus Kompatibilitätsgründen
--  leer bzw. ungenutzt bestehen; sie werden vom Programm nicht mehr gelesen.)
DROP TABLE IF EXISTS "LicenseNonce";

CREATE TABLE "UpdateNonce" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "createdAt" INTEGER NOT NULL
);
