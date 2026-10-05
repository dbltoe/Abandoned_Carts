<?php
/**
 * Abandoned Carts -- recording carts.
 *
 * A cart is recorded only once the shopper can be emailed: a customer with an
 * account, or a One Page Checkout guest who has saved their contact details.
 * Anonymous carts never reach the database, and neither does a shopper the
 * settings say can't be emailed (AbandonedCartsCore::consentProblem(), checked
 * by the caller); forget() deletes what was kept before they said no.
 *
 * Identity, as the spikes on _test223 proved it has to be:
 *
 *   - The plugin's own key (32 hex characters) lives in the session. Zen Cart
 *     regenerates the session id at login and can keep it across a logoff, so
 *     the session id is never the key.
 *   - An OPC guest has the shared placeholder customers_id; a guest's cart is
 *     found by the session key, then by email, never by customers_id.
 *   - A customer's cart is found by the session key, then by customers_id, so a
 *     customer who comes back in a new session keeps one record.
 *
 * Tables are often MyISAM on upgraded stores, so nothing here relies on a
 * transaction.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsCore', false)) {
    require_once __DIR__ . '/AbandonedCartsCore.php';
}

class AbandonedCartsTracker
{
    /** @var object queryFactory (or the harness's FakeDb) */
    protected $db;

    /** @var string Y-m-d H:i:s */
    protected $now;

    public function __construct($db, ?string $now = null)
    {
        AbandonedCartsCore::defineTables();
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
    }

    /**
     * Record (or update) the shopper's cart.
     *
     * @param array $shopper  key, session_id, customers_id, is_guest, opted_in, email, firstname, languages_id, language, currency
     * @param array $snapshot contents, lines, item_count, cart_total
     * @return int the cart record's id, 0 when the shopper can't be emailed
     */
    public function record(array $shopper, array $snapshot): int
    {
        $email = trim((string)($shopper['email'] ?? ''));
        $customerId = empty($shopper['is_guest']) ? (int)($shopper['customers_id'] ?? 0) : 0;
        if ($email === '' || (string)($shopper['key'] ?? '') === '') {
            return 0;
        }
        if ((float)($snapshot['item_count'] ?? 0) <= 0) {
            $this->emptied($shopper);
            return 0;
        }

        $row = $this->openFor($shopper);
        $step = $row === null ? 0 : (int)$row['step'];
        $fields = [
            'session_key' => (string)$shopper['key'],
            'session_id' => substr((string)($shopper['session_id'] ?? ''), 0, 128),
            'customers_id' => $customerId,
            'is_guest' => $customerId > 0 ? 0 : 1,
            'opted_in' => $customerId === 0 && !empty($shopper['opted_in']) ? 1 : 0,
            'email' => substr($email, 0, 96),
            'firstname' => substr(trim((string)($shopper['firstname'] ?? '')), 0, 64),
            'languages_id' => (int)($shopper['languages_id'] ?? 0),
            'language' => substr((string)($shopper['language'] ?? ''), 0, 32),
            'currency' => substr((string)($shopper['currency'] ?? ''), 0, 3),
            'contents' => json_encode($snapshot['contents'] ?? []),
            'cart_lines' => json_encode($snapshot['lines'] ?? []),
            'item_count' => (float)$snapshot['item_count'],
            'cart_total' => round((float)($snapshot['cart_total'] ?? 0), 4),
            'last_activity' => $this->now,
            // Each change pushes the next reminder back: nobody is emailed while still shopping.
            'next_send' => $this->addHours($this->now, AbandonedCartsCore::stepDelayHours($step + 1)),
            'last_modified' => $this->now,
        ];

        if ($row === null) {
            $fields['status'] = AbandonedCartsCore::OPEN;
            $fields['date_added'] = $this->now;
            $this->insert(TABLE_ABANDONED_CARTS, $fields);
            $id = (int)$this->db->Insert_ID();
            $this->event($id, 'tracked', 0, $customerId > 0 ? 'customer ' . $customerId : 'guest');
        } else {
            $id = (int)$row['abandoned_carts_id'];
            $this->update(TABLE_ABANDONED_CARTS, $fields, 'abandoned_carts_id = ' . $id);
            if ($customerId > 0 && (int)$row['customers_id'] !== $customerId) {
                $this->event($id, 'logged_in', $step, 'customer ' . $customerId);
            }
        }

        // One open cart per shopper: a customer back in a new session, or a guest
        // who logged in, folds the older record into this one.
        $other = $customerId > 0 ? 'customers_id = ' . $customerId : "email = '" . $this->db->prepare_input($email) . "' AND customers_id = 0";
        $this->db->Execute(
            "UPDATE " . TABLE_ABANDONED_CARTS . " SET status = 'merged', last_modified = '" . $this->now . "'"
            . " WHERE " . $other . " AND status = 'open' AND abandoned_carts_id <> " . $id
        );
        return $id;
    }

    /**
     * Delete this shopper's open cart record(s) and their history: they're not
     * to be emailed (a guest who unticked "Email me a reminder", or settings
     * that rule them out), so nothing about them is kept.
     *
     * @return int how many records were deleted
     */
    public function forget(array $shopper): int
    {
        $where = ["session_key = '" . $this->db->prepare_input((string)($shopper['key'] ?? '-')) . "'"];
        $customerId = empty($shopper['is_guest']) ? (int)($shopper['customers_id'] ?? 0) : 0;
        $email = trim((string)($shopper['email'] ?? ''));
        if ($customerId > 0) {
            $where[] = 'customers_id = ' . $customerId;
        } elseif ($email !== '') {
            $where[] = "email = '" . $this->db->prepare_input($email) . "' AND customers_id = 0";
        }
        $ids = [];
        foreach ($where as $w) {
            $r = AbandonedCartsCore::fresh($this->db, "SELECT abandoned_carts_id FROM " . TABLE_ABANDONED_CARTS . " WHERE " . $w . " AND status = 'open'");
            while (!$r->EOF) {
                $ids[] = (int)$r->fields['abandoned_carts_id'];
                $r->MoveNext();
            }
        }
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            $this->delete($id);
        }
        return count($ids);
    }

    /** Delete one cart record and its history. */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->db->Execute("DELETE FROM " . TABLE_ABANDONED_CARTS_EVENTS . " WHERE abandoned_carts_id = " . $id);
        $this->db->Execute("DELETE FROM " . TABLE_ABANDONED_CARTS . " WHERE abandoned_carts_id = " . $id);
    }

    /** The shopper emptied the cart: nothing left to remind them about. */
    public function emptied(array $shopper): void
    {
        $row = $this->openFor($shopper);
        if ($row !== null) {
            $this->setStatus((int)$row['abandoned_carts_id'], 'emptied', (int)$row['step']);
        }
    }

    /**
     * An order was placed: every open cart of this shopper is closed, as
     * recovered when a reminder had gone out, else simply ordered.
     *
     * @return int[] the cart ids closed
     */
    public function ordered(array $shopper, int $ordersId, string $orderEmail, float $orderTotal): array
    {
        $where = ["session_key = '" . $this->db->prepare_input((string)($shopper['key'] ?? '-')) . "'"];
        $customerId = empty($shopper['is_guest']) ? (int)($shopper['customers_id'] ?? 0) : 0;
        if ($customerId > 0) {
            $where[] = 'customers_id = ' . $customerId;
        }
        $orderEmail = trim($orderEmail);
        if ($orderEmail !== '') {
            $where[] = "email = '" . $this->db->prepare_input($orderEmail) . "'";
        }
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT abandoned_carts_id, step FROM " . TABLE_ABANDONED_CARTS . " WHERE status = 'open' AND (" . implode(' OR ', $where) . ")"
        );
        $closed = [];
        while (!$r->EOF) {
            $closed[] = $this->closeAsOrdered((int)$r->fields['abandoned_carts_id'], (int)$r->fields['step'], $ordersId, $orderTotal);
            $r->MoveNext();
        }
        return $closed;
    }

    public function closeAsOrdered(int $id, int $step, int $ordersId, float $orderTotal): int
    {
        $status = $step > 0 ? 'recovered' : 'ordered';
        $this->update(TABLE_ABANDONED_CARTS, [
            'status' => $status,
            'orders_id' => $ordersId,
            'order_total' => round($orderTotal, 4),
            'last_modified' => $this->now,
        ], 'abandoned_carts_id = ' . $id);
        $this->event($id, $status, $step, 'order ' . $ordersId);
        return $id;
    }

    /** The open cart for this shopper: by session key, then by customer or guest email. */
    public function openFor(array $shopper): ?array
    {
        $tries = ["session_key = '" . $this->db->prepare_input((string)($shopper['key'] ?? '')) . "'"];
        $customerId = empty($shopper['is_guest']) ? (int)($shopper['customers_id'] ?? 0) : 0;
        $email = trim((string)($shopper['email'] ?? ''));
        if ($customerId > 0) {
            $tries[] = 'customers_id = ' . $customerId;
        } elseif ($email !== '') {
            $tries[] = "email = '" . $this->db->prepare_input($email) . "' AND customers_id = 0";
        }
        foreach ($tries as $where) {
            $r = AbandonedCartsCore::fresh(
                $this->db,
                "SELECT * FROM " . TABLE_ABANDONED_CARTS . " WHERE " . $where . " AND status = 'open' ORDER BY abandoned_carts_id DESC LIMIT 1"
            );
            if (!$r->EOF) {
                return $r->fields;
            }
        }
        return null;
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = AbandonedCartsCore::fresh($this->db, "SELECT * FROM " . TABLE_ABANDONED_CARTS . " WHERE abandoned_carts_id = " . $id . " LIMIT 1");
        return $r->EOF ? null : $r->fields;
    }

    /**
     * Put a recorded cart back into the shopper's cart (the Return to Your
     * Cart link). Lines already in the cart keep their quantity; products that
     * have since been disabled or deleted are skipped. The snapshot is written
     * into $cart->contents the way core's restore_contents() builds it, which
     * keeps text and checkbox options exactly as chosen.
     *
     * @param object $cart the session's shoppingCart
     * @return array{restored:int, skipped:int}
     */
    public function restoreInto(array $row, $cart): array
    {
        $out = ['restored' => 0, 'skipped' => 0];
        $contents = json_decode((string)$row['contents'], true);
        if (!is_array($contents) || !is_object($cart)) {
            return $out;
        }
        if (!is_array($cart->contents)) {
            $cart->contents = [];
        }
        foreach ($contents as $uprid => $item) {
            $pid = (int)$uprid;
            if ($pid <= 0 || !is_array($item) || (float)($item['qty'] ?? 0) <= 0) {
                continue;
            }
            if (!$this->productAvailable($pid)) {
                $out['skipped']++;
                continue;
            }
            if (!isset($cart->contents[$uprid])) {
                $cart->contents[$uprid] = $item;
            }
            $out['restored']++;
        }
        if (method_exists($cart, 'cleanup')) {
            $cart->cleanup();
        }
        if (method_exists($cart, 'generate_cart_id')) {
            $cart->cartID = $cart->generate_cart_id();
        }
        return $out;
    }

    protected function productAvailable(int $productsId): bool
    {
        $r = AbandonedCartsCore::fresh($this->db, "SELECT products_status FROM " . TABLE_PRODUCTS . " WHERE products_id = " . $productsId . " LIMIT 1");
        return !$r->EOF && (int)$r->fields['products_status'] === 1;
    }

    /* ----------------------------------------------------------------- *
     * Unsubscribe list
     * ----------------------------------------------------------------- */

    public function isUnsubscribed(string $email): bool
    {
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT email_hash FROM " . TABLE_ABANDONED_CARTS_UNSUBSCRIBES . " WHERE email_hash = '" . AbandonedCartsCore::emailHash($email) . "' LIMIT 1"
        );
        return !$r->EOF;
    }

    /** Add the address to the list and close its open carts. Safe to repeat. */
    public function unsubscribe(string $email): void
    {
        if (trim($email) === '') {
            return;
        }
        if (!$this->isUnsubscribed($email)) {
            $this->insert(TABLE_ABANDONED_CARTS_UNSUBSCRIBES, ['email_hash' => AbandonedCartsCore::emailHash($email), 'date_added' => $this->now]);
        }
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT abandoned_carts_id, step FROM " . TABLE_ABANDONED_CARTS . " WHERE email = '" . $this->db->prepare_input(trim($email)) . "' AND status = 'open'"
        );
        while (!$r->EOF) {
            $this->setStatus((int)$r->fields['abandoned_carts_id'], 'unsubscribed', (int)$r->fields['step']);
            $r->MoveNext();
        }
    }

    /* ----------------------------------------------------------------- *
     * The snapshot
     * ----------------------------------------------------------------- */

    /**
     * What gets stored for a cart: the cart's own contents (restored exactly by
     * the Return to Your Cart link) and display lines for the email, priced
     * with tax the way the shopper saw them, in the default currency.
     *
     * @param array    $contents   $_SESSION['cart']->contents
     * @param array    $products   $_SESSION['cart']->get_products()
     * @param callable $lineTotal  (array product): float, the line's price as shown
     */
    public function snapshot(array $contents, array $products, int $languagesId, callable $lineTotal): array
    {
        $lines = [];
        $count = 0.0;
        $total = 0.0;
        foreach ($products as $p) {
            $qty = (float)($p['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $amount = round((float)$lineTotal($p), 4);
            $pid = (int)$p['id'];
            $lines[] = [
                'uprid' => (string)$p['id'],
                'products_id' => $pid,
                'name' => $this->productName($pid, $languagesId, (string)($p['name'] ?? '')),
                'model' => (string)($p['model'] ?? ''),
                'image' => (string)($p['image'] ?? ''),
                'quantity' => $qty,
                'line_total' => $amount,
                'options' => $this->optionLabels((array)($p['attributes'] ?? []), (array)($p['attributes_values'] ?? []), $languagesId),
            ];
            $count += $qty;
            $total += $amount;
        }
        return ['contents' => $contents, 'lines' => $lines, 'item_count' => $count, 'cart_total' => round($total, 4)];
    }

    /**
     * The catalog name, not the cart's: an option-stock plugin can append
     * "[In Stock]" to the cart's copy.
     */
    protected function productName(int $productsId, int $languagesId, string $fallback): string
    {
        if (defined('TABLE_PRODUCTS_DESCRIPTION') && $productsId > 0) {
            $r = AbandonedCartsCore::fresh(
                $this->db,
                "SELECT products_name FROM " . TABLE_PRODUCTS_DESCRIPTION . " WHERE products_id = " . $productsId . " AND language_id = " . $languagesId . " LIMIT 1"
            );
            if (!$r->EOF && trim((string)$r->fields['products_name']) !== '') {
                return trim((string)$r->fields['products_name']);
            }
        }
        return trim($fallback);
    }

    /** "Size: Medium", "Gift Message: Happy Birthday" -- in the shopper's language. */
    protected function optionLabels(array $attributes, array $values, int $languagesId): array
    {
        $labels = [];
        foreach ($attributes as $key => $valueId) {
            [$optionId, $checkbox] = AbandonedCartsCore::splitAttributeKey($key);
            if ($optionId <= 0) {
                continue;
            }
            $option = $this->lookup(TABLE_PRODUCTS_OPTIONS, 'products_options_name', 'products_options_id', $optionId, $languagesId);
            if (isset($values[$optionId]) && (string)$values[$optionId] !== '') {
                $labels[] = AbandonedCartsCore::optionLabel($option, (string)$values[$optionId]);
                continue;
            }
            $value = $this->lookup(TABLE_PRODUCTS_OPTIONS_VALUES, 'products_options_values_name', 'products_options_values_id', $checkbox ?? (int)$valueId, $languagesId);
            if ($value !== '') {
                $labels[] = AbandonedCartsCore::optionLabel($option, $value);
            }
        }
        return $labels;
    }

    protected function lookup(string $table, string $column, string $idColumn, int $id, int $languagesId): string
    {
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT " . $column . " FROM " . $table . " WHERE " . $idColumn . " = " . $id . " AND language_id = " . $languagesId . " LIMIT 1"
        );
        return $r->EOF ? '' : trim((string)$r->fields[$column]);
    }

    /* ----------------------------------------------------------------- *
     * Small helpers
     * ----------------------------------------------------------------- */

    public function event(int $cartId, string $event, int $step = 0, string $detail = ''): void
    {
        if ($cartId <= 0) {
            return;
        }
        $this->insert(TABLE_ABANDONED_CARTS_EVENTS, [
            'abandoned_carts_id' => $cartId,
            'event' => substr($event, 0, 32),
            'step' => $step,
            'detail' => substr($detail, 0, 255),
            'date_added' => $this->now,
        ]);
    }

    public function setStatus(int $id, string $status, int $step = 0, string $detail = ''): void
    {
        $this->update(TABLE_ABANDONED_CARTS, ['status' => $status, 'last_modified' => $this->now], 'abandoned_carts_id = ' . $id);
        $this->event($id, $status, $step, $detail);
    }

    public function addHours(string $datetime, int $hours): string
    {
        return date('Y-m-d H:i:s', strtotime($datetime) + $hours * 3600);
    }

    protected function insert(string $table, array $fields): void
    {
        $cols = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $cols[] = $k;
            $vals[] = $this->quote($v);
        }
        $this->db->Execute("INSERT INTO " . $table . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")");
    }

    protected function update(string $table, array $fields, string $where): void
    {
        $set = [];
        foreach ($fields as $k => $v) {
            $set[] = $k . ' = ' . $this->quote($v);
        }
        $this->db->Execute("UPDATE " . $table . " SET " . implode(', ', $set) . " WHERE " . $where . " LIMIT 1");
    }

    protected function quote($v): string
    {
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
        }
        return "'" . $this->db->prepare_input((string)$v) . "'";
    }
}
