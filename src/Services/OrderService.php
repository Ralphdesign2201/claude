<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiError;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Pdf\DocumentPdf;
use App\Support\Activity;
use App\Support\Dates;
use App\Support\Db;
use App\Support\Env;
use App\Support\InvoiceMath;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Bestellungen aus dem Katalog: Eingang (Portal oder Admin), Annahme (erzeugt Rechnung, Abo oder Projekt) und Ablehnung.
 * Produktname, Preis und Steuersatz werden bei der Bestellung festgehalten.
 */
final class OrderService
{
    public const MAX_PENDING_PER_CLIENT = 20;
    public const MAX_QUANTITY = 100000;

    /** @return array<string,mixed> */
    public static function create(string $clientId, string $productId, float $quantity, string $note, string $source, bool $portal, string $domain = ''): array
    {
        $product = Db::one('SELECT p.*, c.active AS categoryActive FROM "Product" p LEFT JOIN "Category" c ON c.id = p.categoryId WHERE p."id" = ?', [$productId])
            ?? throw ApiError::notFound('Produkt nicht gefunden');
        if ($portal && (!$product['active'] || ($product['categoryId'] !== null && !$product['categoryActive']))) {
            throw ApiError::notFound('Produkt nicht gefunden');
        }
        if ($quantity < (float) $product['minQuantity']) {
            throw ApiError::badRequest('Mindestmenge: ' . \App\Support\Format::qty((float) $product['minQuantity']));
        }
        if ($quantity > self::MAX_QUANTITY) {
            throw ApiError::badRequest('Menge zu groß');
        }
        // Lizenzprodukte brauchen die Domain, für die die Lizenz gelten soll
        $licensed = (bool) $product['licenseEnabled'] && in_array($product['type'], ['ONE_TIME', 'RENTAL'], true);
        $licenseDomain = null;
        if ($licensed) {
            $licenseDomain = LicenseService::normalizeDomain($domain)
                ?? throw ApiError::badRequest('Bitte gib die Domain an, für die die Lizenz gelten soll (z. B. meine-seite.de)');
        }

        $id = (string) Db::transaction(static function () use ($clientId, $product, $quantity, $note, $source, $licensed, $licenseDomain) {
            if ($source === 'PORTAL' && (int) Db::value('SELECT COUNT(*) FROM "ProductOrder" WHERE "clientId" = ? AND "status" = \'PENDING\'', [$clientId]) >= self::MAX_PENDING_PER_CLIENT) {
                throw ApiError::conflict('Es liegen bereits sehr viele offene Bestellungen vor – bitte warte auf unsere Bestätigung');
            }
            return Db::insert('ProductOrder', [
                'number' => Numbering::next('ProductOrder'),
                'clientId' => $clientId,
                'productId' => $product['id'],
                'source' => $source,
                'productName' => $product['name'],
                'productType' => $product['type'],
                'unitPrice' => $product['price'],
                'taxRate' => $product['taxRate'],
                'quantity' => $quantity,
                'unit' => $product['unit'],
                'intervalUnit' => $product['intervalUnit'],
                'setupFee' => $product['setupFee'],
                'note' => $note !== '' ? $note : null,
                'domain' => $licenseDomain,
                'licenseEnabled' => (int) $licensed,
                'licenseSubdomains' => (int) $product['licenseSubdomains'],
                'licensePayFirst' => (int) $product['licensePayFirst'],
                'licenseDays' => $product['licenseDays'],
                'licensePlan' => $licensed ? $product['licensePlan'] : null,
                'licenseFeatures' => $licensed ? $product['licenseFeatures'] : null,
                'licenseSupportDays' => $licensed ? $product['licenseSupportDays'] : null,
                'licenseUpdateDays' => $licensed ? $product['licenseUpdateDays'] : null,
            ]);
        });

        $order = self::detail($id);
        Activity::log('ORDER_CREATED', "Bestellung {$order['number']}: " . \App\Support\Format::qty($quantity) . "× {$order['productName']}" . ($source === 'PORTAL' ? ' (über das Kundenportal)' : ''), $clientId, null, null);

        // Benachrichtigungen sind optional; die Bestellung ist bereits gespeichert.
        $client = Db::find('Client', $clientId);
        $company = DocumentPdf::company()['email'];
        self::mail($company, MailTemplates::orderNotify($client, $order));
        if ($source === 'PORTAL') {
            self::mail(MailTemplates::recipient($client), MailTemplates::orderReceived($client, $order));
        }
        return $order;
    }

    /**
     * Nimmt eine Bestellung an und legt je nach Produktart an:
     *  - ONE_TIME: Rechnung (Entwurf)
     *  - RENTAL:   Abo (wiederkehrende Rechnung), eine Einrichtungsgebühr als eigene Rechnung (Entwurf), auf Wunsch die erste Rechnung sofort
     *  - HOURLY:   Projekt mit Stundensatz und Budget aus der geschätzten Stundenzahl
     *
     * @param array{startDate?:?string,billNow?:bool,autoSend?:bool} $opts
     * @return array<string,mixed>
     */
    public static function accept(string $id, array $opts, ?string $userId): array
    {
        $startDate = $opts['startDate'] ?? null;
        $billNow = (bool) ($opts['billNow'] ?? true);
        $autoSend = (bool) ($opts['autoSend'] ?? false);

        $result = Db::transaction(static function () use ($id, $startDate, $autoSend, $userId) {
            $o = self::requirePending($id);
            $links = [];
            $licenseId = null;
            $notes = "Bestellung {$o['number']}";
            $payDays = max(0, Env::int('PAYMENT_DAYS', 14));
            $due = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+$payDays days")->format(Dates::FORMAT);

            switch ($o['productType']) {
                case 'ONE_TIME':
                    $links['invoiceId'] = InvoiceService::create([
                        'clientId' => $o['clientId'], 'status' => 'DRAFT', 'dueDate' => $due, 'taxRate' => $o['taxRate'], 'notes' => $notes,
                    ], [['description' => $o['productName'], 'quantity' => $o['quantity'], 'unitPrice' => $o['unitPrice']]]);
                    break;

                case 'RENTAL':
                    $start = $startDate ?: gmdate('Y-m-d') . 'T00:00:00.000Z';
                    $recId = Db::insert('Recurring', [
                        'clientId' => $o['clientId'], 'title' => $o['productName'], 'intervalUnit' => $o['intervalUnit'],
                        'startDate' => $start, 'nextRunDate' => $start, 'occurrence' => 0, 'active' => 1, 'autoSend' => (int) $autoSend,
                        'paymentDays' => $payDays, 'taxRate' => $o['taxRate'], 'notes' => $notes,
                    ]);
                    Db::insert('RecurringItem', [
                        'recurringId' => $recId, 'description' => $o['productName'] . ' ({zeitraum})',
                        'quantity' => $o['quantity'], 'unitPrice' => $o['unitPrice'], 'position' => 0,
                    ]);
                    $links['recurringId'] = $recId;
                    if ((float) $o['setupFee'] > 0) {
                        $links['setupInvoiceId'] = InvoiceService::create([
                            'clientId' => $o['clientId'], 'status' => 'DRAFT', 'dueDate' => $due, 'taxRate' => $o['taxRate'], 'notes' => $notes,
                        ], [['description' => 'Einrichtung: ' . $o['productName'], 'quantity' => 1, 'unitPrice' => $o['setupFee']]]);
                    }
                    break;

                case 'HOURLY':
                    $links['projectId'] = Db::insert('Project', [
                        'clientId' => $o['clientId'], 'name' => $o['productName'],
                        'description' => $notes . ($o['note'] ? ":\n" . $o['note'] : ''),
                        'status' => 'PLANNED', 'budget' => round($o['unitPrice'] * $o['quantity'], 2), 'hourlyRate' => $o['unitPrice'], 'ownerId' => $userId,
                    ]);
                    break;
            }

            if ($o['licenseEnabled'] && $o['domain'] && in_array($o['productType'], ['ONE_TIME', 'RENTAL'], true)) {
                $licenseId = LicenseService::issueForOrder($o, $links['invoiceId'] ?? null, $links['recurringId'] ?? null, $start ?? null);
                $links['licenseId'] = $licenseId;
            }
            Db::update('ProductOrder', $id, ['status' => 'ACCEPTED', 'decidedAt' => Dates::now()] + $links);
            // Wer etwas bestellt hat und angenommen wurde, ist kein Interessent mehr
            Db::run('UPDATE "Client" SET "status" = \'ACTIVE\', "updatedAt" = ? WHERE "id" = ? AND "status" = \'LEAD\'', [Dates::now(), $o['clientId']]);
            Activity::log('ORDER_ACCEPTED', "Bestellung {$o['number']} angenommen", $o['clientId'], $links['projectId'] ?? null, $userId);
            return ['links' => $links, 'start' => $start ?? null, 'licenseId' => $licenseId];
        });

        // Ist die Lizenz sofort aktiv (keine Zahlung vorab nötig), bekommt der Kunde den Schlüssel gleich per E-Mail
        if ($result['licenseId'] !== null && Db::value('SELECT "status" FROM "License" WHERE "id" = ?', [$result['licenseId']]) === 'ACTIVE') {
            LicenseService::sendLicenseMail($result['licenseId']);
        }

        // Erste Rechnung eines Mietprodukts sofort erzeugen, wenn der Start heute oder früher liegt
        $links = $result['links'];
        if (isset($links['recurringId']) && $billNow && $result['start'] <= gmdate(Dates::FORMAT)) {
            $run = RecurringService::runOne($links['recurringId'], $userId, $autoSend);
            Db::update('ProductOrder', $id, ['invoiceId' => $run['invoiceId']]);
        }

        $order = self::detail($id);
        $client = Db::find('Client', $order['clientId']);
        self::mail(MailTemplates::recipient($client), MailTemplates::orderDecision($client, $order));
        return $order;
    }

    /** @return array<string,mixed> */
    public static function reject(string $id, string $reason, ?string $userId): array
    {
        Db::transaction(static function () use ($id, $reason, $userId) {
            $o = self::requirePending($id);
            Db::update('ProductOrder', $id, ['status' => 'REJECTED', 'rejectReason' => $reason !== '' ? $reason : null, 'decidedAt' => Dates::now()]);
            Activity::log('ORDER_REJECTED', "Bestellung {$o['number']} abgelehnt", $o['clientId'], null, $userId);
        });

        $order = self::detail($id);
        $client = Db::find('Client', $order['clientId']);
        self::mail(MailTemplates::recipient($client), MailTemplates::orderDecision($client, $order));
        return $order;
    }

    /** Der Kunde zieht eine noch offene Bestellung zurück. @return array<string,mixed> */
    public static function cancel(string $id, string $clientId): array
    {
        Db::transaction(static function () use ($id, $clientId) {
            $o = self::requirePending($id);
            if ($o['clientId'] !== $clientId) {
                throw ApiError::notFound('Bestellung nicht gefunden');
            }
            Db::update('ProductOrder', $id, ['status' => 'CANCELLED', 'decidedAt' => Dates::now()]);
            Activity::log('ORDER_CANCELLED', "Bestellung {$o['number']} vom Kunden storniert", $clientId, null, null);
        });
        return self::detail($id);
    }

    /** @return array<string,mixed> Bestellung mit Kunde, verknüpften Objekten und Summen */
    public static function detail(string $id): array
    {
        $o = Db::require('ProductOrder', $id, 'Bestellung nicht gefunden');
        $o['client'] = Db::one('SELECT "id", "name", "company", "email" FROM "Client" WHERE "id" = ?', [$o['clientId']]);
        $o['invoice'] = $o['invoiceId'] ? Db::one('SELECT "id", "number", "status" FROM "Invoice" WHERE "id" = ?', [$o['invoiceId']]) : null;
        $o['setupInvoice'] = $o['setupInvoiceId'] ? Db::one('SELECT "id", "number", "status" FROM "Invoice" WHERE "id" = ?', [$o['setupInvoiceId']]) : null;
        $o['recurring'] = $o['recurringId'] ? Db::one('SELECT "id", "title", "active" FROM "Recurring" WHERE "id" = ?', [$o['recurringId']]) : null;
        $o['project'] = $o['projectId'] ? Db::one('SELECT "id", "name" FROM "Project" WHERE "id" = ?', [$o['projectId']]) : null;
        $o['license'] = $o['licenseId'] ? Db::one('SELECT "id", "licenseKey", "status", "domain", "validUntil", "paidThrough", "recurringId" FROM "License" WHERE "id" = ?', [$o['licenseId']]) : null;
        $o['totals'] = self::totals($o);
        return $o;
    }

    /**
     * Summen der Bestellung (netto). Bei Mietprodukten ist „net“ die erste Zahlung inkl. Einrichtung,
     * „recurringNet“ der Betrag je Zeitraum; bei Stundenprodukten eine Schätzung.
     *
     * @param array<string,mixed> $o
     * @return array{recurringNet:float,setupNet:float,net:float,tax:float,gross:float}
     */
    public static function totals(array $o): array
    {
        $line = round((float) $o['unitPrice'] * (float) $o['quantity'], 2);
        $setup = $o['productType'] === 'RENTAL' ? round((float) $o['setupFee'], 2) : 0.0;
        $t = InvoiceMath::totals($line + $setup, (float) $o['taxRate'], 0.0);
        return ['recurringNet' => $o['productType'] === 'RENTAL' ? $line : 0.0, 'setupNet' => $setup, 'net' => $t['subtotal'], 'tax' => $t['tax'], 'gross' => $t['total']];
    }

    /** Aktive Kategorien mit ihren aktiven Produkten für das Kundenportal. @return list<array<string,mixed>> */
    public static function portalCatalog(): array
    {
        $cats = Db::all('SELECT "id", "name", "description" FROM "Category" WHERE "active" = 1 ORDER BY "sortOrder" ASC, "name" COLLATE NOCASE ASC');
        $products = Db::all('SELECT * FROM "Product" WHERE "active" = 1 ORDER BY "sortOrder" ASC, "name" COLLATE NOCASE ASC');

        $byCat = [];
        foreach ($products as $p) {
            $byCat[$p['categoryId'] ?? ''][] = self::publicProduct($p);
        }
        $out = [];
        foreach ($cats as $c) {
            if (!empty($byCat[$c['id']])) {
                $out[] = $c + ['products' => $byCat[$c['id']]];
            }
        }
        // Produkte ohne Kategorie; Produkte inaktiver Kategorien bleiben bewusst verborgen
        if (!empty($byCat[''])) {
            $out[] = ['id' => null, 'name' => 'Weitere Produkte', 'description' => null, 'products' => $byCat['']];
        }
        return $out;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    public static function publicProduct(array $p): array
    {
        return [
            'id' => $p['id'],
            'name' => $p['name'],
            'description' => $p['description'],
            'type' => $p['type'],
            'price' => $p['price'],
            'taxRate' => $p['taxRate'],
            'unit' => $p['unit'],
            'intervalUnit' => $p['intervalUnit'],
            'setupFee' => $p['setupFee'],
            'minQuantity' => $p['minQuantity'],
            'license' => (bool) $p['licenseEnabled'] && $p['type'] !== 'HOURLY',
            'licenseSubdomains' => (bool) $p['licenseSubdomains'],
            'licensePayFirst' => (bool) $p['licensePayFirst'],
            'licenseDays' => $p['licenseDays'],
            'licensePlan' => $p['licensePlan'],
            'licenseSupportDays' => $p['licenseSupportDays'],
            'licenseUpdateDays' => $p['licenseUpdateDays'],
        ];
    }

    /** @return array<string,mixed> */
    private static function requirePending(string $id): array
    {
        $o = Db::require('ProductOrder', $id, 'Bestellung nicht gefunden');
        if ($o['status'] !== 'PENDING') {
            throw ApiError::conflict('Diese Bestellung wurde bereits bearbeitet');
        }
        return $o;
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
            // Benachrichtigungen sind optional; Bestellung und Entscheidung sind bereits gespeichert.
        }
    }
}
