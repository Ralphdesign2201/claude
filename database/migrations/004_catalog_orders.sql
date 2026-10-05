-- Produktkatalog (Kategorien und Produkte) und Bestellungen

CREATE TABLE "Category" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "sortOrder" INTEGER NOT NULL DEFAULT 0,
    "active" INTEGER NOT NULL DEFAULT 1 CHECK ("active" IN (0, 1)),
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- type: ONE_TIME = einmaliger Kauf, RENTAL = Mietprodukt (wiederkehrend, price gilt je Zeitraum), HOURLY = Zeitprodukt (price = Stundensatz)
CREATE TABLE "Product" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "categoryId" TEXT,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "type" TEXT NOT NULL CHECK ("type" IN ('ONE_TIME', 'RENTAL', 'HOURLY')),
    "price" REAL NOT NULL DEFAULT 0 CHECK ("price" >= 0),
    "taxRate" REAL NOT NULL DEFAULT 19,
    "unit" TEXT,
    "intervalUnit" TEXT CHECK ("intervalUnit" IS NULL OR "intervalUnit" IN ('MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'YEARLY')),
    "setupFee" REAL NOT NULL DEFAULT 0 CHECK ("setupFee" >= 0),
    "minQuantity" REAL NOT NULL DEFAULT 1 CHECK ("minQuantity" > 0),
    "active" INTEGER NOT NULL DEFAULT 1 CHECK ("active" IN (0, 1)),
    "sortOrder" INTEGER NOT NULL DEFAULT 0,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("categoryId") REFERENCES "Category" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

-- Bestellung: Produktdaten werden zum Bestellzeitpunkt festgehalten, spätere Preisänderungen ändern sie nicht
CREATE TABLE "ProductOrder" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "number" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "productId" TEXT,
    "status" TEXT NOT NULL DEFAULT 'PENDING' CHECK ("status" IN ('PENDING', 'ACCEPTED', 'REJECTED', 'CANCELLED')),
    "source" TEXT NOT NULL DEFAULT 'PORTAL' CHECK ("source" IN ('PORTAL', 'ADMIN')),
    "productName" TEXT NOT NULL,
    "productType" TEXT NOT NULL,
    "unitPrice" REAL NOT NULL,
    "taxRate" REAL NOT NULL,
    "quantity" REAL NOT NULL CHECK ("quantity" > 0),
    "unit" TEXT,
    "intervalUnit" TEXT,
    "setupFee" REAL NOT NULL DEFAULT 0,
    "note" TEXT,
    "rejectReason" TEXT,
    "invoiceId" TEXT,
    "setupInvoiceId" TEXT,
    "recurringId" TEXT,
    "projectId" TEXT,
    "decidedAt" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("productId") REFERENCES "Product" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("invoiceId") REFERENCES "Invoice" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("setupInvoiceId") REFERENCES "Invoice" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("recurringId") REFERENCES "Recurring" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("projectId") REFERENCES "Project" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX "Category_sort_idx" ON "Category"("sortOrder", "name");
CREATE INDEX "Product_categoryId_idx" ON "Product"("categoryId");
CREATE INDEX "Product_active_idx" ON "Product"("active", "type");
CREATE UNIQUE INDEX "ProductOrder_number_key" ON "ProductOrder"("number");
CREATE INDEX "ProductOrder_clientId_idx" ON "ProductOrder"("clientId");
CREATE INDEX "ProductOrder_status_idx" ON "ProductOrder"("status");
