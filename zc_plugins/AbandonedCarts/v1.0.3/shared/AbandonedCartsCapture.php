<?php
/**
 * Abandoned Carts -- the storefront side of recording: who the shopper is, and
 * when to write.
 *
 * Called from two observers:
 *
 *   - the cart observer, instantiated at autoloader breakpoint 139 by the
 *     plugin's auto_loader, because core runs cart actions (add, update,
 *     remove) at breakpoint 140 and an auto.* observer only exists from 175;
 *   - the auto observer, for One Page Checkout's guest Save, login and the
 *     order.
 *
 * At breakpoint 139 One Page Checkout's own observer may not be listening yet,
 * so zen_in_guest_checkout() can answer false for a guest. A guest is also
 * recognized by the shared placeholder customers_id.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsTracker', false)) {
    require_once __DIR__ . '/AbandonedCartsTracker.php';
}

class AbandonedCartsCapture
{
    /** The session entry this plugin owns. */
    public const SESSION = 'abandoned_carts';

    /** The plugin's session state, created on first use: key, email, firstname, newsletter, opted_in, ordering. */
    public static function &state(): array
    {
        if (!isset($_SESSION[self::SESSION]) || !is_array($_SESSION[self::SESSION]) || !isset($_SESSION[self::SESSION]['key'])) {
            $_SESSION[self::SESSION] = ['key' => bin2hex(random_bytes(16)), 'email' => '', 'firstname' => '', 'customers_id' => 0, 'ordering' => false];
        }
        // Sessions begun under 1.0.1 lack the 1.0.2 entries.
        $_SESSION[self::SESSION] += ['newsletter' => null, 'opted_in' => false];
        return $_SESSION[self::SESSION];
    }

    /** One Page Checkout's shared guest record, or 0 when OPC isn't installed. */
    public static function guestPlaceholder(): int
    {
        return defined('CHECKOUT_ONE_GUEST_CUSTOMER_ID') ? (int)CHECKOUT_ONE_GUEST_CUSTOMER_ID : 0;
    }

    public static function isGuest(): bool
    {
        $id = (int)($_SESSION['customer_id'] ?? 0);
        if ($id > 0 && $id === self::guestPlaceholder()) {
            return true;
        }
        return function_exists('zen_in_guest_checkout') && zen_in_guest_checkout();
    }

    /** The logged-in customer's id, or 0 for an anonymous shopper or a guest. */
    public static function customerId(): int
    {
        $id = (int)($_SESSION['customer_id'] ?? 0);
        return ($id > 0 && !self::isGuest()) ? $id : 0;
    }

    /**
     * The shopper as the tracker wants them. A customer's email and first name
     * come from their account (looked up once per session); a guest's from
     * what they saved at checkout.
     */
    public static function shopper($db): array
    {
        $state = &self::state();
        $customerId = self::customerId();
        if ($customerId > 0 && (int)$state['customers_id'] !== $customerId) {
            $r = AbandonedCartsCore::fresh(
                $db,
                "SELECT customers_email_address, customers_firstname, customers_newsletter FROM " . TABLE_CUSTOMERS . " WHERE customers_id = " . $customerId . " LIMIT 1"
            );
            $state['customers_id'] = $customerId;
            $state['email'] = $r->EOF ? '' : trim((string)$r->fields['customers_email_address']);
            $state['firstname'] = $r->EOF ? '' : trim((string)$r->fields['customers_firstname']);
            $state['newsletter'] = !$r->EOF && (string)$r->fields['customers_newsletter'] === '1';
        }
        return [
            'key' => (string)$state['key'],
            'session_id' => function_exists('zen_session_id') ? (string)zen_session_id() : '',
            'customers_id' => $customerId,
            'is_guest' => $customerId === 0,
            'opted_in' => $customerId === 0 && !empty($state['opted_in']),
            'newsletter' => $customerId > 0 ? $state['newsletter'] === true : null,
            'email' => $customerId > 0 || self::isGuest() ? (string)$state['email'] : '',
            'firstname' => (string)$state['firstname'],
            'languages_id' => (int)($_SESSION['languages_id'] ?? 1),
            'language' => (string)($_SESSION['language'] ?? 'english'),
            'currency' => (string)($_SESSION['currency'] ?? (defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : '')),
        ];
    }

    /**
     * The guest saved their contact details at checkout. $optedIn is their
     * "Email me a reminder" box when the form carried it (null: not asked on
     * this save, keep what they said before).
     */
    public static function guestSaved(string $email, string $firstname = '', ?bool $optedIn = null): void
    {
        $email = trim(html_entity_decode($email, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8'));
        if ($email === '' || !self::validEmail($email)) {
            return;
        }
        $state = &self::state();
        $state['email'] = $email;
        $state['customers_id'] = 0;
        if ($optedIn !== null) {
            $state['opted_in'] = $optedIn;
        }
        if (trim($firstname) !== '') {
            $state['firstname'] = trim(html_entity_decode($firstname, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8'));
        }
    }

    /** The cart changed (or the shopper just became emailable): record it. */
    public static function cartChanged($db): int
    {
        $cart = $_SESSION['cart'] ?? null;
        if (!is_object($cart)) {
            return 0;
        }
        $shopper = self::shopper($db);
        if ($shopper['email'] === '') {
            // Anonymous: nothing is stored until we could email them.
            return 0;
        }
        $tracker = new AbandonedCartsTracker($db);
        if (AbandonedCartsCore::consentProblem($shopper['is_guest'], $shopper['opted_in'], $shopper['newsletter']) !== '') {
            // Not to be emailed: keep nothing, and drop what was kept before.
            $tracker->forget($shopper);
            return 0;
        }
        $products = method_exists($cart, 'get_products') ? (array)$cart->get_products() : [];
        $snapshot = $tracker->snapshot((array)$cart->contents, $products, $shopper['languages_id'], [self::class, 'lineTotal']);
        return $tracker->record($shopper, $snapshot);
    }

    /** The cart was emptied on purpose (reset with the database). */
    public static function cartEmptied($db): void
    {
        $state = &self::state();
        if (!empty($state['ordering'])) {
            return;
        }
        $shopper = self::shopper($db);
        if ($shopper['email'] !== '') {
            $tracker = new AbandonedCartsTracker($db);
            $tracker->emptied($shopper);
        }
    }

    /** The order went through: close the shopper's open carts. */
    public static function orderPlaced($db, int $ordersId): void
    {
        $state = &self::state();
        $state['ordering'] = true;
        $email = '';
        $total = 0.0;
        if (defined('TABLE_ORDERS') && $ordersId > 0) {
            $r = AbandonedCartsCore::fresh($db, "SELECT customers_email_address, order_total FROM " . TABLE_ORDERS . " WHERE orders_id = " . $ordersId . " LIMIT 1");
            if (!$r->EOF) {
                $email = (string)$r->fields['customers_email_address'];
                $total = (float)$r->fields['order_total'];
            }
        }
        $tracker = new AbandonedCartsTracker($db);
        $tracker->ordered(self::shopper($db), $ordersId, $email, $total);
    }

    /** A cart line's price as the shopper saw it, in the default currency. */
    public static function lineTotal(array $p): float
    {
        $qty = (float)($p['quantity'] ?? 0);
        $price = (float)($p['final_price'] ?? 0);
        $onetime = (float)($p['onetime_charges'] ?? 0);
        $rate = function_exists('zen_get_tax_rate') ? (float)zen_get_tax_rate((int)($p['tax_class_id'] ?? 0)) : 0.0;
        if (function_exists('zen_add_tax')) {
            return (float)zen_add_tax($price, $rate) * $qty + (float)zen_add_tax($onetime, $rate);
        }
        return $price * $qty + $onetime;
    }

    protected static function validEmail(string $email): bool
    {
        if (function_exists('zen_validate_email')) {
            return (bool)zen_validate_email($email);
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
