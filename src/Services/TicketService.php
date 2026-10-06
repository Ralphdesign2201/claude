<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\DocumentsController;
use App\Http\ApiError;
use App\Http\Request;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use DateTimeImmutable;
use DateTimeZone;

/** Support-Tickets: Anlegen, Antworten, Zuweisen, SLA, Anhänge, Benachrichtigungen. */
final class TicketService
{
    public const STATUSES = ['OPEN' => 'Offen', 'PENDING' => 'Wartet auf Kunde', 'ON_HOLD' => 'Zurückgestellt', 'RESOLVED' => 'Gelöst', 'CLOSED' => 'Geschlossen'];
    public const PRIORITIES = ['LOW' => 'Niedrig', 'NORMAL' => 'Normal', 'HIGH' => 'Hoch', 'URGENT' => 'Dringend'];
    public const OPEN_STATUSES = ['OPEN', 'PENDING', 'ON_HOLD'];
    private const SLA_FACTOR = ['URGENT' => 0.25, 'HIGH' => 0.5, 'NORMAL' => 1.0, 'LOW' => 2.0];
    private const MAX_FILES = 5;
    /** Erlaubte Anhänge (keine ausführbaren oder aktiven Inhalte wie html, svg, php) */
    private const FILE_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'log' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        $raw = Env::get('TICKET_CATEGORIES', '') ?: 'Allgemein,Rechnung & Zahlung,Technik,Bestellung';
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn ($c) => $c !== '')));
    }

    /* ---------- SLA ---------- */

    /** @return array{firstDueAt:?string,resolveDueAt:?string,overdue:bool,firstOverdue:bool,resolveOverdue:bool} */
    public static function sla(array $t): array
    {
        $factor = self::SLA_FACTOR[$t['priority']] ?? 1.0;
        $created = new DateTimeImmutable($t['createdAt']);
        $firstH = max(1, Env::int('TICKET_FIRST_RESPONSE_HOURS', 24)) * $factor;
        $resolveH = max(1, Env::int('TICKET_RESOLVE_HOURS', 72)) * $factor;
        $now = Dates::now();
        $isOpen = $t['status'] === 'OPEN';
        $firstDue = $t['firstResponseAt'] === null && in_array($t['status'], self::OPEN_STATUSES, true)
            ? $created->modify('+' . (int) round($firstH * 60) . ' minutes')->format(Dates::FORMAT) : null;
        $resolveDue = in_array($t['status'], self::OPEN_STATUSES, true) ? $created->modify('+' . (int) round($resolveH * 60) . ' minutes')->format(Dates::FORMAT) : null;
        // „Wartet auf Kunde“ und „Zurückgestellt“ stoppen die Uhr
        $firstOver = $isOpen && $firstDue !== null && $firstDue < $now;
        $resolveOver = $isOpen && $resolveDue !== null && $resolveDue < $now;
        return ['firstDueAt' => $firstDue, 'resolveDueAt' => $resolveDue, 'overdue' => $firstOver || $resolveOver, 'firstOverdue' => $firstOver, 'resolveOverdue' => $resolveOver];
    }

    /* ---------- Anlegen und Antworten ---------- */

    /**
     * @param array{source?:string,priority?:string,category?:?string,tags?:?string,assigneeId?:?string,files?:list<array<string,mixed>>,notifyCustomer?:bool} $opts
     * @param array<string,mixed>|null $staff angemeldeter Mitarbeiter bei Erfassung im Admin
     * @return array<string,mixed>
     */
    public static function create(string $clientId, string $subject, string $body, array $opts, ?array $staff = null): array
    {
        $client = Db::require('Client', $clientId, 'Kunde nicht gefunden');
        $source = $opts['source'] ?? 'PORTAL';
        $files = self::checkFiles($opts['files'] ?? []);
        $now = Dates::now();

        $ticketId = Db::transaction(static function () use ($client, $subject, $body, $opts, $staff, $source, $files, $now) {
            $number = Numbering::next('Ticket');
            $assignee = $opts['assigneeId'] ?? null;
            $id = Db::insert('Ticket', [
                'number' => $number, 'clientId' => $client['id'], 'subject' => $subject,
                'priority' => $opts['priority'] ?? 'NORMAL', 'category' => $opts['category'] ?? null, 'tags' => $opts['tags'] ?? null,
                'source' => $source, 'assigneeId' => $assignee,
                'unreadStaff' => $source === 'PORTAL' ? 1 : 0, 'lastActivityAt' => $now, 'lastCustomerAt' => $now,
            ]);
            $msgId = Db::insert('TicketMessage', [
                'createdAt' => Dates::now(), 'ticketId' => $id, 'kind' => 'CUSTOMER', 'authorId' => $source === 'ADMIN' ? ($staff['id'] ?? null) : null,
                'authorName' => $client['name'], 'body' => $body,
            ]);
            self::storeFiles($id, $msgId, $files);
            if ($source === 'ADMIN') {
                self::event($id, 'Ticket von ' . ($staff['name'] ?? 'Mitarbeiter') . ' im Namen des Kunden erfasst');
            }
            Activity::log('TICKET_CREATED', "Ticket $number: $subject", $client['id'], null, $staff['id'] ?? null);
            return $id;
        });

        $ticket = Db::require('Ticket', $ticketId, 'Ticket nicht gefunden');
        if ($source === 'PORTAL') {
            self::mail(MailTemplates::recipient($client), MailTemplates::ticketCreated($client, $ticket));
            self::notifyStaff($ticket, $client, MailTemplates::ticketNotify($client, $ticket, $body, true));
        } elseif ($opts['notifyCustomer'] ?? false) {
            self::mail(MailTemplates::recipient($client), MailTemplates::ticketCreated($client, $ticket));
        }
        return $ticket;
    }

    /**
     * Fügt eine Nachricht hinzu.
     *  CUSTOMER – Antwort des Kunden (öffnet das Ticket wieder), STAFF – Antwort an den Kunden, NOTE – interne Notiz.
     *
     * @param array<string,mixed>|null $staff
     * @param list<array<string,mixed>> $files
     * @return string ID der Nachricht
     */
    public static function addMessage(string $ticketId, string $kind, string $body, ?array $staff, array $files = [], ?string $newStatus = null): string
    {
        $ticket = Db::require('Ticket', $ticketId, 'Ticket nicht gefunden');
        $client = Db::require('Client', $ticket['clientId'], 'Kunde nicht gefunden');
        $files = self::checkFiles($files);
        if ($newStatus !== null && !isset(self::STATUSES[$newStatus])) {
            throw ApiError::badRequest('Ungültiger Status');
        }
        $now = Dates::now();
        $wasClosed = in_array($ticket['status'], ['RESOLVED', 'CLOSED'], true);

        $msgId = Db::transaction(function () use ($ticket, $client, $kind, $body, $staff, $files, $newStatus, $now, $wasClosed) {
            $name = $kind === 'CUSTOMER' ? $client['name'] : ($staff['name'] ?? 'Support');
            $msgId = Db::insert('TicketMessage', [
                'createdAt' => Dates::now(), 'ticketId' => $ticket['id'], 'kind' => $kind, 'authorId' => $kind === 'CUSTOMER' ? null : ($staff['id'] ?? null),
                'authorName' => $name, 'body' => $body,
            ]);
            self::storeFiles($ticket['id'], $msgId, $files);

            $update = ['lastActivityAt' => $now];
            if ($kind === 'CUSTOMER') {
                $update += ['unreadStaff' => 1, 'lastCustomerAt' => $now];
                if ($ticket['status'] !== 'OPEN') {
                    $update += ['status' => 'OPEN', 'resolvedAt' => null, 'closedAt' => null];
                    self::event($ticket['id'], 'Status automatisch auf „Offen“ gesetzt (Antwort des Kunden)');
                }
            } elseif ($kind === 'STAFF') {
                $update += ['unreadCustomer' => 1, 'unreadStaff' => 0, 'lastStaffAt' => $now];
                if ($ticket['firstResponseAt'] === null) {
                    $update['firstResponseAt'] = $now;
                }
            } else {
                $update['unreadStaff'] = 0;
            }
            if ($kind !== 'CUSTOMER' && $newStatus !== null && $newStatus !== $ticket['status']) {
                $update += self::statusFields($newStatus, $ticket);
                self::event($ticket['id'], 'Status: ' . self::STATUSES[$ticket['status']] . ' → ' . self::STATUSES[$newStatus] . ' (von ' . ($staff['name'] ?? 'Mitarbeiter') . ')');
            }
            Db::update('Ticket', $ticket['id'], $update);
            return $msgId;
        });

        $fresh = Db::require('Ticket', $ticket['id'], 'Ticket nicht gefunden');
        if ($kind === 'STAFF') {
            self::mail(MailTemplates::recipient($client), MailTemplates::ticketReply($client, $fresh, $body, $fresh['status'] === 'RESOLVED'));
        } elseif ($kind === 'CUSTOMER') {
            self::notifyStaff($fresh, $client, MailTemplates::ticketNotify($client, $fresh, $body, false, $wasClosed));
        }
        return $msgId;
    }

    /**
     * Ändert Felder eines Tickets (nur Mitarbeiter) und protokolliert jede Änderung im Verlauf.
     *
     * @param array<string,mixed> $changes subject, status, priority, category, tags, assigneeId
     * @param array<string,mixed> $staff
     */
    public static function update(string $ticketId, array $changes, array $staff): array
    {
        $t = Db::require('Ticket', $ticketId, 'Ticket nicht gefunden');
        $set = [];
        $log = [];
        $who = $staff['name'];
        $assigned = null;

        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'status':
                    if ($value !== $t['status']) {
                        $set += self::statusFields($value, $t);
                        $log[] = 'Status: ' . self::STATUSES[$t['status']] . ' → ' . self::STATUSES[$value];
                    }
                    break;
                case 'priority':
                    if ($value !== $t['priority']) {
                        $set['priority'] = $value;
                        $log[] = 'Priorität: ' . self::PRIORITIES[$t['priority']] . ' → ' . self::PRIORITIES[$value];
                    }
                    break;
                case 'assigneeId':
                    if (($value ?? null) !== $t['assigneeId']) {
                        $name = null;
                        if ($value !== null) {
                            $u = Db::find('User', (string) $value) ?? throw ApiError::badRequest('Mitarbeiter nicht gefunden');
                            $name = $u['name'];
                            $assigned = $u;
                        }
                        $set['assigneeId'] = $value;
                        $log[] = $name === null ? 'Zuweisung aufgehoben' : "Zugewiesen an $name";
                    }
                    break;
                case 'category':
                case 'tags':
                    if (($value ?? null) !== $t[$field]) {
                        $set[$field] = $value;
                        $log[] = ($field === 'category' ? 'Kategorie' : 'Stichworte') . ': ' . ($value ?? '–');
                    }
                    break;
                case 'subject':
                    if ($value !== $t['subject']) {
                        $set['subject'] = $value;
                        $log[] = 'Betreff geändert';
                    }
                    break;
            }
        }
        if ($set === []) {
            return $t;
        }
        Db::transaction(static function () use ($ticketId, $set, $log, $who) {
            Db::update('Ticket', $ticketId, $set + ['lastActivityAt' => Dates::now()]);
            foreach ($log as $line) {
                self::event($ticketId, "$line (von $who)");
            }
        });
        if ($assigned !== null && $assigned['id'] !== $staff['id']) {
            $client = Db::require('Client', $t['clientId'], 'Kunde nicht gefunden');
            self::mail((string) $assigned['email'], MailTemplates::ticketAssigned($assigned, Db::require('Ticket', $ticketId, 'Ticket nicht gefunden'), $client, $who));
        }
        return Db::require('Ticket', $ticketId, 'Ticket nicht gefunden');
    }

    /** @return array<string,mixed> */
    private static function statusFields(string $status, array $t): array
    {
        if (!isset(self::STATUSES[$status])) {
            throw ApiError::badRequest('Ungültiger Status');
        }
        $now = Dates::now();
        $f = ['status' => $status];
        if ($status === 'RESOLVED') {
            $f += ['resolvedAt' => $now, 'closedAt' => null];
        } elseif ($status === 'CLOSED') {
            $f += ['closedAt' => $now, 'resolvedAt' => $t['resolvedAt'] ?? $now];
        } else {
            $f += ['resolvedAt' => null, 'closedAt' => null];
        }
        return $f;
    }

    /** Aktionen des Kunden im Portal. */
    public static function customerAction(string $ticketId, string $clientId, string $action): array
    {
        $t = self::ownTicket($ticketId, $clientId);
        $target = $action === 'close' ? 'CLOSED' : 'OPEN';
        if ($action === 'reopen' && !in_array($t['status'], ['RESOLVED', 'CLOSED'], true)) {
            throw ApiError::conflict('Das Ticket ist bereits offen');
        }
        if ($t['status'] === $target) {
            return $t;
        }
        Db::transaction(static function () use ($t, $target, $action) {
            Db::update('Ticket', $t['id'], self::statusFields($target, $t) + ['lastActivityAt' => Dates::now(), 'unreadStaff' => 1]);
            self::event($t['id'], $action === 'close' ? 'Vom Kunden geschlossen' : 'Vom Kunden wieder geöffnet');
        });
        return Db::require('Ticket', $t['id'], 'Ticket nicht gefunden');
    }

    public static function rate(string $ticketId, string $clientId, int $rating, string $comment): array
    {
        $t = self::ownTicket($ticketId, $clientId);
        if (!in_array($t['status'], ['RESOLVED', 'CLOSED'], true)) {
            throw ApiError::conflict('Bewerten ist erst möglich, wenn das Ticket gelöst ist');
        }
        Db::update('Ticket', $t['id'], ['rating' => $rating, 'ratingComment' => $comment !== '' ? $comment : null, 'unreadStaff' => 1]);
        self::event($t['id'], "Bewertung durch den Kunden: $rating von 5 Sternen");
        return Db::require('Ticket', $t['id'], 'Ticket nicht gefunden');
    }

    /** @return array<string,mixed> */
    public static function ownTicket(string $ticketId, string $clientId): array
    {
        $t = Db::find('Ticket', $ticketId);
        if ($t === null || $t['clientId'] !== $clientId) {
            throw ApiError::notFound('Ticket nicht gefunden');
        }
        return $t;
    }

    private static function event(string $ticketId, string $text): void
    {
        Db::insert('TicketMessage', ['createdAt' => Dates::now(), 'ticketId' => $ticketId, 'kind' => 'EVENT', 'authorName' => '', 'body' => $text]);
    }

    /* ---------- Anzeige ---------- */

    /**
     * Ticket mit Nachrichten und Anhängen.
     *
     * @return array<string,mixed>
     */
    public static function detail(string $ticketId, bool $forStaff): array
    {
        $t = Db::require('Ticket', $ticketId, 'Ticket nicht gefunden');
        $kinds = $forStaff ? ['CUSTOMER', 'STAFF', 'NOTE', 'EVENT'] : ['CUSTOMER', 'STAFF'];
        $messages = Db::all(
            'SELECT * FROM "TicketMessage" WHERE "ticketId" = ? AND "kind" IN (' . Db::in($kinds) . ') ORDER BY "createdAt" ASC, "id" ASC',
            [$ticketId, ...$kinds],
        );
        $att = [];
        foreach (Db::all('SELECT "id", "messageId", "fileName", "mimeType", "size" FROM "TicketAttachment" WHERE "ticketId" = ? ORDER BY "createdAt" ASC', [$ticketId]) as $a) {
            $att[$a['messageId']][] = ['id' => $a['id'], 'fileName' => $a['fileName'], 'mimeType' => $a['mimeType'], 'size' => (int) $a['size']];
        }
        foreach ($messages as &$m) {
            $m['attachments'] = $att[$m['id']] ?? [];
            if (!$forStaff) {
                // Dem Kunden nur den Vornamen der Mitarbeiter zeigen
                $m['authorName'] = $m['kind'] === 'STAFF' ? (explode(' ', trim($m['authorName']))[0] ?: 'Support') : $m['authorName'];
                unset($m['authorId']);
            }
        }
        unset($m);

        $t['messages'] = $messages;
        unset($t['licenseId']); // Spalte des früheren Lizenzsystems
        if ($forStaff) {
            $t['client'] = Db::one('SELECT "id", "name", "company", "email", "phone" FROM "Client" WHERE "id" = ?', [$t['clientId']]);
            $t['assignee'] = $t['assigneeId'] ? Db::one('SELECT "id", "name" FROM "User" WHERE "id" = ?', [$t['assigneeId']]) : null;
            $t['sla'] = self::sla($t);
            $t['firstResponseMinutes'] = $t['firstResponseAt'] ? (int) round((strtotime($t['firstResponseAt']) - strtotime($t['createdAt'])) / 60) : null;
            $t['related'] = Db::all('SELECT "id", "number", "subject", "status" FROM "Ticket" WHERE "clientId" = ? AND "id" != ? ORDER BY "createdAt" DESC LIMIT 5', [$t['clientId'], $ticketId]);
        } else {
            unset($t['assigneeId'], $t['unreadStaff'], $t['tags'], $t['source'], $t['lastCustomerAt'], $t['lastStaffAt'], $t['firstResponseAt']);
        }
        return $t;
    }

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $byStatus = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (Db::all('SELECT "status", COUNT(*) AS n FROM "Ticket" GROUP BY "status"') as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }
        $open = Db::all('SELECT * FROM "Ticket" WHERE "status" = \'OPEN\'');
        $overdue = count(array_filter($open, static fn ($t) => self::sla($t)['overdue']));
        $since = gmdate(Dates::FORMAT, time() - 30 * 86400);
        $resp = Db::all('SELECT "createdAt", "firstResponseAt" FROM "Ticket" WHERE "firstResponseAt" IS NOT NULL AND "createdAt" >= ?', [$since]);
        $mins = array_map(static fn ($r) => (strtotime($r['firstResponseAt']) - strtotime($r['createdAt'])) / 60, $resp);
        $rating = Db::value('SELECT AVG("rating") FROM "Ticket" WHERE "rating" IS NOT NULL AND "createdAt" >= ?', [$since]);
        return [
            'byStatus' => $byStatus,
            'open' => $byStatus['OPEN'] + $byStatus['PENDING'] + $byStatus['ON_HOLD'],
            'needsReply' => (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "status" = \'OPEN\' AND "unreadStaff" = 1'),
            'unassigned' => (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "status" IN (\'OPEN\', \'PENDING\', \'ON_HOLD\') AND "assigneeId" IS NULL'),
            'overdue' => $overdue,
            'avgFirstResponseMinutes' => $mins === [] ? null : (int) round(array_sum($mins) / count($mins)),
            'avgRating' => $rating === null ? null : round((float) $rating, 1),
            'ratings' => (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "rating" IS NOT NULL AND "createdAt" >= ?', [$since]),
            'created30' => (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "createdAt" >= ?', [$since]),
            'resolved30' => (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "resolvedAt" >= ?', [$since]),
            'byCategory' => Db::all('SELECT COALESCE("category", \'Ohne Kategorie\') AS category, COUNT(*) AS n FROM "Ticket" WHERE "createdAt" >= ? GROUP BY COALESCE("category", \'Ohne Kategorie\') ORDER BY n DESC LIMIT 8', [$since]),
        ];
    }

    /** Nachrichten, die auf eine Antwort des Teams warten (für das Menü-Zeichen). */
    public static function newCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM "Ticket" WHERE "unreadStaff" = 1 AND "status" != \'CLOSED\'');
    }

    /**
     * Täglich per Cron: gelöste Tickets nach TICKET_AUTOCLOSE_DAYS schließen, unbeantwortete „Wartet auf Kunde“ nach
     * TICKET_PENDING_DAYS als gelöst markieren.
     *
     * @return array{closed:int,resolved:int}
     */
    public static function autoClose(): array
    {
        $closeDays = Env::int('TICKET_AUTOCLOSE_DAYS', 7);
        $pendingDays = Env::int('TICKET_PENDING_DAYS', 14);
        $closed = 0;
        $resolved = 0;
        if ($closeDays > 0) {
            $cut = gmdate(Dates::FORMAT, time() - $closeDays * 86400);
            foreach (Db::all('SELECT * FROM "Ticket" WHERE "status" = \'RESOLVED\' AND "lastActivityAt" < ?', [$cut]) as $t) {
                Db::update('Ticket', $t['id'], self::statusFields('CLOSED', $t));
                self::event($t['id'], "Automatisch geschlossen ($closeDays Tage nach „Gelöst“ ohne Rückmeldung)");
                $closed++;
            }
        }
        if ($pendingDays > 0) {
            $cut = gmdate(Dates::FORMAT, time() - $pendingDays * 86400);
            foreach (Db::all('SELECT * FROM "Ticket" WHERE "status" = \'PENDING\' AND "lastActivityAt" < ?', [$cut]) as $t) {
                Db::update('Ticket', $t['id'], self::statusFields('RESOLVED', $t) + ['lastActivityAt' => Dates::now()]);
                self::event($t['id'], "Automatisch als gelöst markiert (keine Antwort des Kunden seit $pendingDays Tagen)");
                $resolved++;
            }
        }
        return ['closed' => $closed, 'resolved' => $resolved];
    }

    /* ---------- Löschen ---------- */

    public static function delete(string $ticketId): void
    {
        $files = Db::all('SELECT "storedName" FROM "TicketAttachment" WHERE "ticketId" = ?', [$ticketId]);
        Db::delete('Ticket', $ticketId, 'Ticket nicht gefunden');
        foreach ($files as $f) {
            $path = self::dir() . '/' . $f['storedName'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /* ---------- Anhänge ---------- */

    public static function dir(): string
    {
        return DocumentsController::uploadDir() . '/tickets';
    }

    public static function maxFileBytes(): int
    {
        return max(1, Env::int('TICKET_ATTACHMENT_MB', 5)) * 1024 * 1024;
    }

    /**
     * Liest hochgeladene Dateien aus dem Request (Feld files[]).
     *
     * @return list<array{name:string,tmp:string,size:int,error:int}>
     */
    public static function uploads(Request $r): array
    {
        $f = $r->files['files'] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $out = [];
        foreach ((array) $f['name'] as $i => $name) {
            if (!is_string($name)) {
                continue;
            }
            $err = (int) (((array) $f['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'tmp' => (string) ((array) $f['tmp_name'])[$i], 'size' => (int) ((array) $f['size'])[$i], 'error' => $err];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $files
     * @return list<array{name:string,tmp:string,size:int,ext:string}>
     */
    private static function checkFiles(array $files): array
    {
        if (count($files) > self::MAX_FILES) {
            throw ApiError::badRequest('Höchstens ' . self::MAX_FILES . ' Dateien pro Nachricht');
        }
        $max = self::maxFileBytes();
        $out = [];
        foreach ($files as $f) {
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $max) {
                throw new ApiError(413, 'Eine Datei ist zu groß (maximal ' . (int) ($max / 1048576) . ' MB)');
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new ApiError(500, 'Upload fehlgeschlagen');
            }
            $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $f['name']))) ?? '');
            $name = mb_substr($name === '' ? 'datei' : $name, 0, 200);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::FILE_TYPES[$ext])) {
                throw ApiError::badRequest('Dateityp nicht erlaubt: ' . $name . ' (erlaubt: Bilder, PDF, Text, Office, ZIP)');
            }
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && @getimagesize($f['tmp']) === false) {
                throw ApiError::badRequest('Das ist kein gültiges Bild: ' . $name);
            }
            $out[] = ['name' => $name, 'tmp' => $f['tmp'], 'size' => $f['size'], 'ext' => $ext];
        }
        return $out;
    }

    /** @param list<array{name:string,tmp:string,size:int,ext:string}> $files */
    private static function storeFiles(string $ticketId, string $messageId, array $files): void
    {
        if ($files === []) {
            return;
        }
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            throw new ApiError(500, 'Der Ordner für Anhänge ist nicht beschreibbar');
        }
        foreach ($files as $f) {
            $stored = bin2hex(random_bytes(12)) . '.' . $f['ext'];
            $target = self::dir() . '/' . $stored;
            // Bei Tests (kein echter Upload) darf kopiert werden; sonst nur echte Uploads übernehmen
            $ok = is_uploaded_file($f['tmp']) ? move_uploaded_file($f['tmp'], $target) : false;
            if (!$ok) {
                throw new ApiError(500, 'Datei konnte nicht gespeichert werden');
            }
            Db::insert('TicketAttachment', [
                'createdAt' => Dates::now(), 'ticketId' => $ticketId, 'messageId' => $messageId, 'fileName' => $f['name'], 'storedName' => $stored,
                'mimeType' => self::FILE_TYPES[$f['ext']], 'size' => $f['size'],
            ]);
        }
    }

    /** @return array{path:string,name:string,mime:string} */
    public static function attachment(string $attachmentId, ?string $clientId): array
    {
        $a = Db::find('TicketAttachment', $attachmentId) ?? throw ApiError::notFound('Anhang nicht gefunden');
        if ($clientId !== null) {
            $t = Db::find('Ticket', $a['ticketId']);
            $kind = Db::value('SELECT "kind" FROM "TicketMessage" WHERE "id" = ?', [$a['messageId']]);
            if ($t === null || $t['clientId'] !== $clientId || !in_array($kind, ['CUSTOMER', 'STAFF'], true)) {
                throw ApiError::notFound('Anhang nicht gefunden');
            }
        }
        $path = self::dir() . '/' . $a['storedName'];
        if (!preg_match('/^[a-f0-9]{24}\.[a-z0-9]+$/', $a['storedName']) || !is_file($path)) {
            throw ApiError::notFound('Anhang nicht gefunden');
        }
        return ['path' => $path, 'name' => $a['fileName'], 'mime' => $a['mimeType']];
    }

    /* ---------- Benachrichtigungen ---------- */

    private static function notifyStaff(array $ticket, array $client, array $mail): void
    {
        $to = [];
        $addr = trim(Env::get('TICKET_NOTIFY_EMAIL', '') ?: DocumentPdf::company()['email']);
        if ($addr !== '') {
            $to[strtolower($addr)] = $addr;
        }
        if ($ticket['assigneeId']) {
            $u = Db::find('User', $ticket['assigneeId']);
            if ($u !== null && $u['email']) {
                $to[strtolower($u['email'])] = $u['email'];
            }
        }
        foreach ($to as $address) {
            self::mail($address, $mail);
        }
    }

    /** @param array{subject:string,message:string} $mail */
    private static function mail(string $to, array $mail): void
    {
        if ($to === '' || !Mailer::configured()) {
            return;
        }
        try {
            Mailer::send($to, $mail['subject'], $mail['message'] . "\n\n" . MailTemplates::signature());
        } catch (MailException) {
            // Das Ticket ist gespeichert; eine fehlgeschlagene Benachrichtigung darf nichts blockieren.
        }
    }
}
