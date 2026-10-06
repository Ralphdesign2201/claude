-- Support: Tickets, Nachrichten, Anhänge, Textbausteine, Hilfe-Artikel (FAQ)

CREATE TABLE "Ticket" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "number" TEXT NOT NULL,
    "clientId" TEXT NOT NULL,
    "subject" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'OPEN' CHECK ("status" IN ('OPEN', 'PENDING', 'ON_HOLD', 'RESOLVED', 'CLOSED')),
    "priority" TEXT NOT NULL DEFAULT 'NORMAL' CHECK ("priority" IN ('LOW', 'NORMAL', 'HIGH', 'URGENT')),
    "category" TEXT,
    "tags" TEXT,
    "source" TEXT NOT NULL DEFAULT 'PORTAL' CHECK ("source" IN ('PORTAL', 'ADMIN')),
    "assigneeId" TEXT,
    "licenseId" TEXT,
    "unreadStaff" INTEGER NOT NULL DEFAULT 1 CHECK ("unreadStaff" IN (0, 1)),
    "unreadCustomer" INTEGER NOT NULL DEFAULT 0 CHECK ("unreadCustomer" IN (0, 1)),
    "firstResponseAt" TEXT,
    "resolvedAt" TEXT,
    "closedAt" TEXT,
    "lastActivityAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "lastCustomerAt" TEXT,
    "lastStaffAt" TEXT,
    "rating" INTEGER CHECK ("rating" IS NULL OR ("rating" BETWEEN 1 AND 5)),
    "ratingComment" TEXT,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("clientId") REFERENCES "Client" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("assigneeId") REFERENCES "User" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("licenseId") REFERENCES "License" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE UNIQUE INDEX "Ticket_number_key" ON "Ticket"("number");
CREATE INDEX "Ticket_clientId_idx" ON "Ticket"("clientId");
CREATE INDEX "Ticket_status_idx" ON "Ticket"("status", "lastActivityAt");
CREATE INDEX "Ticket_assigneeId_idx" ON "Ticket"("assigneeId");

-- kind: CUSTOMER (Kunde), STAFF (Antwort an den Kunden), NOTE (interne Notiz), EVENT (Verlauf, intern)
CREATE TABLE "TicketMessage" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "ticketId" TEXT NOT NULL,
    "kind" TEXT NOT NULL CHECK ("kind" IN ('CUSTOMER', 'STAFF', 'NOTE', 'EVENT')),
    "authorId" TEXT,
    "authorName" TEXT NOT NULL DEFAULT '',
    "body" TEXT NOT NULL,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("ticketId") REFERENCES "Ticket" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("authorId") REFERENCES "User" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX "TicketMessage_ticketId_idx" ON "TicketMessage"("ticketId", "createdAt");

CREATE TABLE "TicketAttachment" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "ticketId" TEXT NOT NULL,
    "messageId" TEXT NOT NULL,
    "fileName" TEXT NOT NULL,
    "storedName" TEXT NOT NULL,
    "mimeType" TEXT NOT NULL,
    "size" INTEGER NOT NULL DEFAULT 0,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY ("ticketId") REFERENCES "Ticket" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("messageId") REFERENCES "TicketMessage" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX "TicketAttachment_messageId_idx" ON "TicketAttachment"("messageId");
CREATE INDEX "TicketAttachment_ticketId_idx" ON "TicketAttachment"("ticketId");

CREATE TABLE "CannedResponse" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "title" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE TABLE "FaqArticle" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "title" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "category" TEXT,
    "published" INTEGER NOT NULL DEFAULT 1 CHECK ("published" IN (0, 1)),
    "sortOrder" INTEGER NOT NULL DEFAULT 0,
    "createdAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    "updatedAt" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
