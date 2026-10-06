<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;
use App\Support\Where;

/** Katalog: beliebig viele Kategorien und Produkte (Einmalkauf, Miete, Stundenbasis). */
final class CatalogController
{
    public const TYPES = ['ONE_TIME', 'RENTAL', 'HOURLY'];
    public const INTERVALS = ['MONTHLY', 'QUARTERLY', 'HALF_YEARLY', 'YEARLY'];

    private const CATEGORY_SCHEMA = [
        'name' => ['required' => true, 'min' => 1, 'max' => 200],
        'description' => ['max' => 2000],
        'sortOrder' => ['type' => 'int'],
        'active' => ['type' => 'bool'],
    ];

    private const PRODUCT_SCHEMA = [
        'categoryId' => ['emptyOk' => true],
        'name' => ['required' => true, 'min' => 1, 'max' => 255],
        'description' => ['max' => 5000],
        'type' => ['required' => true, 'enum' => self::TYPES],
        'price' => ['required' => true, 'type' => 'number'],
        'taxRate' => ['type' => 'number'],
        'unit' => ['max' => 50],
        'intervalUnit' => ['enum' => self::INTERVALS, 'emptyOk' => true],
        'setupFee' => ['type' => 'number'],
        'minQuantity' => ['type' => 'number', 'positive' => true],
        'active' => ['type' => 'bool'],
        'sortOrder' => ['type' => 'int'],
        'licenseEnabled' => ['type' => 'bool'],
        'licenseSubdomains' => ['type' => 'bool'],
        'licensePayFirst' => ['type' => 'bool'],
        'licenseDays' => ['type' => 'int', 'positive' => true, 'emptyOk' => true],
        'licenseSlug' => ['max' => 40, 'emptyOk' => true],
        'licensePlan' => ['enum' => ['starter', 'pro', 'agency'], 'emptyOk' => true],
        'licenseFeatures' => ['max' => 200, 'emptyOk' => true],
        'licenseSupportDays' => ['type' => 'int', 'emptyOk' => true],
        'licenseUpdateDays' => ['type' => 'int', 'emptyOk' => true],
    ];

    private const PRODUCT_BOOLS = ['active', 'licenseEnabled', 'licenseSubdomains', 'licensePayFirst'];

    /* ---------- Kategorien ---------- */

    public static function categories(Request $r): Response
    {
        $rows = Db::all(
            'SELECT c.*, (SELECT COUNT(*) FROM "Product" p WHERE p.categoryId = c.id) AS productCount
             FROM "Category" c ORDER BY c."sortOrder" ASC, c."name" COLLATE NOCASE ASC',
        );
        return Response::json(Casts::rows($rows, ['active']));
    }

    public static function createCategory(Request $r): Response
    {
        $id = Db::insert('Category', Validator::validate($r->body(), self::CATEGORY_SCHEMA));
        return Response::json(Casts::row(Db::find('Category', $id), ['active']), 201);
    }

    public static function updateCategory(Request $r): Response
    {
        $id = $r->param('id');
        Db::update('Category', $id, Validator::validate($r->body(), self::CATEGORY_SCHEMA, partial: true), 'Kategorie nicht gefunden');
        return Response::json(Casts::row(Db::find('Category', $id), ['active']));
    }

    /** Die Produkte bleiben erhalten und erscheinen danach unter „Ohne Kategorie“. */
    public static function deleteCategory(Request $r): Response
    {
        Db::delete('Category', $r->param('id'), 'Kategorie nicht gefunden');
        return Response::noContent();
    }

    /* ---------- Produkte ---------- */

    public static function products(Request $r): Response
    {
        $where = (new Where())
            ->eq('p.type', $r->q('type'))
            ->search(['p.name', 'p.description'], $r->q('search'));
        $cat = $r->q('categoryId');
        if ($cat === 'none') {
            $where->raw('p."categoryId" IS NULL');
        } else {
            $where->eq('p.categoryId', $cat);
        }
        if ($r->q('active') !== null) {
            $where->raw('p."active" = ?', [in_array($r->q('active'), ['true', '1'], true) ? 1 : 0]);
        }

        $rows = Db::all(
            'SELECT p.*, c.id AS category__id, c.name AS category__name
             FROM "Product" p LEFT JOIN "Category" c ON c.id = p.categoryId'
            . $where->sql() . ' ORDER BY p."sortOrder" ASC, p."name" COLLATE NOCASE ASC',
            $where->params(),
        );
        return Response::json(Casts::rows($rows, self::PRODUCT_BOOLS));
    }

    public static function showProduct(Request $r): Response
    {
        return Response::json(self::loadProduct($r->param('id')));
    }

    public static function createProduct(Request $r): Response
    {
        $data = self::normalize(Validator::validate($r->body(), self::PRODUCT_SCHEMA), null);
        $id = Db::insert('Product', $data);
        return Response::json(self::loadProduct($id), 201);
    }

    public static function updateProduct(Request $r): Response
    {
        $id = $r->param('id');
        $current = Db::require('Product', $id, 'Produkt nicht gefunden');
        $data = self::normalize(Validator::validate($r->body(), self::PRODUCT_SCHEMA, partial: true), $current);
        Db::update('Product', $id, $data, 'Produkt nicht gefunden');
        return Response::json(self::loadProduct($id));
    }

    /** Kopie als inaktives Produkt („… (Kopie)“), um ähnliche Produkte schnell anzulegen. */
    public static function duplicateProduct(Request $r): Response
    {
        $p = Db::require('Product', $r->param('id'), 'Produkt nicht gefunden');
        unset($p['id'], $p['createdAt'], $p['updatedAt']);
        $p['name'] = mb_substr($p['name'], 0, 240) . ' (Kopie)';
        $p['active'] = 0;
        return Response::json(self::loadProduct(Db::insert('Product', $p)), 201);
    }

    /** Bestehende Bestellungen behalten ihre Produktdaten (Schnappschuss) und bleiben unverändert. */
    public static function deleteProduct(Request $r): Response
    {
        Db::delete('Product', $r->param('id'), 'Produkt nicht gefunden');
        return Response::noContent();
    }

    /** Legt einen Beispielkatalog an (alles inaktiv, damit keine Platzhalterpreise im Portal erscheinen). */
    public static function examples(Request $r): Response
    {
        return Response::json(self::createExamples(), 201);
    }

    /**
     * Beispielkatalog nach dem üblichen Angebot eines Webdesigners: Einmalleistungen, Mietprodukte, Zeitleistungen.
     * Bereits vorhandene Kategorien und Produkte (gleicher Name) werden nicht doppelt angelegt.
     * Die Preise sind Vorschläge; die Produkte sind inaktiv, bis du sie prüfst und aktivierst.
     *
     * @return array{categories:int,products:int}
     */
    public static function createExamples(): array
    {
        $catalog = [
            ['Einmalige Leistungen', 'Einmalig beauftragte Arbeiten', 1, [
                ['Webseitenerstellung einmalig', 'ONE_TIME', 1500, null, null, 0, 1, 'Individuelle Website nach Ihren Wünschen. Bitte beschreiben Sie Ihr Projekt in den Anmerkungen.'],
                ['Skripte einmalig', 'ONE_TIME', 250, null, null, 0, 1, 'Individuelles Skript oder eine Automatisierung nach Ihren Vorgaben.'],
                ['Software-Lizenz (1 Domain)', 'ONE_TIME', 149, null, null, 0, 1, 'Lizenz für unsere Software auf einer Domain. Bitte geben Sie bei der Bestellung Ihre Domain an.', true],
                ['Druckaufträge', 'ONE_TIME', 0.15, 'Stück', null, 0, 100, 'Flyer, Visitenkarten, Plakate und mehr. Format und Wünsche bitte in den Anmerkungen angeben.'],
            ]],
            ['Mietprodukte', 'Laufende Leistungen mit fester Abrechnung', 2, [
                ['Webhosting', 'RENTAL', 9.9, null, 'MONTHLY', 0, 1, 'Zuverlässiges Hosting für Ihre Website.'],
                ['Domain', 'RENTAL', 15, null, 'YEARLY', 0, 1, 'Registrierung und jährliche Verlängerung Ihrer Domain. Wunsch-Domain bitte in den Anmerkungen angeben.'],
                ['Miethomepage', 'RENTAL', 49, null, 'MONTHLY', 199, 1, 'Ihre Website im Mietmodell, inklusive Hosting und Pflege.'],
                ['Wartungsservice', 'RENTAL', 79, null, 'MONTHLY', 0, 1, 'Updates, Sicherungen und Überwachung Ihrer Website.'],
                ['SEO-Service', 'RENTAL', 199, null, 'MONTHLY', 0, 1, 'Laufende Suchmaschinenoptimierung mit monatlichem Bericht.'],
            ]],
            ['Zeitbasierte Leistungen', 'Abrechnung nach tatsächlichem Aufwand', 3, [
                ['Projektarbeit nach Stunden', 'HOURLY', 85, 'Std.', null, 0, 1, 'Projekte auf Stundenbasis. Die Stundenzahl ist eine Schätzung, abgerechnet wird nach Aufwand.'],
            ]],
        ];

        $cats = $products = 0;
        Db::transaction(static function () use ($catalog, &$cats, &$products) {
            foreach ($catalog as [$name, $desc, $sort, $items]) {
                $row = Db::one('SELECT "id" FROM "Category" WHERE "name" = ? COLLATE NOCASE', [$name]);
                if ($row === null) {
                    $row = ['id' => Db::insert('Category', ['name' => $name, 'description' => $desc, 'sortOrder' => $sort])];
                    $cats++;
                }
                foreach ($items as $i => [$pname, $type, $price, $unit, $interval, $setup, $min, $text, $licensed]) {
                    $licensed ??= false;
                    if (Db::one('SELECT 1 FROM "Product" WHERE "categoryId" = ? AND "name" = ? COLLATE NOCASE', [$row['id'], $pname])) {
                        continue;
                    }
                    Db::insert('Product', [
                        'categoryId' => $row['id'], 'name' => $pname, 'description' => $text, 'type' => $type, 'price' => $price,
                        'unit' => $unit, 'intervalUnit' => $interval, 'setupFee' => $setup, 'minQuantity' => $min,
                        'active' => 0, 'sortOrder' => $i, 'licenseEnabled' => (int) $licensed,
                    ]);
                    $products++;
                }
            }
        });
        return ['categories' => $cats, 'products' => $products];
    }

    /** @return array<string,mixed> */
    private static function loadProduct(string $id): array
    {
        $row = Db::one(
            'SELECT p.*, c.id AS category__id, c.name AS category__name FROM "Product" p LEFT JOIN "Category" c ON c.id = p.categoryId WHERE p."id" = ?',
            [$id],
        ) ?? throw ApiError::notFound('Produkt nicht gefunden');
        return Casts::row($row, self::PRODUCT_BOOLS);
    }

    /**
     * Fachliche Regeln je Produktart. Miete braucht einen Rhythmus; Einrichtungsgebühr und Rhythmus
     * gibt es nur bei Mietprodukten; Zeitprodukte erhalten die Einheit „Std.“.
     *
     * @param array<string,mixed> $data validierte Eingabe
     * @param array<string,mixed>|null $current bestehendes Produkt (bei Änderungen)
     * @return array<string,mixed>
     */
    private static function normalize(array $data, ?array $current): array
    {
        $merged = $data + ($current ?? []);
        $errors = [];

        if (($merged['price'] ?? 0) < 0) {
            $errors['price'][] = 'Der Preis darf nicht negativ sein';
        }
        if (isset($merged['setupFee']) && $merged['setupFee'] < 0) {
            $errors['setupFee'][] = 'Die Einrichtungsgebühr darf nicht negativ sein';
        }
        if (isset($merged['taxRate']) && ($merged['taxRate'] < 0 || $merged['taxRate'] > 100)) {
            $errors['taxRate'][] = 'Der Steuersatz muss zwischen 0 und 100 liegen';
        }
        $type = $merged['type'];
        if ($type === 'RENTAL' && empty($merged['intervalUnit'])) {
            $errors['intervalUnit'][] = 'Für Mietprodukte ist ein Abrechnungsrhythmus nötig';
        }
        if (array_key_exists('categoryId', $data) && $data['categoryId'] !== null && !Db::find('Category', $data['categoryId'])) {
            $errors['categoryId'][] = 'Kategorie nicht gefunden';
        }
        foreach (['licenseSupportDays', 'licenseUpdateDays'] as $f) {
            if (isset($data[$f]) && ($data[$f] < 0 || $data[$f] > 36500)) {
                $errors[$f][] = 'Erlaubt sind 0 bis 36500 Tage (0 = nicht enthalten, leer = unbegrenzt)';
            }
        }
        if (isset($data['licenseSlug']) && $data['licenseSlug'] !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $data['licenseSlug'])) {
            $errors['licenseSlug'][] = 'Nur Kleinbuchstaben, Ziffern und Bindestriche (z. B. crm)';
        }
        if ($errors !== []) {
            throw ApiError::badRequest('Validierungsfehler', ['formErrors' => [], 'fieldErrors' => (object) $errors]);
        }
        if (array_key_exists('licenseSlug', $data) && $data['licenseSlug'] === '') {
            $data['licenseSlug'] = null;
        }
        if (array_key_exists('licenseFeatures', $data)) {
            $data['licenseFeatures'] = \App\Services\Entitlements::normalize($data['licenseFeatures']);
        }

        if ($type !== 'RENTAL') {
            $data['intervalUnit'] = null;
            $data['setupFee'] = 0;
        }
        if ($type === 'HOURLY' && empty($merged['unit'])) {
            $data['unit'] = 'Std.';
        }
        // Lizenzen gibt es nur für Einmal- und Mietprodukte; die Laufzeit in Tagen nur für Einmalprodukte (Miete folgt den bezahlten Zeiträumen)
        if ($type === 'HOURLY') {
            $data['licenseEnabled'] = false;
            $data['licenseSubdomains'] = false;
            $data['licenseDays'] = null;
            foreach (['licenseSlug', 'licensePlan', 'licenseFeatures', 'licenseSupportDays', 'licenseUpdateDays'] as $f) {
                $data[$f] = null;
            }
        } elseif ($type === 'RENTAL') {
            $data['licenseDays'] = null;
        }
        if (array_key_exists('categoryId', $data) && $data['categoryId'] === '') {
            $data['categoryId'] = null;
        }
        return $data;
    }
}
