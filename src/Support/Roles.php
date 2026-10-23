<?php

declare(strict_types=1);

/**
 * Admin roles. Every /api/v1/admin route is checked here after sign-in.
 *
 *   super_admin  everything: admin users, Kafui settings and AI providers,
 *                WhatsApp templates, email and Paystack settings, backups
 *   admin        running the shop: products, prices, site content, delivery
 *                zones, promo codes, the Kafui on/off switch and action log
 *   staff        day to day: orders, customers, messages, the Kafui inbox,
 *                bookings and test chat
 *
 * A route not listed below is for the super admin only, so a new admin route
 * stays closed until it is placed here on purpose.
 */
final class Roles
{
    public const LABELS = ['super_admin' => 'Super admin', 'admin' => 'Admin', 'staff' => 'Staff'];
    private const RANK = ['staff' => 1, 'admin' => 2, 'super_admin' => 3];

    /** First match wins: [methods, path after /api/v1/admin, lowest role]. */
    private const RULES = [
        // Admin and above
        ['DELETE', '#^/messages/\d+$#', 'admin'],
        ['POST|PUT|PATCH|DELETE', '#^/products(/\d+(/bulk)?)?$#', 'admin'],
        ['GET|PUT', '#^/content$#', 'admin'],
        ['PUT', '#^/delivery-zones$#', 'admin'],
        ['GET|POST|DELETE', '#^/promo-codes(/\d+)?$#', 'admin'],
        ['PUT', '#^/agent/enabled$#', 'admin'],
        ['GET', '#^/agent/actions$#', 'admin'],
        ['GET', '#^/activity$#', 'admin'],
        // Staff and above
        ['GET', '#^/stats$#', 'staff'],
        ['GET', '#^/products$#', 'staff'],
        ['GET', '#^/delivery-zones$#', 'staff'],
        ['GET', '#^/orders(/\d+(/invoice)?)?$#', 'staff'],
        ['PATCH', '#^/orders/\d+(/notes)?$#', 'staff'],
        ['GET', '#^/customers$#', 'staff'],
        ['GET|PATCH', '#^/messages(/\d+)?$#', 'staff'],
        ['POST|PUT', '#^/account/(password|profile)$#', 'staff'],
        ['GET', '#^/agent/overview$#', 'staff'],
        ['GET', '#^/agent/conversations(/\d+)?$#', 'staff'],
        ['POST', '#^/agent/conversations/\d+/(reply|take-over|hand-back|template)$#', 'staff'],
        ['POST', '#^/agent/test$#', 'staff'],
        ['GET', '#^/agent/templates$#', 'staff'],
        ['GET|PATCH', '#^/bookings(/\d+)?$#', 'staff'],
    ];

    public static function valid(string $role): bool { return isset(self::RANK[$role]); }

    public static function atLeast(?string $role, string $needed): bool
    {
        return isset(self::RANK[(string) $role]) && self::RANK[(string) $role] >= self::RANK[$needed];
    }

    /** The lowest role allowed to call this admin route. */
    public static function needed(string $method, string $path): string
    {
        $path = preg_replace('#^/api/v1/admin#', '', rtrim($path, '/')) ?? '';
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        foreach (self::RULES as [$methods, $pattern, $role]) {
            if (in_array($method, explode('|', $methods), true) && preg_match($pattern, $path)) return $role;
        }
        return 'super_admin';
    }

    public static function allows(?string $role, string $method, string $path): bool
    {
        return self::atLeast($role, self::needed($method, $path));
    }

    /** Halts with 403 when the signed-in admin's role may not call this route. */
    public static function guard(string $method, string $path): void
    {
        if (strtoupper($method) === 'OPTIONS') return;
        if (!self::allows(Auth::role(), $method, $path)) {
            Response::json(['error' => 'Your account does not have access to this. Ask the super admin.'], 403);
            exit;
        }
    }
}
