-- Vollpaket (Erstinstallation mit install.php) zu einer Version, das Kunden mit gültiger Lizenz im Portal herunterladen können
ALTER TABLE "Release" ADD COLUMN "fullFileName" TEXT;
ALTER TABLE "Release" ADD COLUMN "fullSize" INTEGER;
ALTER TABLE "Release" ADD COLUMN "fullSha256" TEXT;
