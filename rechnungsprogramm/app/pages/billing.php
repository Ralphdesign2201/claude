<?php
declare(strict_types=1);
require_once APP_ROOT . '/billing_lib.php';

function billing_guard(): array {
    $u = current_user();
    if (!$u || (int)$u['is_system'] !== 1) { flash('Das Abo kann nur ein Administrator verwalten.', 'err'); redirect('dashboard'); }
    return current_tenant();
}

function billing_index(): void {
    $t = billing_guard();
    $plans = cq('SELECT * FROM plans WHERE active = 1 ORDER BY sort, price_cents');
    $pay = cq('SELECT * FROM payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 12', [$t['id']]);
    $usage = ['users' => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'invoices' => (int)(function () { $st = db()->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_date >= ?'); $st->execute([date('Y-m-01')]); return $st->fetchColumn(); })()];
    render('billing', ['t' => $t, 'plans' => $plans, 'payments' => $pay, 'ready' => sa_billing_ready(), 'usage' => $usage], 'Abo & Zahlung');
}

function billing_checkout(): void {
    $t = billing_guard(); csrf_check();
    $plan = cq1('SELECT * FROM plans WHERE id = ? AND active = 1', [(int)($_POST['plan_id'] ?? 0)]);
    $prov = post('provider');
    if (!$plan) { flash('Bitte einen Tarif wählen.', 'err'); redirect('billing'); }
    if ($t['status'] === 'active' && $t['provider_subscription'] !== '' && $t['provider'] !== $prov) { flash('Es läuft bereits ein Abo bei einem anderen Zahlungsanbieter. Bitte dieses zuerst kündigen.', 'err'); redirect('billing'); }
    try {
        if ($prov === 'stripe') $url = stripe_checkout_url($t, $plan);
        elseif ($prov === 'paypal') $url = paypal_subscribe_url($t, $plan);
        else throw new RuntimeException('Unbekannte Zahlungsart.');
    } catch (Throwable $e) { error_log('Checkout: ' . $e->getMessage()); flash('Die Zahlung konnte nicht gestartet werden: ' . $e->getMessage(), 'err'); redirect('billing'); }
    header('Location: ' . $url);
    exit;
}

/** Rückkehr vom Zahlungsanbieter: Status direkt prüfen (zusätzlich zum Webhook). */
function billing_back(): void {
    $t = billing_guard();
    $prov = (string)($_GET['provider'] ?? '');
    try {
        if ($prov === 'stripe' && !empty($_GET['session_id'])) {
            $s = stripe_call('GET', '/v1/checkout/sessions/' . rawurlencode((string)$_GET['session_id']));
            if ((string)($s['client_reference_id'] ?? '') === (string)$t['id'] && ($s['status'] ?? '') === 'complete') { billing_apply_stripe(['type' => 'checkout.session.completed', 'data' => ['object' => $s]]); flash('Vielen Dank! Ihr Abo ist aktiv.'); }
            else flash('Die Zahlung wurde noch nicht abgeschlossen.', 'err');
        } elseif ($prov === 'paypal' && !empty($_GET['subscription_id'])) {
            $s = paypal_call('GET', '/v1/billing/subscriptions/' . rawurlencode((string)$_GET['subscription_id']));
            [$tid] = array_pad(explode(':', (string)($s['custom_id'] ?? '')), 1, '0');
            if ((int)$tid === (int)$t['id'] && in_array($s['status'] ?? '', ['ACTIVE', 'APPROVED'], true)) { billing_apply_paypal(['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => $s]); flash('Vielen Dank! Ihr Abo ist aktiv.'); }
            else flash('PayPal hat die Freigabe noch nicht bestätigt. Der Status aktualisiert sich automatisch.', 'err');
        } else flash('Keine Zahlungsdaten erhalten.', 'err');
    } catch (Throwable $e) { error_log('Billing return: ' . $e->getMessage()); flash('Status konnte nicht geprüft werden: ' . $e->getMessage(), 'err'); }
    redirect('billing');
}

function billing_portal(): void {
    $t = billing_guard();
    if ($t['provider'] !== 'stripe' || $t['provider_customer'] === '') { flash('Kein Kreditkarten-Abo vorhanden.', 'err'); redirect('billing'); }
    try { header('Location: ' . stripe_portal_url($t)); exit; }
    catch (Throwable $e) { flash('Kundenportal nicht erreichbar: ' . $e->getMessage(), 'err'); redirect('billing'); }
}

function billing_cancel(): void {
    $t = billing_guard(); csrf_check();
    try {
        if ($t['provider'] === 'stripe' && $t['provider_subscription'] !== '') stripe_cancel($t['provider_subscription']);
        elseif ($t['provider'] === 'paypal' && $t['provider_subscription'] !== '') paypal_cancel($t['provider_subscription']);
        else throw new RuntimeException('Es gibt kein laufendes Abo.');
        cexec("UPDATE tenants SET cancel_at_period_end = 1, status = CASE WHEN provider = 'paypal' THEN 'canceled' ELSE status END WHERE id = ?", [$t['id']]);
        flash('Ihr Abo wurde gekündigt und läuft bis zum Ende der bezahlten Periode' . ($t['period_end'] ? ' (' . date_de($t['period_end']) . ')' : '') . '.');
    } catch (Throwable $e) { flash('Kündigung fehlgeschlagen: ' . $e->getMessage(), 'err'); }
    redirect('billing');
}
