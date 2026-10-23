<?php

declare(strict_types=1);

/**
 * Kafui's tools. This file is the security boundary between the model and
 * the business data (baseline TB-004):
 *  - the allowed tools come from the caller's role, which code sets from the
 *    channel identity, never from anything said in the chat (CTL-AI-001);
 *  - every argument is validated here;
 *  - every money amount is computed here from the admin catalogue, and an
 *    order can only be placed from a quote the customer already saw (CTL-AI-002);
 *  - customers only ever see their own orders (CTL-AUTHZ-001).
 */
final class KafuiTools
{
    public const MAX_TOOL_CALLS = 6;
    public const QUOTE_HOURS = 2;
    public const MAX_DAYS_AHEAD = 60;
    public const ORDERS_PER_DAY = 5;
    public const BOOKINGS_PER_DAY = 3;
    public const MAX_QUANTITY = 5000;

    private const CUSTOMER_TOOLS = ['get_menu', 'quote_order', 'place_order', 'create_booking', 'my_orders', 'request_invoice', 'handoff'];
    private const OWNER_TOOLS = ['sales_summary', 'list_orders', 'list_bookings', 'create_invoice'];

    /** @return list<string> */
    public static function allowedFor(string $role): array
    {
        return $role === 'owner' ? array_merge(self::CUSTOMER_TOOLS, self::OWNER_TOOLS) : self::CUSTOMER_TOOLS;
    }

    /** Tool definitions (JSON schema) for the tools this role may call. */
    public static function definitions(string $role, string $channel): array
    {
        $date = ['type' => 'string', 'description' => 'Date as YYYY-MM-DD'];
        $all = [
            'get_menu' => ['Get the current flavours, bottle sizes, prices, bulk prices, delivery areas and fees, pickup and opening hours.', []],
            'quote_order' => ['Price an order. Always call this before place_order and show the customer the summary it returns, word for word. Never work out prices yourself.', [
                'items' => ['type' => 'array', 'description' => 'What the customer wants', 'items' => ['type' => 'object', 'properties' => [
                    'size_id' => ['type' => 'integer', 'description' => 'size_id from the menu'],
                    'quantity' => ['type' => 'integer', 'description' => 'Number of bottles'],
                ], 'required' => ['size_id', 'quantity']]],
                'fulfilment' => ['type' => 'string', 'enum' => ['delivery', 'pickup']],
                'delivery_area' => ['type' => 'string', 'description' => 'Delivery area name exactly as in the menu (delivery only)'],
            ], ['items', 'fulfilment']],
            'place_order' => ['Place the order after the customer has seen the quote summary in an earlier message and clearly said yes.', [
                'quote_id' => ['type' => 'string', 'description' => 'quote_id from quote_order'],
                'customer_name' => ['type' => 'string'],
                'phone' => ['type' => 'string', 'description' => $channel === 'whatsapp' ? 'Only if the customer wants a different contact number' : 'Customer phone number'],
                'address' => ['type' => 'string', 'description' => 'Delivery address or landmark (delivery only)'],
                'requested_date' => $date,
                'notes' => ['type' => 'string'],
            ], $channel === 'whatsapp' ? ['quote_id', 'customer_name', 'requested_date'] : ['quote_id', 'customer_name', 'phone', 'requested_date']],
            'create_booking' => ['Book an event or bulk supply for a future date, or a call back from the team. The team confirms and quotes it.', [
                'kind' => ['type' => 'string', 'enum' => ['event', 'callback']],
                'customer_name' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'event_date' => $date,
                'event_time' => ['type' => 'string', 'description' => 'Time or part of day'],
                'venue' => ['type' => 'string'],
                'guests' => ['type' => 'integer'],
                'juices' => ['type' => 'string', 'description' => 'Juices and amounts wanted, in the customer\'s words'],
                'notes' => ['type' => 'string'],
            ], ['kind', 'customer_name']],
            'my_orders' => [$channel === 'whatsapp' ? 'Look up this customer\'s recent orders (matched on their WhatsApp number).' : 'Look up an order. Needs the order reference and the phone number used on the order.', $channel === 'whatsapp' ? [] : [
                'reference' => ['type' => 'string'], 'phone' => ['type' => 'string'],
            ], $channel === 'whatsapp' ? [] : ['reference', 'phone']],
            'request_invoice' => ['Ask for an invoice for one of the customer\'s own orders. It goes to the owner, who sends it on.', [
                'reference' => ['type' => 'string'],
                'phone' => ['type' => 'string', 'description' => 'Phone used on the order (website chat only)'],
            ], ['reference']],
            'handoff' => ['Hand the chat to a person: when the customer asks for one, has a complaint, wants something you cannot do, or you are not sure.', [
                'reason' => ['type' => 'string'],
            ], ['reason']],
            'sales_summary' => ['Owner only: orders and sales between two dates.', ['from' => $date, 'to' => $date], ['from', 'to']],
            'list_orders' => ['Owner only: recent orders, optionally by status or day.', [
                'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'processing', 'delivered', 'cancelled']],
                'date' => $date,
            ], []],
            'list_bookings' => ['Owner only: bookings and call-back requests.', [
                'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'declined', 'done']],
            ], []],
            'create_invoice' => ['Owner only: make an invoice PDF for any order and send it to the owner.', ['reference' => ['type' => 'string']], ['reference']],
        ];
        $out = [];
        foreach (self::allowedFor($role) as $name) {
            [$desc, $props, $required] = array_pad($all[$name], 3, []);
            $out[] = ['name' => $name, 'description' => $desc, 'parameters' => ['type' => 'object', 'properties' => (object) $props, 'required' => $required]];
        }
        return $out;
    }

    /**
     * Runs one tool call for the model. Always returns an array the model can
     * read; refusals and errors are explained, never thrown.
     */
    public static function run(string $name, array $args, KafuiContext $ctx): array
    {
        $ctx->toolCalls++;
        $logArgs = $args;
        if ($ctx->toolCalls > self::MAX_TOOL_CALLS) {
            AgentMonitor::action($ctx->conversationId(), $ctx->role, $name, $logArgs, 'refused', 'tool call limit');
            return ['error' => 'Too many steps for one message. Answer the customer with what you have.'];
        }
        if (!in_array($name, self::allowedFor($ctx->role), true)) {
            AgentMonitor::action($ctx->conversationId(), $ctx->role, $name, $logArgs, 'refused', 'not allowed for role');
            return ['error' => 'That is not available here.'];
        }
        try {
            $result = match ($name) {
                'get_menu' => self::getMenu($ctx),
                'quote_order' => self::quoteOrder($args, $ctx),
                'place_order' => self::placeOrder($args, $ctx),
                'create_booking' => self::createBooking($args, $ctx),
                'my_orders' => self::myOrders($args, $ctx),
                'request_invoice' => self::requestInvoice($args, $ctx),
                'handoff' => self::handoff($args, $ctx),
                'sales_summary' => self::salesSummary($args, $ctx),
                'list_orders' => self::listOrders($args, $ctx),
                'list_bookings' => self::listBookings($args, $ctx),
                'create_invoice' => self::createInvoice($args, $ctx),
            };
            $refused = isset($result['error']);
            AgentMonitor::action($ctx->conversationId(), $ctx->role, $name, $logArgs, $refused ? 'refused' : 'ok', $refused ? (string) $result['error'] : (string) ($result['log'] ?? ''));
            unset($result['log']);
            if (in_array($name, self::OWNER_TOOLS, true) && !$ctx->isTest()) {
                KafuiNotify::ownerToolUsed($name, $ctx->verifiedPhone());
            }
            return $result;
        } catch (Throwable $e) {
            error_log('[kafui] tool ' . $name . ' failed: ' . $e->getMessage());
            AgentMonitor::action($ctx->conversationId(), $ctx->role, $name, $logArgs, 'error', mb_substr($e->getMessage(), 0, 200));
            return ['error' => 'Something went wrong on our side. Offer to hand the chat to a person.'];
        }
    }

    // ------------------------------------------------------------------ catalogue

    /** @return list<array<string,mixed>> active sizes with their product */
    public static function catalogue(): array
    {
        return Database::connection()->query(
            'SELECT s.id AS size_id, p.id AS product_id, p.name, p.flavour, p.description, s.label, s.unit_price_pesewas,
                    s.bulk_min_quantity, s.bulk_price_pesewas, s.stock_quantity, p.bulk_available
               FROM product_sizes s JOIN products p ON p.id = s.product_id
              WHERE p.is_active = 1 AND s.is_active = 1
           ORDER BY p.name, s.sort_order, s.id'
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function zones(): array
    {
        return Database::connection()->query('SELECT id,name,fee_pesewas FROM delivery_zones WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();
    }

    public static function storeOpen(): bool
    {
        $v = Database::connection()->query("SELECT value FROM site_content WHERE content_key='store_accepting_orders'")->fetchColumn();
        return $v === false || $v === '1';
    }

    /** Every catalogue price and delivery fee, for the reply money check. @return list<int> */
    public static function publicAmounts(): array
    {
        $out = [];
        foreach (self::catalogue() as $c) {
            $out[] = (int) $c['unit_price_pesewas'];
            if ($c['bulk_price_pesewas'] !== null && (int) $c['bulk_available'] === 1) $out[] = (int) $c['bulk_price_pesewas'];
        }
        foreach (self::zones() as $z) $out[] = (int) $z['fee_pesewas'];
        return $out;
    }

    private static function getMenu(KafuiContext $ctx): array
    {
        $items = [];
        foreach (self::catalogue() as $c) {
            $bulk = (int) $c['bulk_available'] === 1 && $c['bulk_price_pesewas'] !== null && (int) $c['bulk_min_quantity'] > 0;
            $items[] = [
                'size_id' => (int) $c['size_id'],
                'juice' => $c['name'],
                'flavour' => $c['flavour'],
                'size' => $c['label'],
                'price' => KafuiText::money((int) $c['unit_price_pesewas']),
                'bulk_price' => $bulk ? KafuiText::money((int) $c['bulk_price_pesewas']) . ' each from ' . (int) $c['bulk_min_quantity'] . ' bottles' : null,
                'description' => $c['description'],
                'in_stock' => (int) $c['stock_quantity'] > 0,
            ];
        }
        $zones = array_map(static fn(array $z) => ['area' => $z['name'], 'delivery_fee' => KafuiText::money((int) $z['fee_pesewas'])], self::zones());
        return [
            'items' => $items,
            'delivery_areas' => $zones,
            'pickup' => AgentSettings::get('pickup_enabled') === '1' ? (AgentSettings::get('pickup_details') ?: 'available') : 'not available',
            'taking_orders' => self::storeOpen(),
        ];
    }

    // ------------------------------------------------------------------ quotes and orders

    /**
     * Prices items from the database. Shared by quote_order and place_order so
     * both always agree. @return array{error?:string,lines?:array,subtotal?:int,fee?:int,total?:int,zone?:?array,notes?:array}
     */
    public static function price(array $items, string $fulfilment, string $area): array
    {
        if (!in_array($fulfilment, ['delivery', 'pickup'], true)) return ['error' => 'Say delivery or pickup.'];
        if ($fulfilment === 'pickup' && AgentSettings::get('pickup_enabled') !== '1') return ['error' => 'Pickup is not available. Orders are delivered.'];
        if ($items === [] || count($items) > 20) return ['error' => 'Give between 1 and 20 items.'];

        $bySize = [];
        foreach (self::catalogue() as $c) $bySize[(int) $c['size_id']] = $c;
        $wanted = [];
        foreach ($items as $it) {
            if (!is_array($it)) return ['error' => 'Each item needs size_id and quantity.'];
            $sizeId = filter_var($it['size_id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($it['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($sizeId === false || !isset($bySize[$sizeId])) return ['error' => 'One of the items is not on the menu. Use a size_id from get_menu.'];
            if ($qty === false || $qty < 1 || $qty > self::MAX_QUANTITY) return ['error' => 'Quantities must be between 1 and ' . self::MAX_QUANTITY . '.'];
            $wanted[$sizeId] = ($wanted[$sizeId] ?? 0) + $qty;
        }

        $big = (int) AgentSettings::get('handoff_min_bottles');
        if ($big > 0 && array_sum($wanted) >= $big) {
            return ['error' => 'Orders of ' . $big . ' bottles or more are quoted by ' . (AgentSettings::get('handoff_name') ?: 'our team') . '. Take the details (items, date, place) and call handoff.'];
        }
        $lines = []; $subtotal = 0; $notes = [];
        foreach ($wanted as $sizeId => $qty) {
            if ($qty > self::MAX_QUANTITY) return ['error' => 'Quantities must be between 1 and ' . self::MAX_QUANTITY . '.'];
            $c = $bySize[$sizeId];
            $bulk = (int) $c['bulk_available'] === 1 && $c['bulk_price_pesewas'] !== null && (int) $c['bulk_min_quantity'] > 0 && $qty >= (int) $c['bulk_min_quantity'];
            $unit = $bulk ? (int) $c['bulk_price_pesewas'] : (int) $c['unit_price_pesewas'];
            $lines[] = [
                'product_id' => (int) $c['product_id'], 'size_id' => $sizeId,
                'product_name' => $c['name'] . ', ' . $c['label'],
                'unit_price_pesewas' => $unit, 'quantity' => $qty, 'is_bulk' => $bulk ? 1 : 0,
                'line_total_pesewas' => $unit * $qty,
            ];
            $subtotal += $unit * $qty;
            if ((int) $c['stock_quantity'] < $qty) $notes[] = $c['name'] . ' ' . $c['label'] . ': we have fewer in stock right now, so our team will confirm when it can be ready.';
        }

        $zone = null; $fee = 0;
        if ($fulfilment === 'delivery') {
            $area = trim($area);
            foreach (self::zones() as $z) if ($area !== '' && strcasecmp($z['name'], $area) === 0) $zone = $z;
            if ($zone === null) {
                return ['error' => 'Choose a delivery area from this list: ' . implode(', ', array_column(self::zones(), 'name')) . '.'];
            }
            $fee = (int) $zone['fee_pesewas'];
        }
        return ['lines' => $lines, 'subtotal' => $subtotal, 'fee' => $fee, 'total' => $subtotal + $fee, 'zone' => $zone, 'notes' => $notes];
    }

    public static function summaryText(array $priced, string $fulfilment): string
    {
        $out = [];
        foreach ($priced['lines'] as $l) {
            $out[] = $l['quantity'] . ' x ' . $l['product_name'] . ' at ' . KafuiText::money($l['unit_price_pesewas']) . ($l['is_bulk'] ? ' (bulk price)' : '') . ' = ' . KafuiText::money($l['line_total_pesewas']);
        }
        $out[] = $fulfilment === 'pickup' ? 'Pickup: no delivery fee'
            : ($priced['fee'] === 0 ? 'Delivery to ' . $priced['zone']['name'] . ': you pay the Uber or Yango rider directly'
                : 'Delivery to ' . $priced['zone']['name'] . ': ' . KafuiText::money($priced['fee']));
        $out[] = 'Total: ' . KafuiText::money($priced['total']);
        foreach ($priced['notes'] as $n) $out[] = $n;
        return implode("\n", $out);
    }

    private static function quoteOrder(array $args, KafuiContext $ctx): array
    {
        $fulfilment = (string) ($args['fulfilment'] ?? '');
        $priced = self::price(is_array($args['items'] ?? null) ? $args['items'] : [], $fulfilment, KafuiText::field($args['delivery_area'] ?? '', 60));
        if (isset($priced['error'])) return ['error' => $priced['error']];

        $id = 'Q' . strtoupper(bin2hex(random_bytes(5)));
        $payload = ['items' => array_map(static fn($l) => ['size_id' => $l['size_id'], 'quantity' => $l['quantity']], $priced['lines']), 'fulfilment' => $fulfilment, 'area' => $priced['zone']['name'] ?? '', 'turn_id' => $ctx->turnId];
        Database::connection()->prepare('INSERT INTO agent_quotes(id,conversation_id,payload_json,total_pesewas) VALUES(:id,:c,:p,:t)')
            ->execute([':id' => $id, ':c' => $ctx->conversationId(), ':p' => json_encode($payload), ':t' => $priced['total']]);

        $summary = self::summaryText($priced, $fulfilment);
        foreach ($priced['lines'] as $l) { $ctx->allowedAmounts[] = $l['unit_price_pesewas']; $ctx->allowedAmounts[] = $l['line_total_pesewas']; }
        $ctx->allowedAmounts[] = $priced['fee']; $ctx->allowedAmounts[] = $priced['subtotal']; $ctx->allowedAmounts[] = $priced['total'];
        $ctx->customerText = "Here is your order:\n" . $summary . "\n\nShall I place it?";
        return ['quote_id' => $id, 'summary' => $summary, 'total' => KafuiText::money($priced['total']), 'next_step' => 'Show the summary exactly, ask the customer to confirm, and collect anything still missing (name, date' . ($fulfilment === 'delivery' ? ', address' : '') . ').', 'log' => $id . ' ' . KafuiText::money($priced['total'])];
    }

    private static function placeOrder(array $args, KafuiContext $ctx): array
    {
        if (!self::storeOpen()) {
            $msg = Database::connection()->query("SELECT value FROM site_content WHERE content_key='store_closed_message'")->fetchColumn();
            return ['error' => 'We are not taking new orders right now. ' . ($msg ?: '') . ' Offer a booking or a person.'];
        }
        $db = Database::connection();
        $quoteId = KafuiText::field($args['quote_id'] ?? '', 20);
        $q = $db->prepare('SELECT * FROM agent_quotes WHERE id=:id AND conversation_id=:c');
        $q->execute([':id' => $quoteId, ':c' => $ctx->conversationId()]);
        $quote = $q->fetch();
        if (!$quote) return ['error' => 'No such quote in this chat. Call quote_order first and show the customer the summary.'];
        $payload = json_decode((string) $quote['payload_json'], true) ?: [];
        if ($quote['order_id'] !== null) {
            $o = $db->prepare('SELECT reference FROM orders WHERE id=:id'); $o->execute([':id' => $quote['order_id']]);
            return ['already_placed' => true, 'reference' => (string) $o->fetchColumn(), 'log' => 'repeat'];
        }
        if ((int) ($payload['turn_id'] ?? PHP_INT_MAX) >= $ctx->turnId) {
            return ['error' => 'The customer has not seen this quote yet. Show the summary and wait for them to say yes.'];
        }
        if (strtotime((string) $quote['created_at'] . ' UTC') < time() - self::QUOTE_HOURS * 3600) {
            return ['error' => 'That quote is more than ' . self::QUOTE_HOURS . ' hours old. Call quote_order again and show the new summary.'];
        }

        $name = KafuiText::field($args['customer_name'] ?? '', 80);
        if (mb_strlen($name) < 2) return ['error' => 'Ask for the customer\'s name.'];
        $phone = KafuiText::digits(KafuiText::field($args['phone'] ?? '', 20));
        if ($phone === '' && $ctx->channel() === 'whatsapp') $phone = $ctx->verifiedPhone();
        if (strlen($phone) < 9 || strlen($phone) > 15) return ['error' => 'Ask for a phone number our team can call.'];
        $date = self::date($args['requested_date'] ?? '', false, true);
        if ($date === null) return ['error' => 'Ask for the date they want it, from ' . self::earliestText() . ' up to ' . self::MAX_DAYS_AHEAD . ' days ahead.'];
        $fulfilment = (string) $payload['fulfilment'];
        $address = KafuiText::field($args['address'] ?? '', 200);
        if ($fulfilment === 'delivery' && mb_strlen($address) < 3) return ['error' => 'Ask for the delivery address or a landmark.'];
        $notes = KafuiText::field($args['notes'] ?? '', 300);

        // Re-price now: the admin may have changed a price since the quote.
        $priced = self::price($payload['items'] ?? [], $fulfilment, (string) ($payload['area'] ?? ''));
        if (isset($priced['error'])) return ['error' => $priced['error'] . ' Make a new quote.'];
        if ($priced['total'] !== (int) $quote['total_pesewas']) {
            return ['error' => 'Our prices changed since that quote. Call quote_order again and show the customer the new summary.'];
        }

        $todays = $db->prepare("SELECT COUNT(*) FROM orders WHERE source LIKE 'kafui%' AND created_at>=datetime('now','-1 day') AND (notes LIKE :conv OR customer_phone=:p)");
        $todays->execute([':conv' => '%[chat ' . $ctx->conversationId() . ']%', ':p' => $phone]);
        if ((int) $todays->fetchColumn() >= self::ORDERS_PER_DAY) {
            AgentMonitor::event('rate_limited', 'orders per day');
            return ['error' => 'This number has reached today\'s limit for chat orders. Hand the chat to a person.'];
        }

        $isBulk = (bool) array_filter($priced['lines'], static fn($l) => $l['is_bulk'] === 1);
        $reference = $ctx->isTest() ? 'TEST-' . strtoupper(bin2hex(random_bytes(3))) : 'JF-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $order = [
            'reference' => $reference, 'order_type' => $isBulk ? 'bulk' : 'single', 'customer_name' => $name, 'customer_phone' => '+' . ltrim($phone, '+'),
            'delivery_address' => $fulfilment === 'pickup' ? 'Pickup' : $address, 'region' => $fulfilment === 'pickup' ? 'Pickup' : (string) $priced['zone']['name'],
            'zone_id' => $priced['zone']['id'] ?? null, 'notes' => trim('Taken by Kafui on ' . $ctx->channel() . ' [chat ' . $ctx->conversationId() . ']. ' . $notes),
            'subtotal' => $priced['subtotal'], 'fee' => $priced['fee'], 'total' => $priced['total'], 'fulfilment' => $fulfilment, 'requested_date' => $date,
        ];

        if (!$ctx->isTest()) {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO orders (reference,order_type,customer_name,customer_phone,customer_email,delivery_address,region,delivery_zone_id,notes,subtotal_pesewas,promo_code,discount_pesewas,delivery_fee_pesewas,total_pesewas,status,payment_status,stock_state,reservation_expires_at,source,fulfilment,requested_date)
                              VALUES (:r,:t,:n,:p,'',:a,:reg,:z,:notes,:sub,'',0,:fee,:tot,'pending','unpaid','none','',:src,:f,:d)")
                   ->execute([':r' => $reference, ':t' => $order['order_type'], ':n' => $name, ':p' => $order['customer_phone'], ':a' => $order['delivery_address'], ':reg' => $order['region'],
                              ':z' => $order['zone_id'], ':notes' => $order['notes'], ':sub' => $order['subtotal'], ':fee' => $order['fee'], ':tot' => $order['total'],
                              ':src' => 'kafui-' . $ctx->channel(), ':f' => $fulfilment, ':d' => $date]);
                $orderId = (int) $db->lastInsertId();
                $item = $db->prepare('INSERT INTO order_items(order_id,product_id,size_id,product_name,unit_price_pesewas,quantity,is_bulk,line_total_pesewas) VALUES(:o,:p,:s,:n,:u,:q,:b,:l)');
                foreach ($priced['lines'] as $l) {
                    $item->execute([':o' => $orderId, ':p' => $l['product_id'], ':s' => $l['size_id'], ':n' => $l['product_name'], ':u' => $l['unit_price_pesewas'], ':q' => $l['quantity'], ':b' => $l['is_bulk'], ':l' => $l['line_total_pesewas']]);
                }
                $db->prepare('INSERT INTO order_status_history(order_id,status,note) VALUES(:id,\'pending\',:note)')->execute([':id' => $orderId, ':note' => 'Order taken by Kafui on ' . $ctx->channel() . ', to confirm']);
                $used = $db->prepare('UPDATE agent_quotes SET order_id=:o WHERE id=:q AND order_id IS NULL');
                $used->execute([':o' => $orderId, ':q' => $quoteId]);
                if ($used->rowCount() !== 1) throw new RuntimeException('Quote already used.');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            KafuiNotify::order(array_merge($order, ['delivery_fee_pesewas' => $order['fee'], 'total_pesewas' => $order['total']]), $priced['lines'], $ctx->channel() === 'web' ? 'the website chat' : 'WhatsApp');
        }

        $ctx->allowedAmounts[] = $priced['total'];
        $payment = AgentSettings::get('payment_note');
        $ctx->customerText = 'Thank you, ' . $name . '! Your order ' . $reference . ' is in: ' . KafuiText::money($priced['total']) . ' in total, for ' . $date . '. '
            . 'It is pending until our team confirms it. ' . $payment . ' We will contact you on +' . ltrim($phone, '+') . '.';
        return ['reference' => $reference, 'total' => KafuiText::money($priced['total']), 'status' => 'pending until the team confirms', 'payment' => $payment, 'tell_customer' => $ctx->customerText, 'log' => $reference];
    }

    // ------------------------------------------------------------------ bookings

    private static function createBooking(array $args, KafuiContext $ctx): array
    {
        $kind = ($args['kind'] ?? '') === 'callback' ? 'callback' : (($args['kind'] ?? '') === 'event' ? 'event' : '');
        if ($kind === '') return ['error' => 'Say whether this is an event booking or a call back.'];
        $name = KafuiText::field($args['customer_name'] ?? '', 80);
        if (mb_strlen($name) < 2) return ['error' => 'Ask for the customer\'s name.'];
        $phone = KafuiText::digits(KafuiText::field($args['phone'] ?? '', 20));
        if ($phone === '' && $ctx->channel() === 'whatsapp') $phone = $ctx->verifiedPhone();
        if (strlen($phone) < 9 || strlen($phone) > 15) return ['error' => 'Ask for a phone number our team can call.'];
        $date = '';
        if ($kind === 'event' || trim((string) ($args['event_date'] ?? '')) !== '') {
            $date = self::date($args['event_date'] ?? '');
            if ($date === null) return ['error' => 'Ask for the date, from today up to ' . self::MAX_DAYS_AHEAD . ' days ahead. For later dates, hand the chat to a person.'];
        }
        $guests = filter_var($args['guests'] ?? 0, FILTER_VALIDATE_INT);
        $guests = ($guests === false || $guests < 0) ? 0 : min($guests, 100000);
        $b = [
            'kind' => $kind, 'customer_name' => $name, 'customer_phone' => '+' . $phone, 'event_date' => (string) $date,
            'event_time' => KafuiText::field($args['event_time'] ?? '', 40), 'venue' => KafuiText::field($args['venue'] ?? '', 160),
            'guests' => $guests, 'juices' => KafuiText::field($args['juices'] ?? '', 300), 'notes' => KafuiText::field($args['notes'] ?? '', 300),
        ];

        $db = Database::connection();
        $recent = $db->prepare("SELECT COUNT(*) FROM bookings WHERE created_at>=datetime('now','-1 day') AND (conversation_id=:c OR customer_phone=:p)");
        $recent->execute([':c' => $ctx->conversationId(), ':p' => $b['customer_phone']]);
        if ((int) $recent->fetchColumn() >= self::BOOKINGS_PER_DAY) {
            AgentMonitor::event('rate_limited', 'bookings per day');
            return ['error' => 'This number has reached today\'s booking limit. Hand the chat to a person.'];
        }
        $dupe = $db->prepare("SELECT reference FROM bookings WHERE conversation_id=:c AND kind=:k AND event_date=:d AND created_at>=datetime('now','-10 minutes')");
        $dupe->execute([':c' => $ctx->conversationId(), ':k' => $kind, ':d' => $b['event_date']]);
        if ($existing = $dupe->fetchColumn()) return ['already_booked' => true, 'reference' => $existing];

        $reference = ($ctx->isTest() ? 'TEST-' : 'JB-' . gmdate('Ymd') . '-') . strtoupper(bin2hex(random_bytes(3)));
        $b['reference'] = $reference;
        if (!$ctx->isTest()) {
            $db->prepare('INSERT INTO bookings(reference,kind,conversation_id,customer_name,customer_phone,event_date,event_time,venue,guests,juices,notes) VALUES(:r,:k,:c,:n,:p,:d,:t,:v,:g,:j,:no)')
               ->execute([':r' => $reference, ':k' => $kind, ':c' => $ctx->conversationId(), ':n' => $name, ':p' => $b['customer_phone'], ':d' => $b['event_date'], ':t' => $b['event_time'], ':v' => $b['venue'], ':g' => $guests, ':j' => $b['juices'], ':no' => $b['notes']]);
            KafuiNotify::booking($b);
        }
        $ctx->customerText = $kind === 'callback'
            ? 'Done, ' . $name . '. I have asked our team to call you on +' . $phone . '. Your reference is ' . $reference . '.'
            : 'Thank you, ' . $name . '. Your booking request ' . $reference . ' for ' . $date . ' is in. Our team will confirm it and send you a price.';
        return ['reference' => $reference, 'status' => 'pending until the team confirms', 'tell_customer' => $ctx->customerText, 'log' => $reference];
    }

    // ------------------------------------------------------------------ the customer's own orders

    /** @return array<string,mixed>|null the order when it belongs to this caller */
    private static function ownOrder(string $reference, string $phone, KafuiContext $ctx): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM orders WHERE reference=:r');
        $s->execute([':r' => strtoupper(KafuiText::field($reference, 40))]);
        $o = $s->fetch();
        if (!$o) return null;
        if ($ctx->role === 'owner') return $o;
        $mine = $ctx->channel() === 'whatsapp' ? $ctx->verifiedPhone() : $phone;
        return KafuiText::samePhone((string) $o['customer_phone'], $mine) ? $o : null;
    }

    private static function orderView(array $o): array
    {
        $items = Database::connection()->prepare('SELECT product_name,quantity,line_total_pesewas FROM order_items WHERE order_id=:id');
        $items->execute([':id' => $o['id']]);
        return [
            'reference' => $o['reference'], 'placed' => substr((string) $o['created_at'], 0, 10), 'status' => $o['status'],
            'payment' => $o['payment_status'] === 'paid' ? 'paid' : 'not paid yet', 'total' => KafuiText::money((int) $o['total_pesewas']),
            'items' => array_map(static fn($i) => $i['quantity'] . ' x ' . $i['product_name'], $items->fetchAll()),
        ];
    }

    private static function myOrders(array $args, KafuiContext $ctx): array
    {
        if ($ctx->channel() === 'whatsapp') {
            $phone = $ctx->verifiedPhone();
            $rows = [];
            if (strlen($phone) >= 9) {
                $s = Database::connection()->prepare("SELECT * FROM orders WHERE substr(replace(replace(customer_phone,'+',''),' ',''),-9)=:p ORDER BY created_at DESC LIMIT 5");
                $s->execute([':p' => substr($phone, -9)]);
                $rows = $s->fetchAll();
            }
            foreach ($rows as $r) $ctx->allowedAmounts[] = (int) $r['total_pesewas'];
            return ['orders' => array_map([self::class, 'orderView'], $rows), 'log' => count($rows) . ' orders'];
        }
        $o = self::ownOrder((string) ($args['reference'] ?? ''), KafuiText::digits((string) ($args['phone'] ?? '')), $ctx);
        if (!$o) return ['error' => 'No order matches that reference and phone number.'];
        $ctx->allowedAmounts[] = (int) $o['total_pesewas'];
        return ['orders' => [self::orderView($o)], 'log' => $o['reference']];
    }

    private static function requestInvoice(array $args, KafuiContext $ctx): array
    {
        $o = self::ownOrder((string) ($args['reference'] ?? ''), KafuiText::digits((string) ($args['phone'] ?? '')), $ctx);
        if (!$o) return ['error' => 'No order matches that reference' . ($ctx->channel() === 'whatsapp' ? ' for this WhatsApp number.' : ' and phone number.')];
        if (!$ctx->isTest()) {
            $invoice = Invoices::create((int) $o['id']);
            KafuiNotify::invoice($invoice, 'A customer asked Kafui for an invoice for order ' . $o['reference'] . ' (' . $o['customer_name'] . '). Check it and send it to them.');
            foreach (AgentSettings::ownerNumbers() as $owner) {
                WhatsAppSender::sendDocument($owner['number'], $invoice['url'], $invoice['filename'], 'Invoice requested by ' . $o['customer_name'] . ' for ' . $o['reference'] . '. Please check and send it on.');
            }
        }
        $ctx->customerText = 'I have sent your invoice request for ' . $o['reference'] . ' to the owner. They will check it and send it to you.';
        return ['sent_to_owner' => true, 'tell_customer' => $ctx->customerText, 'log' => $o['reference']];
    }

    private static function handoff(array $args, KafuiContext $ctx): array
    {
        $reason = KafuiText::field($args['reason'] ?? '', 200) ?: 'Customer asked for a person';
        Kafui::handOff($ctx, $reason);
        return ['handed_off' => true, 'tell_customer' => $ctx->customerText, 'log' => $reason];
    }

    // ------------------------------------------------------------------ owner tools (read-only, plus invoices)

    private static function salesSummary(array $args, KafuiContext $ctx): array
    {
        $from = self::date($args['from'] ?? '', true); $to = self::date($args['to'] ?? '', true);
        if ($from === null || $to === null || $from > $to) return ['error' => 'Give from and to dates as YYYY-MM-DD.'];
        if ((strtotime($to) - strtotime($from)) / 86400 > 92) return ['error' => 'Ask for at most three months at a time.'];
        $db = Database::connection();
        $s = $db->prepare("SELECT COUNT(*) n, SUM(status!='cancelled') live, SUM(CASE WHEN payment_status='paid' AND status!='cancelled' THEN total_pesewas ELSE 0 END) paid,
                                  SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending, SUM(CASE WHEN source LIKE 'kafui%' THEN 1 ELSE 0 END) kafui,
                                  SUM(CASE WHEN status!='cancelled' THEN total_pesewas ELSE 0 END) ordered
                             FROM orders WHERE date(created_at) BETWEEN :f AND :t");
        $s->execute([':f' => $from, ':t' => $to]);
        $r = $s->fetch();
        $top = $db->prepare("SELECT oi.product_name, SUM(oi.quantity) q FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status!='cancelled' AND date(o.created_at) BETWEEN :f AND :t GROUP BY oi.product_name ORDER BY q DESC LIMIT 5");
        $top->execute([':f' => $from, ':t' => $to]);
        $ctx->allowedAmounts[] = (int) $r['paid']; $ctx->allowedAmounts[] = (int) $r['ordered'];
        return [
            'from' => $from, 'to' => $to, 'orders' => (int) $r['n'], 'not_cancelled' => (int) $r['live'], 'pending' => (int) $r['pending'],
            'taken_by_kafui' => (int) $r['kafui'], 'value_ordered' => KafuiText::money((int) $r['ordered']), 'paid_sales' => KafuiText::money((int) $r['paid']),
            'top_items' => array_map(static fn($t) => $t['q'] . ' x ' . $t['product_name'], $top->fetchAll()), 'log' => "$from to $to",
        ];
    }

    private static function listOrders(array $args, KafuiContext $ctx): array
    {
        $where = []; $params = [];
        $status = (string) ($args['status'] ?? '');
        if (in_array($status, ['pending', 'confirmed', 'processing', 'delivered', 'cancelled'], true)) { $where[] = 'status=:s'; $params[':s'] = $status; }
        if (trim((string) ($args['date'] ?? '')) !== '') {
            $d = self::date($args['date'], true);
            if ($d === null) return ['error' => 'Give the date as YYYY-MM-DD.'];
            $where[] = 'date(created_at)=:d'; $params[':d'] = $d;
        }
        $s = Database::connection()->prepare('SELECT reference,customer_name,total_pesewas,status,payment_status,source,created_at FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 20');
        $s->execute($params);
        $rows = $s->fetchAll();
        foreach ($rows as $r) $ctx->allowedAmounts[] = (int) $r['total_pesewas'];
        return ['orders' => array_map(static fn($r) => [
            'reference' => $r['reference'], 'customer' => $r['customer_name'], 'total' => KafuiText::money((int) $r['total_pesewas']),
            'status' => $r['status'], 'payment' => $r['payment_status'], 'via' => str_starts_with((string) $r['source'], 'kafui') ? 'Kafui' : 'website', 'placed' => $r['created_at'],
        ], $rows), 'log' => count($rows) . ' orders'];
    }

    private static function listBookings(array $args, KafuiContext $ctx): array
    {
        $status = (string) ($args['status'] ?? '');
        $ok = in_array($status, ['pending', 'confirmed', 'declined', 'done'], true);
        $s = Database::connection()->prepare('SELECT reference,kind,customer_name,customer_phone,event_date,event_time,venue,guests,juices,status FROM bookings' . ($ok ? ' WHERE status=:s' : '') . ' ORDER BY created_at DESC LIMIT 20');
        $s->execute($ok ? [':s' => $status] : []);
        $rows = $s->fetchAll();
        return ['bookings' => $rows, 'log' => count($rows) . ' bookings'];
    }

    private static function createInvoice(array $args, KafuiContext $ctx): array
    {
        $o = self::ownOrder((string) ($args['reference'] ?? ''), '', $ctx);
        if (!$o) return ['error' => 'No order with that reference.'];
        $ctx->allowedAmounts[] = (int) $o['total_pesewas'];
        if ($ctx->isTest()) {
            $ctx->customerText = 'Test chat: the invoice for ' . $o['reference'] . ' would be sent to you here as a PDF.';
            return ['created' => true, 'tell_customer' => $ctx->customerText, 'log' => $o['reference']];
        }
        $invoice = Invoices::create((int) $o['id']);
        $ctx->document = ['link' => $invoice['url'], 'filename' => $invoice['filename'], 'caption' => 'Invoice ' . $o['reference'] . ', total ' . KafuiText::money((int) $o['total_pesewas'])];
        KafuiNotify::invoice($invoice, 'You asked Kafui for this invoice on WhatsApp.');
        $ctx->customerText = 'Here is the invoice for ' . $o['reference'] . ' (' . KafuiText::money((int) $o['total_pesewas']) . '). I have also emailed you the link.';
        return ['created' => true, 'sent_as' => 'PDF in this chat and by email', 'tell_customer' => $ctx->customerText, 'log' => $o['reference']];
    }

    // ------------------------------------------------------------------ helpers

    private static function earliestText(): string
    {
        $days = max(0, (int) AgentSettings::get('min_days_ahead'));
        return $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : $days . ' days from today');
    }

    /** YYYY-MM-DD from the earliest delivery day up to MAX_DAYS_AHEAD (or any past date for owner reports). */
    private static function date(mixed $value, bool $allowPast = false, bool $orderDate = false): ?string
    {
        $v = trim(is_scalar($value) ? (string) $value : '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $v) return null;
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($allowPast) return $d <= $today->modify('+1 day') ? $v : null;
        $earliest = $orderDate ? $today->modify('+' . max(0, (int) AgentSettings::get('min_days_ahead')) . ' days') : $today;
        if ($d < $earliest || $d > $today->modify('+' . self::MAX_DAYS_AHEAD . ' days')) return null;
        return $v;
    }
}
