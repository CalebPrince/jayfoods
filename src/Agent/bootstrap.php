<?php

declare(strict_types=1);

// Loads Kafui. Used by public/index.php, the tests and database/kafui-cleanup.php.
$agentDir = __DIR__;
require_once dirname($agentDir) . '/Support/Signer.php';
foreach ([
    'KafuiText', 'AgentHttp', 'AgentSettings', 'WhatsAppCloud', 'InvoicePdf', 'Invoices', 'KafuiStore',
    'AgentMonitor', 'KafuiNotify', 'KafuiContext', 'KafuiTools', 'KafuiPrompt', 'ModelProviders', 'Kafui',
] as $class) {
    require_once $agentDir . '/' . $class . '.php';
}
require_once dirname($agentDir) . '/Controllers/WhatsAppWebhookController.php';
require_once dirname($agentDir) . '/Controllers/ChatController.php';
require_once dirname($agentDir) . '/Controllers/AdminAgentController.php';
