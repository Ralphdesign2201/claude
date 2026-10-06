<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AuthController;
use App\Controllers\ClientsController;
use App\Controllers\ContractsController;
use App\Controllers\BackupController;
use App\Controllers\CatalogController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentsController;
use App\Controllers\InvoicesController;
use App\Controllers\LicenseApiController;
use App\Controllers\LicensesController;
use App\Controllers\NotesController;
use App\Controllers\OrdersController;
use App\Controllers\PortalAuthController;
use App\Controllers\PortalController;
use App\Controllers\LegalController;
use App\Controllers\ReleasesController;
use App\Controllers\PortalSupportController;
use App\Controllers\TicketsController;
use App\Controllers\ProjectsController;
use App\Controllers\QuotesController;
use App\Controllers\RecurringController;
use App\Controllers\RemindersController;
use App\Controllers\SettingsController;
use App\Controllers\SystemController;
use App\Controllers\TasksController;
use App\Controllers\TimeEntriesController;
use App\Controllers\UsersController;
use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Mail\MailException;
use App\Support\Env;
use App\Support\RateLimit;
use PDOException;
use Throwable;

final class App
{
    public static function run(): void
    {
        try {
            $request = Request::fromGlobals();
            $response = self::handle($request);
        } catch (Throwable $e) {
            $response = self::errorResponse($e);
        }

        foreach (self::securityHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->send();
    }

    private static function handle(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return Response::noContent();
        }

        if (str_starts_with($request->path, '/api')) {
            $limit = Env::int('RATE_LIMIT_MAX', 500);
            if (RateLimit::hit('api:' . $request->ip(), 900) > $limit) {
                return Response::json(['error' => 'Zu viele Anfragen – bitte später erneut versuchen'], 429)
                    ->withHeader('Retry-After', '900');
            }
        }

        if (str_starts_with($request->path, '/api') && \App\Support\Product::enforced()) {
            \App\Support\ProductGate::check($request);
        }

        return self::router()->dispatch($request);
    }

    private static function router(): Router
    {
        $r = new Router();
        $pub = Router::PUBLIC;
        $admin = Router::ADMIN;

        $r->add('GET', '/', static fn () => Response::file(APP_ROOT . '/public/assets/index.html', [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ]), $pub);
        $r->add('GET', '/portal', static fn () => Response::file(APP_ROOT . '/public/assets/portal.html', [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]), $pub);
        foreach (\App\Services\LegalService::PATHS as $legalPath) {
            $r->add('GET', $legalPath, [LegalController::class, 'page'], $pub);
        }
        $r->add('GET', '/health', static fn () => Response::json(['status' => 'ok']), $pub);
        $r->add('GET', '/uploads/:name', [DocumentsController::class, 'serve'], $pub);

        $r->add('POST', '/api/auth/register', [AuthController::class, 'register'], $pub);
        $r->add('POST', '/api/auth/login', [AuthController::class, 'login'], $pub);
        $r->add('GET', '/api/auth/me', [AuthController::class, 'me']);

        $r->add('GET', '/api/clients', [ClientsController::class, 'index']);
        $r->add('POST', '/api/clients', [ClientsController::class, 'create']);
        $r->add('GET', '/api/clients/:id', [ClientsController::class, 'show']);
        $r->add('PATCH', '/api/clients/:id', [ClientsController::class, 'update']);
        $r->add('DELETE', '/api/clients/:id', [ClientsController::class, 'delete']);
        $r->add('GET', '/api/clients/:id/portal', [PortalController::class, 'status']);
        $r->add('GET', '/api/clients/:id/tickets', [TicketsController::class, 'forClient']);
        $r->add('POST', '/api/clients/:id/portal', [PortalController::class, 'issue']);
        $r->add('DELETE', '/api/clients/:id/portal', [PortalController::class, 'revoke']);
        $r->add('POST', '/api/clients/:id/contacts', [ClientsController::class, 'createContact']);
        $r->add('PATCH', '/api/clients/:id/contacts/:contactId', [ClientsController::class, 'updateContact']);
        $r->add('DELETE', '/api/clients/:id/contacts/:contactId', [ClientsController::class, 'deleteContact']);

        $r->add('GET', '/api/projects', [ProjectsController::class, 'index']);
        $r->add('POST', '/api/projects', [ProjectsController::class, 'create']);
        $r->add('GET', '/api/projects/:id', [ProjectsController::class, 'show']);
        $r->add('PATCH', '/api/projects/:id', [ProjectsController::class, 'update']);
        $r->add('DELETE', '/api/projects/:id', [ProjectsController::class, 'delete']);
        $r->add('GET', '/api/projects/:id/tasks', [ProjectsController::class, 'tasks']);
        $r->add('POST', '/api/projects/:id/tasks', [ProjectsController::class, 'createTask']);

        $r->add('GET', '/api/tasks', [TasksController::class, 'index']);
        $r->add('PATCH', '/api/tasks/:id', [TasksController::class, 'update']);
        $r->add('DELETE', '/api/tasks/:id', [TasksController::class, 'delete']);

        $r->add('GET', '/api/time-entries', [TimeEntriesController::class, 'index']);
        $r->add('POST', '/api/time-entries', [TimeEntriesController::class, 'create']);
        $r->add('PATCH', '/api/time-entries/:id', [TimeEntriesController::class, 'update']);
        $r->add('DELETE', '/api/time-entries/:id', [TimeEntriesController::class, 'delete']);

        $r->add('GET', '/api/invoices', [InvoicesController::class, 'index']);
        $r->add('POST', '/api/invoices', [InvoicesController::class, 'create']);
        $r->add('GET', '/api/invoices/:id', [InvoicesController::class, 'show']);
        $r->add('GET', '/api/invoices/:id/pdf', [InvoicesController::class, 'pdf']);
        $r->add('PATCH', '/api/invoices/:id', [InvoicesController::class, 'update']);
        $r->add('DELETE', '/api/invoices/:id', [InvoicesController::class, 'delete']);
        $r->add('GET', '/api/invoices/:id/email-draft', [InvoicesController::class, 'emailDraft']);
        $r->add('POST', '/api/invoices/:id/send', [InvoicesController::class, 'send']);
        $r->add('GET', '/api/invoices/:id/reminder-draft', [RemindersController::class, 'draft']);
        $r->add('POST', '/api/invoices/:id/reminders', [RemindersController::class, 'create']);
        $r->add('POST', '/api/invoices/:id/payments', [InvoicesController::class, 'addPayment']);
        $r->add('DELETE', '/api/invoices/:id/payments/:paymentId', [InvoicesController::class, 'deletePayment']);

        $r->add('GET', '/api/reminders/overview', [RemindersController::class, 'overview']);
        $r->add('GET', '/api/reminders/:id/pdf', [RemindersController::class, 'pdf']);
        $r->add('DELETE', '/api/reminders/:id', [RemindersController::class, 'delete']);

        $r->add('GET', '/api/quotes', [QuotesController::class, 'index']);
        $r->add('POST', '/api/quotes', [QuotesController::class, 'create']);
        $r->add('GET', '/api/quotes/:id', [QuotesController::class, 'show']);
        $r->add('PATCH', '/api/quotes/:id', [QuotesController::class, 'update']);
        $r->add('DELETE', '/api/quotes/:id', [QuotesController::class, 'delete']);
        $r->add('GET', '/api/quotes/:id/pdf', [QuotesController::class, 'pdf']);
        $r->add('GET', '/api/quotes/:id/email-draft', [QuotesController::class, 'emailDraft']);
        $r->add('POST', '/api/quotes/:id/send', [QuotesController::class, 'send']);
        $r->add('POST', '/api/quotes/:id/convert', [QuotesController::class, 'convert']);

        $r->add('GET', '/api/recurring', [RecurringController::class, 'index']);
        $r->add('POST', '/api/recurring', [RecurringController::class, 'create']);
        $r->add('POST', '/api/recurring/run-due', [RecurringController::class, 'runDue']);
        $r->add('GET', '/api/recurring/:id', [RecurringController::class, 'show']);
        $r->add('PATCH', '/api/recurring/:id', [RecurringController::class, 'update']);
        $r->add('DELETE', '/api/recurring/:id', [RecurringController::class, 'delete']);
        $r->add('POST', '/api/recurring/:id/run', [RecurringController::class, 'run']);
        $r->add('POST', '/api/cron/run', [CronController::class, 'run'], $pub);

        $r->add('GET', '/api/portal/config', [PortalAuthController::class, 'config'], $pub);
        $r->add('POST', '/api/portal/register', [PortalAuthController::class, 'register'], $pub);
        $r->add('POST', '/api/portal/verify-info', [PortalAuthController::class, 'verifyInfo'], $pub);
        $r->add('POST', '/api/portal/verify', [PortalAuthController::class, 'verify'], $pub);
        $r->add('POST', '/api/portal/login', [PortalAuthController::class, 'login'], $pub);
        $r->add('POST', '/api/portal/logout', [PortalAuthController::class, 'logout'], $pub);
        $r->add('POST', '/api/portal/forgot', [PortalAuthController::class, 'forgot'], $pub);
        $r->add('POST', '/api/portal/reset', [PortalAuthController::class, 'reset'], $pub);
        $r->add('POST', '/api/portal/password', [PortalAuthController::class, 'changePassword'], $pub);
        $r->add('POST', '/api/portal-accounts/:id/active', [PortalAuthController::class, 'setActive']);
        $r->add('POST', '/api/portal-accounts/:id/reset', [PortalAuthController::class, 'sendReset']);
        $r->add('DELETE', '/api/portal-accounts/:id', [PortalAuthController::class, 'deleteAccount']);
        $r->add('GET', '/api/portal/me', [PortalController::class, 'me'], $pub);
        $r->add('GET', '/api/portal/invoices', [PortalController::class, 'invoiceList'], $pub);
        $r->add('GET', '/api/portal/invoices/:id/pdf', [PortalController::class, 'invoicePdf'], $pub);
        $r->add('GET', '/api/portal/quotes', [PortalController::class, 'quoteList'], $pub);
        $r->add('GET', '/api/portal/quotes/:id/pdf', [PortalController::class, 'quotePdf'], $pub);
        $r->add('POST', '/api/portal/quotes/:id/accept', [PortalController::class, 'acceptQuote'], $pub);
        $r->add('POST', '/api/portal/quotes/:id/decline', [PortalController::class, 'declineQuote'], $pub);

        $r->add('POST', '/api/license/verify', [LicenseApiController::class, 'verify'], $pub);
        $r->add('POST', '/api/license/update-check', [LicenseApiController::class, 'updateCheck'], $pub);
        $r->add('POST', '/api/license/download', [LicenseApiController::class, 'download'], $pub);
        $r->add('GET', '/api/releases', [ReleasesController::class, 'index'], $admin);
        $r->add('POST', '/api/releases', [ReleasesController::class, 'create'], $admin);
        $r->add('PATCH', '/api/releases/:id', [ReleasesController::class, 'update'], $admin);
        $r->add('DELETE', '/api/releases/:id', [ReleasesController::class, 'delete'], $admin);
        $r->add('GET', '/api/releases/:id/file', [ReleasesController::class, 'file'], $admin);
        $r->add('GET', '/api/license/public-key', [LicenseApiController::class, 'publicKey'], $pub);
        $r->add('GET', '/license/client.php', [LicenseApiController::class, 'client'], $pub);
        $r->add('GET', '/api/license-entitlements', [LicensesController::class, 'entitlements']);
        $r->add('GET', '/api/licenses', [LicensesController::class, 'index']);
        $r->add('POST', '/api/licenses', [LicensesController::class, 'create']);
        $r->add('GET', '/api/licenses/:id', [LicensesController::class, 'show']);
        $r->add('PATCH', '/api/licenses/:id', [LicensesController::class, 'update']);
        $r->add('DELETE', '/api/licenses/:id', [LicensesController::class, 'delete']);
        $r->add('POST', '/api/licenses/:id/regenerate', [LicensesController::class, 'regenerate']);
        $r->add('POST', '/api/licenses/:id/send', [LicensesController::class, 'send']);
        $r->add('GET', '/api/portal/licenses', [PortalController::class, 'licenseList'], $pub);
        $r->add('POST', '/api/portal/licenses/:id/domain', [PortalController::class, 'changeLicenseDomain'], $pub);
        $r->add('GET', '/api/portal/products', [PortalController::class, 'products'], $pub);
        $r->add('GET', '/api/portal/orders', [PortalController::class, 'orderList'], $pub);
        $r->add('POST', '/api/portal/orders', [PortalController::class, 'createOrder'], $pub);
        $r->add('POST', '/api/portal/orders/:id/cancel', [PortalController::class, 'cancelOrder'], $pub);

        $r->add('GET', '/api/categories', [CatalogController::class, 'categories']);
        $r->add('POST', '/api/categories', [CatalogController::class, 'createCategory']);
        $r->add('PATCH', '/api/categories/:id', [CatalogController::class, 'updateCategory']);
        $r->add('DELETE', '/api/categories/:id', [CatalogController::class, 'deleteCategory']);
        $r->add('GET', '/api/products', [CatalogController::class, 'products']);
        $r->add('POST', '/api/products', [CatalogController::class, 'createProduct']);
        $r->add('GET', '/api/products/:id', [CatalogController::class, 'showProduct']);
        $r->add('PATCH', '/api/products/:id', [CatalogController::class, 'updateProduct']);
        $r->add('DELETE', '/api/products/:id', [CatalogController::class, 'deleteProduct']);
        $r->add('POST', '/api/catalog/examples', [CatalogController::class, 'examples']);
        $r->add('POST', '/api/products/:id/duplicate', [CatalogController::class, 'duplicateProduct']);

        $r->add('GET', '/api/orders', [OrdersController::class, 'index']);
        $r->add('POST', '/api/orders', [OrdersController::class, 'create']);
        $r->add('GET', '/api/orders/:id', [OrdersController::class, 'show']);
        $r->add('POST', '/api/orders/:id/accept', [OrdersController::class, 'accept']);
        $r->add('POST', '/api/orders/:id/reject', [OrdersController::class, 'reject']);


        $r->add('GET', '/api/tickets/meta', [TicketsController::class, 'meta']);
        $r->add('GET', '/api/tickets/stats', [TicketsController::class, 'stats']);
        $r->add('POST', '/api/tickets/bulk', [TicketsController::class, 'bulk']);
        $r->add('GET', '/api/tickets/attachments/:attId', [TicketsController::class, 'attachment']);
        $r->add('GET', '/api/tickets', [TicketsController::class, 'index']);
        $r->add('POST', '/api/tickets', [TicketsController::class, 'create']);
        $r->add('GET', '/api/tickets/:id', [TicketsController::class, 'show']);
        $r->add('PATCH', '/api/tickets/:id', [TicketsController::class, 'update']);
        $r->add('DELETE', '/api/tickets/:id', [TicketsController::class, 'delete']);
        $r->add('POST', '/api/tickets/:id/messages', [TicketsController::class, 'message']);
        $r->add('GET', '/api/canned', [TicketsController::class, 'canned']);
        $r->add('POST', '/api/canned', [TicketsController::class, 'cannedCreate']);
        $r->add('PATCH', '/api/canned/:id', [TicketsController::class, 'cannedUpdate']);
        $r->add('DELETE', '/api/canned/:id', [TicketsController::class, 'cannedDelete']);
        $r->add('GET', '/api/faq', [TicketsController::class, 'faq']);
        $r->add('POST', '/api/faq', [TicketsController::class, 'faqCreate']);
        $r->add('PATCH', '/api/faq/:id', [TicketsController::class, 'faqUpdate']);
        $r->add('DELETE', '/api/faq/:id', [TicketsController::class, 'faqDelete']);
        $r->add('GET', '/api/portal/tickets', [PortalSupportController::class, 'list'], $pub);
        $r->add('POST', '/api/portal/tickets', [PortalSupportController::class, 'create'], $pub);
        $r->add('GET', '/api/portal/tickets/:id', [PortalSupportController::class, 'show'], $pub);
        $r->add('POST', '/api/portal/tickets/:id/messages', [PortalSupportController::class, 'message'], $pub);
        $r->add('POST', '/api/portal/tickets/:id/close', [PortalSupportController::class, 'close'], $pub);
        $r->add('POST', '/api/portal/tickets/:id/reopen', [PortalSupportController::class, 'reopen'], $pub);
        $r->add('POST', '/api/portal/tickets/:id/rating', [PortalSupportController::class, 'rate'], $pub);
        $r->add('GET', '/api/portal/attachments/:attId', [PortalSupportController::class, 'attachment'], $pub);
        $r->add('GET', '/api/portal/faq', [PortalSupportController::class, 'faq'], $pub);

        $r->add('GET', '/api/legal', [LegalController::class, 'index'], $admin);
        $r->add('PUT', '/api/legal/:type', [LegalController::class, 'save'], $admin);
        $r->add('POST', '/api/legal/:type/preview', [LegalController::class, 'preview'], $admin);
        $r->add('GET', '/api/health', [LegalController::class, 'health'], $admin);

        $r->add('GET', '/api/system/status', [SystemController::class, 'status'], $admin);
        $r->add('POST', '/api/system/license/refresh', [SystemController::class, 'refresh'], $admin);
        $r->add('POST', '/api/system/license/key', [SystemController::class, 'setKey'], $admin);
        $r->add('POST', '/api/system/update/check', [SystemController::class, 'checkUpdate'], $admin);
        $r->add('POST', '/api/system/update/install', [SystemController::class, 'installUpdate'], $admin);
        $r->add('GET', '/api/settings', [SettingsController::class, 'show']);
        $r->add('GET', '/api/settings/all', [SettingsController::class, 'all'], $admin);
        $r->add('PUT', '/api/settings/all', [SettingsController::class, 'save'], $admin);
        $r->add('POST', '/api/settings/test-mail', [SettingsController::class, 'testMail'], $admin);
        $r->add('POST', '/api/settings/database/test', [SettingsController::class, 'databaseTest'], $admin);
        $r->add('POST', '/api/settings/database/switch', [SettingsController::class, 'databaseSwitch'], $admin);

        $r->add('GET', '/api/contracts', [ContractsController::class, 'index']);
        $r->add('POST', '/api/contracts', [ContractsController::class, 'create']);
        $r->add('GET', '/api/contracts/:id', [ContractsController::class, 'show']);
        $r->add('PATCH', '/api/contracts/:id', [ContractsController::class, 'update']);
        $r->add('DELETE', '/api/contracts/:id', [ContractsController::class, 'delete']);

        $r->add('GET', '/api/notes', [NotesController::class, 'index']);
        $r->add('POST', '/api/notes', [NotesController::class, 'create']);
        $r->add('PATCH', '/api/notes/:id', [NotesController::class, 'update']);
        $r->add('DELETE', '/api/notes/:id', [NotesController::class, 'delete']);

        $r->add('GET', '/api/documents', [DocumentsController::class, 'index']);
        $r->add('POST', '/api/documents', [DocumentsController::class, 'create']);
        $r->add('DELETE', '/api/documents/:id', [DocumentsController::class, 'delete']);

        $r->add('GET', '/api/dashboard/summary', [DashboardController::class, 'summary']);

        $r->add('GET', '/api/backups', [BackupController::class, 'index'], $admin);
        $r->add('POST', '/api/backups', [BackupController::class, 'create'], $admin);
        $r->add('GET', '/api/backups/:name', [BackupController::class, 'download'], $admin);
        $r->add('DELETE', '/api/backups/:name', [BackupController::class, 'delete'], $admin);

        $r->add('GET', '/api/users', [UsersController::class, 'index']);
        $r->add('POST', '/api/users', [UsersController::class, 'create'], $admin);
        $r->add('PATCH', '/api/users/:id', [UsersController::class, 'update'], $admin);
        $r->add('DELETE', '/api/users/:id', [UsersController::class, 'delete'], $admin);

        return $r;
    }

    private static function errorResponse(Throwable $e): Response
    {
        if ($e instanceof ApiError) {
            return Response::json(['error' => $e->getMessage(), 'details' => $e->details], $e->status);
        }

        if ($e instanceof MailException) {
            return Response::json(['error' => $e->getMessage()], str_contains($e->getMessage(), 'nicht eingerichtet') ? 503 : 502);
        }

        if ($e instanceof PDOException) {
            $kind = \App\Support\Db::violationKind($e);
            if ($kind === 'unique') {
                return Response::json(['error' => 'Eintrag existiert bereits'], 409);
            }
            if ($kind === 'foreign') {
                return Response::json(['error' => 'Referenzierter Datensatz existiert nicht oder wird noch verwendet'], 400);
            }
            if ($kind === 'invalid') {
                return Response::json(['error' => 'Ungültige Daten'], 400);
            }
        }

        error_log((string) $e);
        return Response::json(['error' => 'Interner Serverfehler'], 500);
    }

    /** @return array<string,string> */
    private static function securityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];

        $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ORIGIN', '*') ?? '*')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (in_array('*', $allowed, true)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif ($origin !== null && in_array($origin, $allowed, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin';
        }
        $headers['Access-Control-Allow-Methods'] = 'GET, POST, PATCH, DELETE, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type';
        $headers['Access-Control-Max-Age'] = '600';

        return $headers;
    }
}
