<?php
declare(strict_types=1);

use App\{Auth, Backup, Config, Db, Http, HttpError, Router};
use App\Controllers\{AdminController, AiController, AuthController, BodyController, EntryController,
    FoodController, GoalController, PhotoController, RecipeController, ReportController};

require __DIR__ . '/../app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

try {
    if (!Config::isConfigured()) {
        throw new HttpError(503, 'Aplikácia nie je nainštalovaná. Otvorte install.php', 'not_installed');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Ochrana proti CSRF: zápisové požiadavky musia prísť z našej aplikácie (vlastná hlavička).
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'DietManager') {
        throw new HttpError(403, 'Neplatná požiadavka');
    }
    Config::ensureStorage();
    Db::migrate();

    $r = new Router();
    $r->add('GET', '/auth/me', [AuthController::class, 'me']);
    $r->add('POST', '/auth/login', [AuthController::class, 'login']);
    $r->add('POST', '/auth/logout', [AuthController::class, 'logout']);
    $r->add('POST', '/auth/password', [AuthController::class, 'changePassword']);

    $r->add('GET', '/admin/users', [AdminController::class, 'list']);
    $r->add('POST', '/admin/users', [AdminController::class, 'create']);
    $r->add('PUT', '/admin/users/{id}', [AdminController::class, 'update']);
    $r->add('POST', '/admin/users/{id}/password', [AdminController::class, 'resetPassword']);
    $r->add('DELETE', '/admin/users/{id}', [AdminController::class, 'delete']);

    $r->add('GET', '/goal', [GoalController::class, 'get']);
    $r->add('POST', '/goal', [GoalController::class, 'save']);
    $r->add('POST', '/goal/suggest', [GoalController::class, 'suggest']);

    $r->add('GET', '/day', [EntryController::class, 'day']);
    $r->add('POST', '/entries', [EntryController::class, 'create']);
    $r->add('POST', '/entries/copy', [EntryController::class, 'copy']);
    $r->add('PUT', '/entries/{id}', [EntryController::class, 'update']);
    $r->add('DELETE', '/entries/{id}', [EntryController::class, 'delete']);

    $r->add('GET', '/foods/search', [FoodController::class, 'search']);
    $r->add('GET', '/foods/online', [FoodController::class, 'searchOnline']);
    $r->add('GET', '/foods/recent', [FoodController::class, 'recent']);
    $r->add('GET', '/foods/mine', [FoodController::class, 'mine']);
    $r->add('GET', '/foods/barcode/{code}', [FoodController::class, 'barcode']);
    $r->add('POST', '/foods', [FoodController::class, 'create']);
    $r->add('GET', '/foods/{id}', [FoodController::class, 'show']);
    $r->add('PUT', '/foods/{id}', [FoodController::class, 'update']);
    $r->add('DELETE', '/foods/{id}', [FoodController::class, 'delete']);
    $r->add('POST', '/foods/{id}/favorite', [FoodController::class, 'toggleFavorite']);
    $r->add('POST', '/recipes', static fn() => RecipeController::save(null));
    $r->add('PUT', '/recipes/{id}', static fn(string $id) => RecipeController::save($id));

    $r->add('POST', '/ai/text', [AiController::class, 'text']);
    $r->add('POST', '/ai/photo', [AiController::class, 'photo']);
    $r->add('POST', '/ai/activity', [AiController::class, 'activity']);

    $r->add('POST', '/water', [BodyController::class, 'addWater']);
    $r->add('GET', '/weights', [BodyController::class, 'weights']);
    $r->add('POST', '/weights', [BodyController::class, 'saveWeight']);
    $r->add('DELETE', '/weights/{id}', [BodyController::class, 'deleteWeight']);
    $r->add('GET', '/activities', [BodyController::class, 'activities']);
    $r->add('POST', '/activities', [BodyController::class, 'saveActivity']);
    $r->add('DELETE', '/activities/{id}', [BodyController::class, 'deleteActivity']);

    $r->add('GET', '/reports', [ReportController::class, 'range']);
    $r->add('GET', '/photo', [PhotoController::class, 'show']);

    $result = $r->dispatch($method, (string) ($_GET['r'] ?? '/'));
    Http::json($result);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    // Automatická denná záloha (ak nie je nastavený cron).
    Backup::runIfDue();
} catch (HttpError $e) {
    Http::json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
} catch (Throwable $e) {
    @file_put_contents(Config::storage('logs/error.log'),
        date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
    Http::json(['error' => Config::get('debug') ? $e->getMessage() : 'Nastala neočakávaná chyba servera'], 500);
}
