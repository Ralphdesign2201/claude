<?php

declare(strict_types=1);

namespace App\Support;

final class InvoiceMath
{
    /** @return array{subtotal:float,tax:float,total:float} auf Cent gerundet */
    public static function totals(float $subtotal, float $taxRate, float $discount): array
    {
        $subtotal = round($subtotal, 2);
        $discounted = $subtotal - $discount;
        $tax = round($discounted * ($taxRate / 100), 2);
        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => max(0.0, round($discounted + $tax, 2))];
    }

    /** @param list<array<string,mixed>> $items */
    public static function subtotal(array $items): float
    {
        return array_reduce(
            $items,
            static fn (float $sum, array $i) => $sum + round($i['quantity'] * $i['unitPrice'], 2),
            0.0,
        );
    }

    /**
     * Hängt items, payments und totals an eine Rechnung.
     *
     * @param array<string,mixed> $invoice
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $payments
     * @return array<string,mixed>
     */
    public static function withTotals(array $invoice, array $items, array $payments): array
    {
        $totals = self::totals(self::subtotal($items), $invoice['taxRate'], $invoice['discount']);
        $paid = round(array_sum(array_column($payments, 'amount')), 2);
        $invoice['items'] = $items;
        $invoice['payments'] = $payments;
        $invoice['totals'] = $totals + ['paid' => $paid, 'balance' => round($totals['total'] - $paid, 2)];
        return $invoice;
    }
}
