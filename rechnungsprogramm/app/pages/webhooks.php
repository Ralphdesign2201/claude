<?php
declare(strict_types=1);
require_once APP_ROOT . '/billing_lib.php';

function webhooks_reply(int $code, string $msg): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['status' => $msg]); exit; }

function webhooks_once(string $provider, string $eventId, string $type): bool {
    if ($eventId === '') return true;
    if (cq1('SELECT id FROM webhook_events WHERE event_id = ?', [$provider . ':' . $eventId])) return false;
    cexec('INSERT INTO webhook_events(provider, event_id, type) VALUES (?,?,?)', [$provider, $provider . ':' . $eventId, $type]);
    return true;
}

function webhooks_stripe(): void {
    $body = (string)file_get_contents('php://input');
    if (!stripe_verify_signature($body, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), csetting('stripe_webhook_secret'))) webhooks_reply(400, 'invalid signature');
    $ev = json_decode($body, true);
    if (!is_array($ev) || empty($ev['id'])) webhooks_reply(400, 'bad payload');
    try {
        if (!webhooks_once('stripe', (string)$ev['id'], (string)$ev['type'])) webhooks_reply(200, 'duplicate');
        $r = billing_apply_stripe($ev);
    } catch (Throwable $e) { error_log('Stripe-Webhook: ' . $e->getMessage()); cexec('DELETE FROM webhook_events WHERE event_id = ?', ['stripe:' . $ev['id']]); webhooks_reply(500, 'error'); }
    webhooks_reply(200, $r);
}

function webhooks_paypal(): void {
    $body = (string)file_get_contents('php://input');
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!$headers) foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $headers[str_replace('_', '-', substr($k, 5))] = $v;
    try { if (!paypal_verify_webhook($headers, $body)) webhooks_reply(400, 'invalid signature'); }
    catch (Throwable $e) { error_log('PayPal-Webhook-Prüfung: ' . $e->getMessage()); webhooks_reply(500, 'verification error'); }
    $ev = json_decode($body, true);
    if (!is_array($ev) || empty($ev['id'])) webhooks_reply(400, 'bad payload');
    try {
        if (!webhooks_once('paypal', (string)$ev['id'], (string)$ev['event_type'])) webhooks_reply(200, 'duplicate');
        $r = billing_apply_paypal($ev);
    } catch (Throwable $e) { error_log('PayPal-Webhook: ' . $e->getMessage()); cexec('DELETE FROM webhook_events WHERE event_id = ?', ['paypal:' . $ev['id']]); webhooks_reply(500, 'error'); }
    webhooks_reply(200, $r);
}
