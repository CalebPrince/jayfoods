<?php

declare(strict_types=1);

/**
 * Front controller for the Jayfoods API.
 *
 * Runs as the router script for the PHP built-in server:
 *   php -S localhost:8010 -t public public/index.php
 *
 * Existing static assets (order.html, admin/*, js/api.js, ...) are served
 * as-is; everything else is dispatched to the API router.
 */

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Let the built-in server serve real files directly.
if (PHP_SAPI === 'cli-server' && $requestPath !== '/' && is_file(__DIR__ . $requestPath)) {
    return false;
}

// Landing page.
if ($requestPath === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

$src = dirname(__DIR__) . '/src';

require $src . '/Support/Response.php';
require $src . '/Support/Config.php';
require $src . '/Support/Database.php';
require $src . '/Support/Jwt.php';
require $src . '/Support/Auth.php';
require $src . '/Support/SmtpMailer.php';
require $src . '/Support/EmailNotifications.php';
require $src . '/Support/SecretBox.php';
require $src . '/Support/Inventory.php';
require $src . '/Router.php';
require $src . '/Controllers/OrderController.php';
require $src . '/Controllers/AuthController.php';
require $src . '/Controllers/MessageController.php';
require $src . '/Controllers/AdminProductController.php';
require $src . '/Controllers/AdminOrderController.php';
require $src . '/Controllers/DashboardController.php';
require $src . '/Controllers/SmtpSettingsController.php';
require $src . '/Controllers/PaystackSettingsController.php';
require $src . '/Controllers/PaymentController.php';
require $src . '/Controllers/SiteContentController.php';
require $src . '/Controllers/DeliveryZoneController.php';

// Guard: every /api/v1/admin/* route requires a valid admin session.
if (str_starts_with($requestPath, '/api/v1/admin')) {
    Auth::requireAdmin();
}

$router        = new Router();
$orders        = new OrderController();
$auth          = new AuthController();
$messages      = new MessageController();
$adminProducts = new AdminProductController();
$adminOrders   = new AdminOrderController();
$dashboard     = new DashboardController();
$smtp          = new SmtpSettingsController();
$paystack      = new PaystackSettingsController();
$payments      = new PaymentController();
$content       = new SiteContentController();
$deliveryZones = new DeliveryZoneController();

// ---- Public API -----------------------------------------------------------
$router->get('/api/v1/products', static fn(array $p) => $orders->listProducts());
$router->post('/api/v1/orders', static fn(array $p) => $orders->create());
$router->post('/api/v1/orders/track', static fn(array $p) => $orders->track());
$router->post('/api/v1/orders/{reference}/pay', static fn(array $p) => $payments->initialize($p['reference']));
$router->get('/api/v1/payments/verify/{reference}', static fn(array $p) => $payments->verify($p['reference']));
$router->post('/api/v1/payments/webhook', static fn(array $p) => $payments->webhook());
$router->post('/api/v1/messages', static fn(array $p) => $messages->create());
$router->get('/api/v1/site-content', static fn(array $p) => $content->publicContent());
$router->get('/api/v1/delivery-zones', static fn(array $p) => $deliveryZones->publicIndex());

// ---- Auth -----------------------------------------------------------------
$router->post('/api/v1/auth/login', static fn(array $p) => $auth->login());
$router->post('/api/v1/auth/logout', static fn(array $p) => $auth->logout());
$router->get('/api/v1/auth/me', static fn(array $p) => $auth->me());

// ---- Admin (guarded above) ------------------------------------------------
$router->get('/api/v1/admin/stats', static fn(array $p) => $dashboard->stats());

$router->get('/api/v1/admin/products', static fn(array $p) => $adminProducts->index());
$router->post('/api/v1/admin/products', static fn(array $p) => $adminProducts->create());
$router->put('/api/v1/admin/products/{id}', static fn(array $p) => $adminProducts->update((int) $p['id']));
$router->post('/api/v1/admin/products/{id}', static fn(array $p) => $adminProducts->update((int) $p['id']));
$router->delete('/api/v1/admin/products/{id}', static fn(array $p) => $adminProducts->destroy((int) $p['id']));
$router->patch('/api/v1/admin/products/{id}/bulk', static fn(array $p) => $adminProducts->toggleBulk((int) $p['id']));

$router->get('/api/v1/admin/orders', static fn(array $p) => $adminOrders->index());
$router->get('/api/v1/admin/orders/{id}', static fn(array $p) => $adminOrders->show((int) $p['id']));
$router->patch('/api/v1/admin/orders/{id}', static fn(array $p) => $adminOrders->updateStatus((int) $p['id']));

$router->get('/api/v1/admin/messages', static fn(array $p) => $messages->index());
$router->patch('/api/v1/admin/messages/{id}', static fn(array $p) => $messages->markRead((int) $p['id']));
$router->delete('/api/v1/admin/messages/{id}', static fn(array $p) => $messages->destroy((int) $p['id']));

$router->post('/api/v1/admin/account/password', static fn(array $p) => $auth->changePassword());
$router->put('/api/v1/admin/account/profile', static fn(array $p) => $auth->updateProfile());
$router->get('/api/v1/admin/settings/smtp', static fn(array $p) => $smtp->show());
$router->put('/api/v1/admin/settings/smtp', static fn(array $p) => $smtp->update());
$router->post('/api/v1/admin/settings/smtp/test', static fn(array $p) => $smtp->test());
$router->get('/api/v1/admin/settings/paystack', static fn(array $p) => $paystack->show());
$router->put('/api/v1/admin/settings/paystack', static fn(array $p) => $paystack->update());
$router->get('/api/v1/admin/content', static fn(array $p) => $content->adminContent());
$router->put('/api/v1/admin/content', static fn(array $p) => $content->update());
$router->get('/api/v1/admin/delivery-zones', static fn(array $p) => $deliveryZones->adminIndex());
$router->put('/api/v1/admin/delivery-zones', static fn(array $p) => $deliveryZones->save());

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
