<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AuthController;
use App\Controllers\ClientsController;
use App\Controllers\ContractsController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentsController;
use App\Controllers\InvoicesController;
use App\Controllers\NotesController;
use App\Controllers\ProjectsController;
use App\Controllers\QuotesController;
use App\Controllers\RecurringController;
use App\Controllers\RemindersController;
use App\Controllers\SettingsController;
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
        $r->add('GET', '/api/recurring/:id', [RecurringController::class, 'show']);
        $r->add('PATCH', '/api/recurring/:id', [RecurringController::class, 'update']);
        $r->add('DELETE', '/api/recurring/:id', [RecurringController::class, 'delete']);
        $r->add('POST', '/api/recurring/:id/run', [RecurringController::class, 'run']);
        $r->add('POST', '/api/cron/run', [CronController::class, 'run'], $pub);

        $r->add('GET', '/api/settings', [SettingsController::class, 'show']);

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
            $message = $e->getMessage();
            if (str_contains($message, 'UNIQUE constraint failed')) {
                return Response::json(['error' => 'Eintrag existiert bereits'], 409);
            }
            if (str_contains($message, 'FOREIGN KEY constraint failed')) {
                return Response::json(['error' => 'Referenzierter Datensatz existiert nicht'], 400);
            }
            if (str_contains($message, 'CHECK constraint failed') || str_contains($message, 'NOT NULL constraint failed')) {
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
