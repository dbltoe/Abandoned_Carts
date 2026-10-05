<?php
/**
 * Abandoned Carts -- the emails this plugin sends, for Preview Email.
 *
 * Preview Email (Tools > Preview Email) reads every installed plugin's
 * <version>/email_preview/*.php. Each entry builds its email with the same
 * compose method the scheduler sends it with -- only the data is sample data
 * -- so what the store owner previews is the real email.
 *
 * Every zen_mail() call in this plugin is marked "Preview Email: <key>" and a
 * harness checks that each key is defined here.
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
    require_once dirname(__DIR__) . '/shared/AbandonedCartsCore.php';
}

if (!function_exists('abandoned_carts_preview_setup')) {
    /**
     * Load the plugin's storefront text and return sample data: two lines from
     * real active products when the store has them (else made-up ones), the
     * links as they'd appear, and a money formatter.
     */
    function abandoned_carts_preview_setup(): array
    {
        if (function_exists('preview_email_load_plugin_language')) {
            preview_email_load_plugin_language('AbandonedCarts', 'catalog/includes/languages/', 'abandoned_carts', 'extra_definitions');
        }
        $lines = [
            ['name' => 'Sample Product', 'quantity' => 2, 'line_total' => 39.90, 'options' => ['Size: Medium']],
            ['name' => 'Another Sample Product', 'quantity' => 1, 'line_total' => 12.50, 'options' => []],
        ];
        global $db;
        if (isset($db) && is_object($db) && defined('TABLE_PRODUCTS') && defined('TABLE_PRODUCTS_DESCRIPTION')) {
            $languageId = (int)($_SESSION['languages_id'] ?? 1);
            $r = $db->Execute(
                "SELECT p.products_id, p.products_price, pd.products_name FROM " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd"
                . " WHERE pd.products_id = p.products_id AND pd.language_id = " . $languageId . " AND p.products_status = 1 AND p.products_price > 0"
                . " ORDER BY p.products_id LIMIT 2"
            );
            $real = [];
            $qty = 2;
            while (!$r->EOF) {
                $real[] = ['name' => (string)$r->fields['products_name'], 'quantity' => $qty, 'line_total' => round((float)$r->fields['products_price'] * $qty, 2), 'options' => []];
                $qty = 1;
                $r->MoveNext();
            }
            if ($real !== []) {
                $lines = $real;
            }
        }
        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['line_total'];
        }
        $currencies = $GLOBALS['currencies'] ?? null;
        $money = static function ($amount, $currency = '') use ($currencies) {
            return (is_object($currencies) && method_exists($currencies, 'format')) ? $currencies->format($amount) : number_format((float)$amount, 2);
        };
        $page = defined('FILENAME_ABANDONED_CART') ? FILENAME_ABANDONED_CART : 'abandoned_cart';
        $base = function_exists('zen_catalog_href_link') ? zen_catalog_href_link($page, 'cart=123', 'SSL') : '/index.php?main_page=' . $page . '&cart=123';
        $base = str_replace('&amp;', '&', $base);
        $customer = function_exists('preview_email_sample_customer')
            ? preview_email_sample_customer()
            : ['name' => 'Sample Customer', 'email' => 'sample.customer@example.com'];
        $first = trim((string)strtok((string)$customer['name'], ' '));

        return [
            'cart' => ['firstname' => $first, 'email' => $customer['email'], 'lines' => $lines, 'cart_total' => $total, 'currency' => ''],
            'cart_url' => $base . '&t=sample',
            'unsubscribe_url' => $base . '&u=sample',
            'money' => $money,
            'customer' => $customer,
        ];
    }
}

return [
    [
        'key' => 'abandoned_carts_reminder',
        'group' => 'Abandoned Carts',
        'sort' => 10,
        'label' => 'Cart Reminder',
        'describe' => 'Sent by the scheduler to a shopper who left items in their cart (Reminder E-Mail, Hours After Last Activity): the items, the cart total, a Return to Your Cart button and an unsubscribe link.',
        'module' => AbandonedCartsCore::MAIL_MODULE,
        'page_base' => 'abandoned_carts_cron',
        'build' => static function (array $def): array {
            $x = abandoned_carts_preview_setup();
            $m = AbandonedCartsCore::composeReminder($x['cart'], 1, $x['cart_url'], $x['unsubscribe_url'], $x['money']);
            return [
                'subject' => $m['subject'],
                'text' => $m['text'],
                'block' => ['EMAIL_MESSAGE_HTML' => $m['html']],
                'to_name' => $x['cart']['firstname'],
                'to_email' => $x['customer']['email'],
                'notes' => ['The items are your first two active products (or samples), and the links are samples that open nothing.'],
            ];
        },
    ],
];
