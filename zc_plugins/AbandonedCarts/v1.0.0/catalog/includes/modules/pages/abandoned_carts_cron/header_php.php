<?php
/**
 * Abandoned Carts -- the scheduler.
 *
 *   index.php?main_page=abandoned_carts_cron&key=<Scheduler Key>
 *
 * Opened every 15 minutes by a cron job (curl) or an outside cron service. It
 * runs inside a normal storefront request, so the emails get the store's
 * templates, currencies and links exactly as a customer's page would. Answers
 * in plain text and never renders a template; a wrong or missing key gets a
 * bare 403.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsMailer', false)) {
    require_once dirname(__DIR__, 5) . '/shared/AbandonedCartsMailer.php';
}

header('Content-Type: text/plain; charset=' . CHARSET);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$acExpected = AbandonedCartsCore::setting('ABANDONED_CARTS_CRON_KEY');
if ($acExpected === '' || !hash_equals($acExpected, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    zen_exit();
}

$acSecret = AbandonedCartsCore::setting('ABANDONED_CARTS_SECRET');
$acCurrencies = isset($currencies) && is_object($currencies) ? $currencies : null;
$acMailer = new AbandonedCartsMailer($db);
// Abandoned Carts Pro (or any plugin) can change a reminder before it's
// composed -- Pro adds its coupon here -- and add lines to the report.
if (isset($zco_notifier) && is_object($zco_notifier)) {
    $acNotifier = $zco_notifier;
    $acMailer->onBeforeCompose(static function (int $cartId, int $step, array $cart, array $row) use ($acNotifier): array {
        $acNotifier->notify('NOTIFY_ABANDONED_CARTS_BEFORE_COMPOSE', ['cart_id' => $cartId, 'step' => $step, 'row' => $row], $cart);
        return $cart;
    });
}
$acReport = $acMailer->run(
    static function (int $cartId, string $purpose) use ($acSecret): string {
        $param = $purpose === 'unsubscribe' ? 'u' : 't';
        return str_replace('&amp;', '&', zen_href_link(FILENAME_ABANDONED_CART, 'cart=' . $cartId . '&' . $param . '=' . AbandonedCartsCore::token($cartId, $acSecret, $purpose), 'SSL', false));
    },
    static function (float $amount, string $currency) use ($acCurrencies): string {
        return $acCurrencies !== null ? $acCurrencies->format($amount, true, $currency !== '' ? $currency : DEFAULT_CURRENCY) : number_format($amount, 2);
    },
    static function (array $mail): bool {
        AbandonedCartsCore::$sendingUnsubscribeUrl = (string)($mail['unsubscribe_url'] ?? '');
        try {
            // Preview Email: abandoned_carts_reminder
            $result = zen_mail($mail['name'], $mail['email'], $mail['subject'], $mail['text'], STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => $mail['html']], AbandonedCartsCore::MAIL_MODULE);
        } finally {
            AbandonedCartsCore::$sendingUnsubscribeUrl = '';
        }
        // zen_mail() answers '' when sent, PHPMailer's error text when not, and
        // false when sending is off (SEND_EMAILS) or the address is refused.
        return $result === '';
    }
);

echo 'Abandoned Carts scheduler, ' . date('Y-m-d H:i:s') . "\n";
if ($acReport['locked']) {
    echo "Another run is still in progress, so this one did nothing.\n";
} else {
    echo (AbandonedCartsCore::settingOn('ABANDONED_CARTS_STATUS', true) ? '' : "Reminder emails are turned off (Send Reminder E-Mails?).\n")
        . 'Reminders sent: ' . $acReport['sent'] . "\n"
        . 'Ordered before a reminder went out: ' . $acReport['ordered'] . "\n"
        . 'Waiting (shopper still browsing): ' . $acReport['waiting'] . "\n"
        . 'Not emailed by your settings: ' . $acReport['excluded'] . "\n"
        . 'Unsubscribed: ' . $acReport['unsubscribed'] . "\n"
        . 'Too old to remind: ' . $acReport['expired'] . "\n"
        . 'Old records deleted: ' . $acReport['purged'] . "\n"
        . 'Errors: ' . ($acReport['errors'] === [] ? 'none' : "\n  " . implode("\n  ", $acReport['errors'])) . "\n";
    if (isset($zco_notifier) && is_object($zco_notifier)) {
        $zco_notifier->notify('NOTIFY_ABANDONED_CARTS_SCHEDULER_END', [], $acReport);
    }
    foreach ((array)$acReport['extra'] as $acLine) {
        echo (string)$acLine . "\n";
    }
}
zen_exit();
