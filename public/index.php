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

// Errors go to the log, never to the browser; API responses are never sniffed or framed (CTL-CFG-001).
ini_set('display_errors', '0');
if (str_starts_with($requestPath, '/api/')) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (!str_starts_with($requestPath, '/api/v1/invoices/')) header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

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
require $src . '/Controllers/PromoCodeController.php';
require $src . '/Controllers/CustomerController.php';
require $src . '/Controllers/BackupController.php';
require $src . '/Controllers/AdminUserController.php';
require $src . '/Controllers/AdminActivityController.php';
require $src . '/Agent/bootstrap.php';

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
$promos        = new PromoCodeController();
$customers     = new CustomerController();
$backups       = new BackupController();
$adminUsers    = new AdminUserController();
$activity      = new AdminActivityController();
$whatsapp      = new WhatsAppWebhookController();
$chat          = new ChatController();
$kafui         = new AdminAgentController();

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
$router->post('/api/v1/promo-codes/validate', static fn(array $p) => $promos->validate());

// ---- Kafui (WhatsApp webhook, website chat, signed invoice links) -------
$router->get('/api/v1/whatsapp/webhook', static fn(array $p) => $whatsapp->verify());
$router->post('/api/v1/whatsapp/webhook', static fn(array $p) => $whatsapp->receive());
$router->post('/api/v1/chat/session', static fn(array $p) => $chat->session());
$router->post('/api/v1/chat/message', static fn(array $p) => $chat->message());
$router->get('/api/v1/chat/history', static fn(array $p) => $chat->history());
$router->get('/api/v1/invoices/{token}', static fn(array $p) => Invoices::serve($p['token']));

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
$router->patch('/api/v1/admin/orders/{id}/notes', static fn(array $p) => $adminOrders->updateNotes((int)$p['id']));
$router->get('/api/v1/admin/customers', static fn(array $p) => $customers->index());
$router->get('/api/v1/admin/backups/database', static fn(array $p) => $backups->download());
$router->get('/api/v1/admin/users', static fn(array $p) => $adminUsers->index());
$router->post('/api/v1/admin/users', static fn(array $p) => $adminUsers->save());
$router->delete('/api/v1/admin/users/{id}', static fn(array $p) => $adminUsers->destroy((int)$p['id']));
$router->get('/api/v1/admin/activity', static fn(array $p) => $activity->index());

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
$router->get('/api/v1/admin/promo-codes', static fn(array $p) => $promos->adminIndex());
$router->post('/api/v1/admin/promo-codes', static fn(array $p) => $promos->save());
$router->delete('/api/v1/admin/promo-codes/{id}', static fn(array $p) => $promos->destroy((int)$p['id']));

// ---- Kafui admin ------------------------------------------------------------
$router->get('/api/v1/admin/agent/overview', static fn(array $p) => $kafui->overview());
$router->get('/api/v1/admin/agent/settings', static fn(array $p) => $kafui->settings());
$router->put('/api/v1/admin/agent/settings', static fn(array $p) => $kafui->saveSettings());
$router->post('/api/v1/admin/agent/verify-token', static fn(array $p) => $kafui->newVerifyToken());
$router->put('/api/v1/admin/agent/enabled', static fn(array $p) => $kafui->setEnabled());
$router->get('/api/v1/admin/agent/conversations', static fn(array $p) => $kafui->conversations());
$router->get('/api/v1/admin/agent/conversations/{id}', static fn(array $p) => $kafui->conversation((int)$p['id']));
$router->post('/api/v1/admin/agent/conversations/{id}/reply', static fn(array $p) => $kafui->reply((int)$p['id']));
$router->post('/api/v1/admin/agent/conversations/{id}/take-over', static fn(array $p) => $kafui->takeOver((int)$p['id']));
$router->post('/api/v1/admin/agent/conversations/{id}/hand-back', static fn(array $p) => $kafui->handBack((int)$p['id']));
$router->get('/api/v1/admin/agent/actions', static fn(array $p) => $kafui->actions());
$router->post('/api/v1/admin/agent/test', static fn(array $p) => $kafui->test());
$router->get('/api/v1/admin/bookings', static fn(array $p) => $kafui->bookings());
$router->patch('/api/v1/admin/bookings/{id}', static fn(array $p) => $kafui->updateBooking((int)$p['id']));

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if(str_starts_with($requestPath,'/api/v1/admin')&&!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD','OPTIONS'],true)&&http_response_code()<400)AdminActivityController::record($_SERVER['REQUEST_METHOD'],$requestPath);
