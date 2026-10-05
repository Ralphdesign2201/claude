-- Angebote, wiederkehrende Rechnungen (Abos), Mahnungen und E-Mail-Protokoll

CREATE TABLE "Recurring" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "clientId" TEXT NOT NULL,
    "projectId" TEXT,
    "title" TEXT NOT NULL,
    "intervalUnit" TEXT NOT NULL DEFAULT 'YEARLY' CHECK ("intervalUnit" IN ('MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'YEARLY')),
    "startDate" TEXT NOT NULL,
    "nextRunDate" TEXT NOT NULL,
    "endDate" TEXT,
    "occurrence" INTEGER NOT NULL DEFAULT 0,
    "active" INTEGER NOT NULL DEFAULT 1 CHECK ("active" IN (0, 1)),
    "autoSend" INTEGER NOT NULL DEFAULT 0 CHECK ("autoSend" IN (0, 1)),
    "paymentDays" INTEGER NOT NULL DEFAULT 14,
    "taxRate" REAL NOT NULL DEFAULT 19,
    "discount" REAL NOT NULL DEFAULT 0,
    "notes" TEXT,
    "currency" TEXT NOT NULL DEFAULT 'EUR',
    "lastRunAt" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("projectId") REFERENCES "Project" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE "RecurringItem" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "recurringId" TEXT NOT NULL,
    "description" TEXT NOT NULL,
    "quantity" REAL NOT NULL DEFAULT 1,
    "unitPrice" REAL NOT NULL,
    "position" INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY ("recurringId") REFERENCES "Recurring" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- Verweis von erzeugten Rechnungen auf ihr Abo
ALTER TABLE "Invoice" ADD COLUMN "recurringId" TEXT REFERENCES "Recurring" ("id") ON DELETE SET NULL;

CREATE TABLE "Quote" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "number" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "projectId" TEXT,
    "status" TEXT NOT NULL DEFAULT 'DRAFT' CHECK ("status" IN ('DRAFT', 'SENT', 'ACCEPTED', 'DECLINED', 'EXPIRED')),
    "issueDate" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "validUntil" TEXT,
    "taxRate" REAL NOT NULL DEFAULT 19,
    "discount" REAL NOT NULL DEFAULT 0,
    "notes" TEXT,
    "currency" TEXT NOT NULL DEFAULT 'EUR',
    "invoiceId" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("projectId") REFERENCES "Project" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("invoiceId") REFERENCES "Invoice" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE "QuoteItem" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "quoteId" TEXT NOT NULL,
    "description" TEXT NOT NULL,
    "quantity" REAL NOT NULL DEFAULT 1,
    "unitPrice" REAL NOT NULL,
    "position" INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY ("quoteId") REFERENCES "Quote" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE "Reminder" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "invoiceId" TEXT NOT NULL,
    "level" INTEGER NOT NULL CHECK ("level" BETWEEN 1 AND 3),
    "fee" REAL NOT NULL DEFAULT 0,
    "dueDate" TEXT NOT NULL,
    "toEmail" TEXT,
    "subject" TEXT,
    "message" TEXT NOT NULL,
    "emailedAt" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("invoiceId") REFERENCES "Invoice" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE "EmailLog" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "kind" TEXT NOT NULL CHECK ("kind" IN ('INVOICE', 'QUOTE', 'REMINDER')),
    "refId" TEXT NOT NULL,
    "toEmail" TEXT NOT NULL,
    "subject" TEXT NOT NULL,
    "status" TEXT NOT NULL CHECK ("status" IN ('SENT', 'FAILED')),
    "error" TEXT,
    "userId" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE UNIQUE INDEX "Quote_number_key" ON "Quote"("number");
CREATE INDEX "Quote_clientId_idx" ON "Quote"("clientId");
CREATE INDEX "Quote_status_idx" ON "Quote"("status");
CREATE INDEX "QuoteItem_quoteId_idx" ON "QuoteItem"("quoteId");
CREATE INDEX "Recurring_clientId_idx" ON "Recurring"("clientId");
CREATE INDEX "Recurring_due_idx" ON "Recurring"("active", "nextRunDate");
CREATE INDEX "RecurringItem_recurringId_idx" ON "RecurringItem"("recurringId");
CREATE INDEX "Invoice_recurringId_idx" ON "Invoice"("recurringId");
CREATE INDEX "Reminder_invoiceId_idx" ON "Reminder"("invoiceId");
CREATE INDEX "EmailLog_ref_idx" ON "EmailLog"("kind", "refId");
