<?php
declare(strict_types=1);

/** Routen der SaaS-Betriebsart. Liefert [routes, perms, kinds]; kinds: public | webhook | sa | tenant */
function saas_routes(array $routes, array $perms): array {
    // Mandanten dürfen weder die Datenbank umstellen noch Programm-Updates einspielen
    foreach (['db_test', 'db_switch', 'updates', 'update_upload', 'update_install', 'update_finish'] as $r) { unset($routes[$r], $perms[$r]); }
    $extra = [
        'verify' => ['auth', 'verify'], 'verify_resend' => ['auth', 'verify_resend'],
        'home' => ['public', 'home'], 'signup' => ['public', 'signup'], 'page' => ['public', 'page'],
        'webhook_stripe' => ['webhooks', 'stripe'], 'webhook_paypal' => ['webhooks', 'paypal'],
        'billing' => ['billing', 'index'], 'billing_checkout' => ['billing', 'checkout'], 'billing_return' => ['billing', 'back'], 'billing_portal' => ['billing', 'portal'], 'billing_cancel' => ['billing', 'cancel'],
        'sa_login' => ['sa', 'login'], 'sa_login_2fa' => ['sa', 'login_2fa'], 'sa_forgot' => ['sa', 'forgot'], 'sa_reset' => ['sa', 'reset'], 'sa_profile' => ['sa', 'profile'], 'sa_profile_save' => ['sa', 'profile_save'], 'sa_twofa' => ['sa', 'twofa'], 'sa_audit' => ['sa', 'audit'], 'sa_logout' => ['sa', 'logout'], 'sa_dashboard' => ['sa', 'dashboard'],
        'sa_tenants' => ['sa', 'tenants'], 'sa_tenant' => ['sa', 'tenant'], 'sa_tenant_save' => ['sa', 'tenant_save'], 'sa_tenant_action' => ['sa', 'tenant_action'],
        'sa_plans' => ['sa', 'plans'], 'sa_plan_edit' => ['sa', 'plan_edit'], 'sa_plan_save' => ['sa', 'plan_save'], 'sa_plan_delete' => ['sa', 'plan_delete'],
        'sa_payments' => ['sa', 'payments'], 'sa_settings' => ['sa', 'settings'], 'sa_settings_save' => ['sa', 'settings_save'],
        'sa_admins' => ['sa', 'admins'], 'sa_admin_save' => ['sa', 'admin_save'], 'sa_admin_delete' => ['sa', 'admin_delete'],
        'sa_updates' => ['updates', 'index'], 'sa_update_upload' => ['updates', 'upload'], 'sa_update_install' => ['updates', 'install'], 'sa_update_finish' => ['updates', 'finish'],
        'sa_back' => ['sa', 'back_to_sa'],
    ];
    $kinds = [];
    foreach ($extra as $r => $_) $kinds[$r] = str_starts_with($r, 'sa_') ? 'sa' : (str_starts_with($r, 'webhook_') ? 'webhook' : (in_array($r, ['home', 'signup', 'page'], true) ? 'public' : 'tenant'));
    foreach (['sa_login', 'sa_login_2fa', 'sa_forgot', 'sa_reset'] as $r) $kinds[$r] = 'public';
    foreach (['login', 'login_2fa', 'logout', 'forgot', 'reset', 'verify'] as $r) $kinds[$r] = 'public';
    foreach (['billing', 'billing_checkout', 'billing_return', 'billing_portal', 'billing_cancel', 'verify_resend'] as $r) $perms[$r] = '*';
    return [$routes + $extra, $perms, $kinds];
}
