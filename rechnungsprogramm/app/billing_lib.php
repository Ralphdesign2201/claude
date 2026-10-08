<?php
declare(strict_types=1);

/** Zahlungsanbieter für den SaaS-Betrieb: Stripe (Kreditkarte) und PayPal (Abonnements). */

function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 25): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $out === false ? '' : (string)$out, $err];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
    return [$code, $out === false ? '' : $out, $out === false ? 'Verbindung fehlgeschlagen (curl-Erweiterung fehlt?)' : ''];
}

function billing_url(string $route, array $p = []): string { return app_url($route, $p); }

function billing_url_abs(string $route): string { return app_url($route); }

// ------------------------------------------------------------------ Stripe
function stripe_base(): string { return rtrim(csetting('stripe_api_base') ?: 'https://api.stripe.com', '/'); }

function stripe_call(string $method, string $path, array $params = []): array {
    $key = csetting('stripe_secret');
    if ($key === '') throw new RuntimeException('Stripe ist nicht eingerichtet.');
    $url = stripe_base() . $path; $body = null;
    $qs = http_build_query($params);
    if ($method === 'GET') { if ($qs !== '') $url .= '?' . $qs; } else $body = $qs;
    [$code, $out, $err] = http_request($method, $url, ['Authorization: Bearer ' . $key, 'Content-Type: application/x-www-form-urlencoded'], $body);
    $j = json_decode($out, true);
    if ($err !== '' || !is_array($j)) throw new RuntimeException('Stripe nicht erreichbar: ' . ($err ?: 'ungültige Antwort'));
    if ($code >= 400) throw new RuntimeException('Stripe: ' . ($j['error']['message'] ?? ('HTTP ' . $code)));
    return $j;
}

function stripe_ensure_price(array $plan): string {
    if ($plan['stripe_price_id'] !== '') return $plan['stripe_price_id'];
    $p = stripe_call('POST', '/v1/prices', ['unit_amount' => (int)$plan['price_cents'], 'currency' => strtolower($plan['currency']), 'recurring' => ['interval' => $plan['interval_unit']], 'product_data' => ['name' => csetting('brand_name', APP_NAME) . ' – ' . $plan['name']], 'metadata' => ['plan_id' => (string)$plan['id']]]);
    cexec('UPDATE plans SET stripe_price_id = ? WHERE id = ?', [$p['id'], $plan['id']]);
    return $p['id'];
}

function stripe_checkout_url(array $t, array $plan): string {
    $price = stripe_ensure_price($plan);
    $params = ['mode' => 'subscription', 'line_items' => [['price' => $price, 'quantity' => 1]], 'payment_method_types' => ['card'],
        'success_url' => billing_url('billing_return', ['provider' => 'stripe']) . '&session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => billing_url('billing'),
        'client_reference_id' => (string)$t['id'], 'locale' => 'de', 'metadata' => ['tenant_id' => (string)$t['id'], 'plan_id' => (string)$plan['id']],
        'subscription_data' => ['metadata' => ['tenant_id' => (string)$t['id'], 'plan_id' => (string)$plan['id']]]];
    if ($t['provider_customer'] !== '' && $t['provider'] === 'stripe') $params['customer'] = $t['provider_customer']; else $params['customer_email'] = $t['owner_email'];
    if ($t['status'] === 'trial' && $t['trial_ends'] && strtotime($t['trial_ends'] . ' 23:59:59') > time() + 3 * 86400) $params['subscription_data']['trial_end'] = strtotime($t['trial_ends'] . ' 23:59:59');
    return (string)stripe_call('POST', '/v1/checkout/sessions', $params)['url'];
}

function stripe_portal_url(array $t): string {
    return (string)stripe_call('POST', '/v1/billing_portal/sessions', ['customer' => $t['provider_customer'], 'return_url' => billing_url('billing')])['url'];
}

function stripe_cancel(string $subId): void { stripe_call('POST', '/v1/subscriptions/' . rawurlencode($subId), ['cancel_at_period_end' => 'true']); }

function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $t = 0; $sigs = [];
    foreach (explode(',', $header) as $part) { $kv = explode('=', trim($part), 2); if (count($kv) !== 2) continue; if ($kv[0] === 't') $t = (int)$kv[1]; if ($kv[0] === 'v1') $sigs[] = $kv[1]; }
    if ($t === 0 || !$sigs || abs(time() - $t) > $tolerance || $secret === '') return false;
    $exp = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) if (hash_equals($exp, $s)) return true;
    return false;
}

// ------------------------------------------------------------------ PayPal
function paypal_base(): string {
    if (csetting('paypal_api_base') !== '') return rtrim(csetting('paypal_api_base'), '/');
    return csetting('payment_mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}
function paypal_token(): string {
    static $tok = null;
    if ($tok) return $tok;
    [$code, $out, $err] = http_request('POST', paypal_base() . '/v1/oauth2/token', ['Authorization: Basic ' . base64_encode(csetting('paypal_client_id') . ':' . csetting('paypal_secret')), 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], 'grant_type=client_credentials');
    $j = json_decode($out, true);
    if ($err !== '' || $code >= 400 || empty($j['access_token'])) throw new RuntimeException('PayPal-Anmeldung fehlgeschlagen: ' . ($j['error_description'] ?? $err ?: 'HTTP ' . $code));
    return $tok = $j['access_token'];
}
function paypal_call(string $method, string $path, ?array $json = null): array {
    [$code, $out, $err] = http_request($method, paypal_base() . $path, ['Authorization: Bearer ' . paypal_token(), 'Content-Type: application/json', 'Accept: application/json', 'Prefer: return=representation'], $json === null ? ($method === 'GET' ? null : '{}') : json_encode($json));
    $j = $out === '' ? [] : json_decode($out, true);
    if ($err !== '' || !is_array($j)) throw new RuntimeException('PayPal nicht erreichbar: ' . ($err ?: 'ungültige Antwort'));
    if ($code >= 400) throw new RuntimeException('PayPal: ' . ($j['message'] ?? ('HTTP ' . $code)) . (!empty($j['details'][0]['description']) ? ' – ' . $j['details'][0]['description'] : ''));
    return $j;
}

function paypal_ensure_plan(array $plan): string {
    if ($plan['paypal_plan_id'] !== '') return $plan['paypal_plan_id'];
    $prod = $plan['paypal_product_id'];
    if ($prod === '') {
        $prod = paypal_call('POST', '/v1/catalogs/products', ['name' => csetting('brand_name', APP_NAME) . ' – ' . $plan['name'], 'type' => 'SERVICE', 'category' => 'SOFTWARE'])['id'];
        cexec('UPDATE plans SET paypal_product_id = ? WHERE id = ?', [$prod, $plan['id']]);
    }
    $pp = paypal_call('POST', '/v1/billing/plans', ['product_id' => $prod, 'name' => $plan['name'] . ($plan['interval_unit'] === 'year' ? ' (jährlich)' : ' (monatlich)'), 'status' => 'ACTIVE',
        'billing_cycles' => [['frequency' => ['interval_unit' => $plan['interval_unit'] === 'year' ? 'YEAR' : 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
            'pricing_scheme' => ['fixed_price' => ['value' => number_format($plan['price_cents'] / 100, 2, '.', ''), 'currency_code' => $plan['currency']]]]],
        'payment_preferences' => ['auto_bill_outstanding' => true, 'setup_fee_failure_action' => 'CONTINUE', 'payment_failure_threshold' => 3]]);
    cexec('UPDATE plans SET paypal_plan_id = ? WHERE id = ?', [$pp['id'], $plan['id']]);
    return $pp['id'];
}

function paypal_subscribe_url(array $t, array $plan): string {
    $body = ['plan_id' => paypal_ensure_plan($plan), 'custom_id' => (string)$t['id'] . ':' . (string)$plan['id'], 'subscriber' => ['email_address' => $t['owner_email']],
        'application_context' => ['brand_name' => csetting('brand_name', APP_NAME), 'locale' => 'de-DE', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
            'payment_method' => ['payer_selected' => 'PAYPAL', 'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'], 'return_url' => billing_url('billing_return', ['provider' => 'paypal']), 'cancel_url' => billing_url('billing')]];
    if ($t['status'] === 'trial' && $t['trial_ends'] && strtotime($t['trial_ends'] . ' 23:59:59') > time() + 86400) $body['start_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($t['trial_ends'] . ' 23:59:59'));
    $r = paypal_call('POST', '/v1/billing/subscriptions', $body);
    foreach ($r['links'] ?? [] as $l) if (($l['rel'] ?? '') === 'approve') return (string)$l['href'];
    throw new RuntimeException('PayPal lieferte keinen Zahlungslink.');
}

function paypal_cancel(string $subId): void { paypal_call('POST', '/v1/billing/subscriptions/' . rawurlencode($subId) . '/cancel', ['reason' => 'Kündigung durch den Kunden']); }

function paypal_verify_webhook(array $h, string $body): bool {
    $id = csetting('paypal_webhook_id');
    if ($id === '') return false;
    $h = array_change_key_case($h, CASE_LOWER);
    foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $k) if (empty($h[$k])) return false;
    $r = paypal_call('POST', '/v1/notifications/verify-webhook-signature', ['auth_algo' => $h['paypal-auth-algo'], 'cert_url' => $h['paypal-cert-url'], 'transmission_id' => $h['paypal-transmission-id'],
        'transmission_sig' => $h['paypal-transmission-sig'], 'transmission_time' => $h['paypal-transmission-time'], 'webhook_id' => $id, 'webhook_event' => json_decode($body, true)]);
    return ($r['verification_status'] ?? '') === 'SUCCESS';
}

// ------------------------------------------------------------------ Ereignisse → Mandant
function billing_find_tenant(array $keys): ?array {
    if (!empty($keys['tenant_id']) && ($t = tenant_row_id((int)$keys['tenant_id']))) return $t;
    if (!empty($keys['subscription']) && ($t = cq1('SELECT id FROM tenants WHERE provider_subscription = ?', [$keys['subscription']]))) return tenant_row_id((int)$t['id']);
    if (!empty($keys['customer']) && ($t = cq1('SELECT id FROM tenants WHERE provider_customer = ?', [$keys['customer']]))) return tenant_row_id((int)$t['id']);
    return null;
}
function billing_period_end(string $interval, ?int $from = null): string { return date('Y-m-d', strtotime($interval === 'year' ? '+1 year' : '+1 month', $from ?? time())); }

function billing_record_payment(array $t, string $provider, string $ref, int $cents, string $cur, string $desc): void {
    if (cq1('SELECT id FROM payments WHERE provider_ref = ?', [$ref])) return;
    cexec('INSERT INTO payments(tenant_id, provider, provider_ref, amount_cents, currency, status, description) VALUES (?,?,?,?,?,?,?)', [$t['id'], $provider, $ref, $cents, strtoupper($cur), 'paid', $desc]);
}

/** Wendet ein Stripe-Ereignis an. @return string Kurzbeschreibung */
function billing_apply_stripe(array $ev): string {
    $o = $ev['data']['object'] ?? []; $type = $ev['type'] ?? '';
    switch ($type) {
        case 'checkout.session.completed':
            $t = billing_find_tenant(['tenant_id' => $o['client_reference_id'] ?? ($o['metadata']['tenant_id'] ?? 0)]);
            if (!$t) return 'Mandant unbekannt';
            $plan = (int)($o['metadata']['plan_id'] ?? 0) ?: (int)$t['plan_id'];
            cexec("UPDATE tenants SET provider = 'stripe', provider_customer = ?, provider_subscription = ?, plan_id = ?, status = CASE WHEN status = 'suspended' THEN status ELSE 'active' END, cancel_at_period_end = 0, period_end = COALESCE(CASE WHEN status = 'trial' THEN trial_ends END, period_end) WHERE id = ?", [(string)($o['customer'] ?? ''), (string)($o['subscription'] ?? ''), $plan, $t['id']]);
            return 'Abo aktiviert';
        case 'invoice.paid':
            $t = billing_find_tenant(['subscription' => $o['subscription'] ?? '', 'customer' => $o['customer'] ?? '']);
            if (!$t) return 'Mandant unbekannt';
            $end = $o['lines']['data'][0]['period']['end'] ?? null;
            if ((int)($o['amount_paid'] ?? 0) > 0) billing_record_payment($t, 'stripe', (string)$o['id'], (int)$o['amount_paid'], (string)($o['currency'] ?? 'eur'), 'Abo-Zahlung ' . ($o['number'] ?? $o['id']));
            if ((int)($o['amount_paid'] ?? 0) >= 0) cexec("UPDATE tenants SET status = CASE WHEN status = 'suspended' THEN status ELSE 'active' END, period_end = ? WHERE id = ?", [$end ? date('Y-m-d', (int)$end) : billing_period_end($t['interval_unit'] ?? 'month'), $t['id']]);
            return 'Zahlung verbucht';
        case 'invoice.payment_failed':
            $t = billing_find_tenant(['subscription' => $o['subscription'] ?? '', 'customer' => $o['customer'] ?? '']);
            if ($t) cexec("UPDATE tenants SET status = 'past_due' WHERE id = ? AND status <> 'suspended'", [$t['id']]);
            return 'Zahlung fehlgeschlagen';
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $t = billing_find_tenant(['tenant_id' => $o['metadata']['tenant_id'] ?? 0, 'subscription' => $o['id'] ?? '', 'customer' => $o['customer'] ?? '']);
            if (!$t) return 'Mandant unbekannt';
            $s = $o['status'] ?? '';
            $map = ['active' => 'active', 'trialing' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due', 'canceled' => 'canceled', 'incomplete_expired' => 'canceled'];
            $new = $type === 'customer.subscription.deleted' ? 'canceled' : ($map[$s] ?? $t['status']);
            if ($t['status'] === 'suspended') $new = 'suspended';
            $pe = !empty($o['current_period_end']) ? date('Y-m-d', (int)$o['current_period_end']) : $t['period_end'];
            cexec('UPDATE tenants SET status = ?, period_end = ?, cancel_at_period_end = ? WHERE id = ?', [$new, $pe, !empty($o['cancel_at_period_end']) ? 1 : 0, $t['id']]);
            return 'Abo-Status ' . $new;
    }
    return 'ignoriert';
}

/** Wendet ein PayPal-Ereignis an. */
function billing_apply_paypal(array $ev): string {
    $type = $ev['event_type'] ?? ''; $r = $ev['resource'] ?? [];
    $subId = (string)($r['billing_agreement_id'] ?? ($r['id'] ?? ''));
    $custom = (string)($r['custom_id'] ?? ''); [$tid, $pid] = array_pad(explode(':', $custom), 2, '0');
    $t = billing_find_tenant(['tenant_id' => (int)$tid, 'subscription' => $subId]);
    if (!$t) return 'Mandant unbekannt';
    switch ($type) {
        case 'BILLING.SUBSCRIPTION.ACTIVATED':
            $plan = (int)$pid ?: (int)$t['plan_id'];
            cexec("UPDATE tenants SET provider = 'paypal', provider_subscription = ?, plan_id = ?, status = CASE WHEN status = 'suspended' THEN status ELSE 'active' END, cancel_at_period_end = 0, period_end = COALESCE(CASE WHEN status = 'trial' THEN trial_ends END, period_end) WHERE id = ?", [(string)$r['id'], $plan, $t['id']]);
            return 'Abo aktiviert';
        case 'PAYMENT.SALE.COMPLETED':
            billing_record_payment($t, 'paypal', (string)$r['id'], (int)round(((float)($r['amount']['total'] ?? 0)) * 100), (string)($r['amount']['currency'] ?? 'EUR'), 'Abo-Zahlung PayPal');
            cexec("UPDATE tenants SET status = CASE WHEN status = 'suspended' THEN status ELSE 'active' END, period_end = ? WHERE id = ?", [billing_period_end($t['interval_unit'] ?? 'month'), $t['id']]);
            return 'Zahlung verbucht';
        case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
        case 'BILLING.SUBSCRIPTION.SUSPENDED':
            cexec("UPDATE tenants SET status = 'past_due' WHERE id = ? AND status <> 'suspended'", [$t['id']]);
            return 'Zahlung offen';
        case 'BILLING.SUBSCRIPTION.CANCELLED':
        case 'BILLING.SUBSCRIPTION.EXPIRED':
            cexec("UPDATE tenants SET status = 'canceled' WHERE id = ? AND status <> 'suspended'", [$t['id']]);
            return 'Abo beendet';
    }
    return 'ignoriert';
}
