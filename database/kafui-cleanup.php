<?php

declare(strict_types=1);

/**
 * Daily Kafui clean-up for cron: deletes chats 90 days after their last
 * message, invoice files after 30 days, old counters and rate-limit rows,
 * and emails an alert if today's Kafui orders look unusually high.
 *
 *   0 3 * * * /usr/local/bin/php /home/ACCOUNT/public_html/jayfoods/database/kafui-cleanup.php >/dev/null 2>&1
 */

$root = dirname(__DIR__);
foreach (['Response', 'Config', 'Database', 'SecretBox', 'SmtpMailer', 'EmailNotifications', 'Inventory'] as $class) {
    require $root . '/src/Support/' . $class . '.php';
}
require $root . '/src/Controllers/SiteContentController.php';
require $root . '/src/Agent/bootstrap.php';

$result = AgentMonitor::housekeeping();
echo '[kafui-cleanup] ' . json_encode($result) . "\n";
