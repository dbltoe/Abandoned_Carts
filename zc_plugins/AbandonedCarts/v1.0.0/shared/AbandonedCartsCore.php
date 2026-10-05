<?php
/**
 * Abandoned Carts -- the parts every side of the plugin shares.
 *
 * Pure functions only: table names, settings, link tokens, the email hash used
 * by the unsubscribe list, and composing the reminder email. Nothing in here
 * touches the database, so the harnesses can exercise all of it without a
 * store.
 *
 * Loaded with require_once via __DIR__ from every entry point. No Zen Cart
 * loader is relied on: the catalog side never auto-loads a plugin's
 * extra_functions.
 *
 * Source must parse on PHP 7.4 and stay deprecation-clean on 8.5.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class AbandonedCartsCore
{
    public const VERSION = 'v1.0.0';

    /** The zen_mail() module name: our own, so the email observers touch only our mail. */
    public const MAIL_MODULE = 'abandoned_carts';

    /** A cart the scheduler may still email. Every other status is final. */
    public const OPEN = 'open';

    /** Every status a cart record can have. */
    public const STATUSES = ['open', 'recovered', 'ordered', 'emptied', 'merged', 'excluded', 'expired', 'unsubscribed'];

    /** Reminder emails the free plugin sends per cart. Abandoned Carts Pro raises it. */
    public const FREE_STEPS = 1;

    /** A send that never reached the end of its run is left alone for this long, then counted as sent. */
    public const STUCK_MINUTES = 30;

    /** "Never" in the datetime columns (MySQL strict mode refuses 0000-00-00). */
    public const NEVER = '0001-01-01 00:00:00';

    /** Define the plugin's table constants. Guarded: extra_datafiles load on both sides. */
    public static function defineTables(): void
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $tables = [
            'TABLE_ABANDONED_CARTS' => 'abandoned_carts',
            'TABLE_ABANDONED_CARTS_EVENTS' => 'abandoned_carts_events',
            'TABLE_ABANDONED_CARTS_UNSUBSCRIBES' => 'abandoned_carts_unsubscribes',
        ];
        foreach ($tables as $name => $table) {
            if (!defined($name)) {
                define($name, $prefix . $table);
            }
        }
    }

    /**
     * Settings that win over the store's configuration. Empty on a store; the
     * harnesses use it to try each setting both ways in one run (a constant,
     * once defined, can't change).
     *
     * @var array<string,string>
     */
    public static $overrides = [];

    /**
     * Run a SELECT past core's query cache. Zen Cart memoizes every SELECT for
     * the length of a request (QueryCache, every release 1.5.8 -> 3.0.0) and a
     * write doesn't clear it, so the same lookup after an INSERT would get the
     * stale answer: a login that records the cart twice in one request made two
     * records on _test223 (2026-10-05). Every SELECT this plugin runs on data
     * that can change goes through here.
     */
    public static function fresh($db, string $sql)
    {
        return $db->Execute($sql, null, false, 0, true);
    }

    /** A setting's value, or the default when it isn't defined (not installed, or a key Pro adds). */
    public static function setting(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$overrides)) {
            return (string)self::$overrides[$key];
        }
        return defined($key) ? (string)constant($key) : $default;
    }

    public static function settingOn(string $key, bool $default = false): bool
    {
        return self::setting($key, $default ? 'true' : 'false') === 'true';
    }

    /** A whole-number setting held between $min and $max. */
    public static function settingInt(string $key, int $default, int $min, int $max): int
    {
        $raw = trim(self::setting($key, (string)$default));
        $n = preg_match('/^-?\d+$/', $raw) ? (int)$raw : $default;
        return max($min, min($max, $n));
    }

    /**
     * How many reminders a cart gets. The free plugin sends one. Abandoned
     * Carts Pro raises it to its Reminders per Cart (up to 3), but only while
     * Pro is loaded: ABANDONED_CARTS_PRO_LOADED comes from Pro's extra_datafiles,
     * which Zen Cart loads only for an enabled plugin, whereas its settings stay
     * defined while it's disabled.
     */
    public static function maxSteps(): int
    {
        $pro = self::setting('ABANDONED_CARTS_PRO_STEPS', '');
        if ($pro === '' || self::setting('ABANDONED_CARTS_PRO_LOADED', '') === '') {
            return self::FREE_STEPS;
        }
        return max(1, min(3, (int)$pro));
    }

    /** Hours after the last cart activity that reminder $step (1-based) goes out. */
    public static function stepDelayHours(int $step): int
    {
        if ($step <= 1) {
            return self::settingInt('ABANDONED_CARTS_DELAY_HOURS', 1, 1, 168);
        }
        $defaults = [2 => 24, 3 => 72];
        return self::settingInt('ABANDONED_CARTS_PRO_STEP' . $step . '_HOURS', $defaults[$step] ?? 72, 1, 720);
    }

    /**
     * The link token for one cart: an HMAC of the cart id under the store's
     * secret, so nothing has to be stored and a guessed id gets nowhere.
     * $purpose keeps the cart link and the unsubscribe link apart.
     */
    public static function token(int $cartId, string $secret, string $purpose = 'cart'): string
    {
        return substr(hash_hmac('sha256', $purpose . '|' . $cartId, $secret), 0, 40);
    }

    public static function tokenMatches(int $cartId, string $secret, string $given, string $purpose = 'cart'): bool
    {
        if ($cartId <= 0 || $secret === '' || !preg_match('/^[0-9a-f]{40}$/', $given)) {
            return false;
        }
        return hash_equals(self::token($cartId, $secret, $purpose), $given);
    }

    /** The unsubscribe list keeps a hash, never the address. */
    public static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** A cart line's label, "Size: Medium". */
    public static function optionLabel(string $option, string $value): string
    {
        return trim($option) === '' ? trim($value) : trim($option) . ': ' . trim($value);
    }

    /**
     * Split a cart attribute key into option id and value id. Core stores a
     * checkbox choice as "13_chk36"; every other key is the option id.
     *
     * @return array{0:int, 1:int|null} option id, and the value id for a checkbox (else null)
     */
    public static function splitAttributeKey($key): array
    {
        $key = (string)$key;
        if (preg_match('/^(\d+)_chk(\d+)$/', $key, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        return [(int)$key, null];
    }

    /**
     * Compose the reminder email.
     *
     * Reminders after the first can have their own subject and intro
     * (ABANDONED_CARTS_EMAIL_SUBJECT_2, _INTRO_3...: Abandoned Carts Pro's
     * language file defines them); without one, the first reminder's wording
     * is used. A coupon, when $cart['coupon'] carries one (code, offer,
     * expires), goes in a box above the button.
     *
     * @param array    $cart   firstname, email, lines (name, quantity, line_total, options[]), cart_total, currency, coupon (optional)
     * @param callable $money  (float amount, string currency): string
     * @return array{subject:string, text:string, html:string}
     */
    public static function composeReminder(array $cart, int $step, string $cartUrl, string $unsubscribeUrl, callable $money): array
    {
        $store = self::text('STORE_NAME', 'our store');
        $name = trim((string)($cart['firstname'] ?? ''));
        $currency = (string)($cart['currency'] ?? '');

        $subject = sprintf(self::stepText('ABANDONED_CARTS_EMAIL_SUBJECT', $step, 'You left something in your cart at %s'), $store);
        $intro = sprintf(self::stepText('ABANDONED_CARTS_EMAIL_INTRO', $step, 'You left these in your cart at %s. They\'re saved for you:'), $store);
        $coupon = is_array($cart['coupon'] ?? null) && (string)($cart['coupon']['code'] ?? '') !== '' ? $cart['coupon'] : null;
        $couponLine = $coupon === null ? '' : sprintf(
            self::text('ABANDONED_CARTS_EMAIL_COUPON', 'Here\'s %1$s your order: enter code %2$s at checkout. It\'s good through %3$s.'),
            (string)$coupon['offer'],
            (string)$coupon['code'],
            (string)$coupon['expires']
        );

        $text = ($name !== '' ? sprintf(self::text('ABANDONED_CARTS_EMAIL_GREETING', 'Hi %s,'), $name) : self::text('ABANDONED_CARTS_EMAIL_GREETING_NONAME', 'Hello,')) . "\n\n"
            . $intro . "\n\n";
        $rows = '';
        foreach ((array)($cart['lines'] ?? []) as $line) {
            $qty = self::quantity($line['quantity'] ?? 1);
            $amount = $money((float)($line['line_total'] ?? 0), $currency);
            $text .= $qty . ' x ' . $line['name'] . '  ' . $amount . "\n";
            $opts = '';
            foreach ((array)($line['options'] ?? []) as $label) {
                $text .= '    ' . $label . "\n";
                $opts .= '<br><span style="color:#555;font-size:90%">' . self::esc($label) . '</span>';
            }
            $rows .= '<tr><td style="padding:6px 12px 6px 0;vertical-align:top">' . self::esc($qty) . ' &times;</td>'
                . '<td style="padding:6px 12px 6px 0;vertical-align:top">' . self::esc((string)$line['name']) . $opts . '</td>'
                . '<td style="padding:6px 0;vertical-align:top;text-align:right;white-space:nowrap">' . self::esc($amount) . '</td></tr>';
        }
        $total = $money((float)($cart['cart_total'] ?? 0), $currency);
        $text .= "\n" . sprintf(self::text('ABANDONED_CARTS_EMAIL_TOTAL', 'Cart total: %s'), $total) . "\n\n"
            . ($couponLine === '' ? '' : $couponLine . "\n\n")
            . self::text('ABANDONED_CARTS_EMAIL_CTA_TEXT', 'Pick up where you left off:') . "\n" . $cartUrl . "\n\n"
            . self::text('ABANDONED_CARTS_EMAIL_CLOSING', 'Prices and availability are confirmed at checkout.') . "\n\n"
            . self::footerText($unsubscribeUrl);

        $button = self::text('ABANDONED_CARTS_EMAIL_BUTTON', 'Return to Your Cart');
        $html = '<p>' . self::esc($name !== '' ? sprintf(self::text('ABANDONED_CARTS_EMAIL_GREETING', 'Hi %s,'), $name) : self::text('ABANDONED_CARTS_EMAIL_GREETING_NONAME', 'Hello,')) . '</p>'
            . '<p>' . self::esc($intro) . '</p>'
            . '<table role="presentation" style="border-collapse:collapse;margin:0 0 12px">' . $rows
            . '<tr><td colspan="2" style="padding:8px 12px 0 0;border-top:1px solid #ccc;text-align:right"><strong>' . self::esc(self::text('ABANDONED_CARTS_EMAIL_TOTAL_LABEL', 'Cart total')) . '</strong></td>'
            . '<td style="padding:8px 0 0;border-top:1px solid #ccc;text-align:right;white-space:nowrap"><strong>' . self::esc($total) . '</strong></td></tr></table>'
            . ($coupon === null ? '' : '<p style="padding:10px 14px;border:2px dashed #1d4f91;border-radius:4px">' . self::esc($couponLine)
                . '<br><strong style="font-size:120%;letter-spacing:1px">' . self::esc((string)$coupon['code']) . '</strong></p>')
            . '<p><a href="' . self::esc($cartUrl) . '" style="display:inline-block;padding:10px 18px;background:#1d4f91;color:#ffffff;text-decoration:none;border-radius:4px">' . self::esc($button) . '</a></p>'
            . '<p style="color:#555;font-size:90%">' . self::esc(self::text('ABANDONED_CARTS_EMAIL_CLOSING', 'Prices and availability are confirmed at checkout.')) . '</p>'
            . self::footerHtml($unsubscribeUrl);

        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    protected static function footerText(string $unsubscribeUrl): string
    {
        $out = sprintf(self::text('ABANDONED_CARTS_EMAIL_UNSUBSCRIBE_TEXT', 'Don\'t want cart reminders? Unsubscribe: %s'), $unsubscribeUrl);
        $address = trim(self::text('STORE_NAME_ADDRESS', ''));
        return $address === '' ? $out : $out . "\n\n" . $address;
    }

    protected static function footerHtml(string $unsubscribeUrl): string
    {
        $link = '<a href="' . self::esc($unsubscribeUrl) . '">' . self::esc(self::text('ABANDONED_CARTS_EMAIL_UNSUBSCRIBE_LINK', 'Unsubscribe from cart reminders')) . '</a>';
        $address = trim(self::text('STORE_NAME_ADDRESS', ''));
        return '<p style="color:#777;font-size:85%">' . $link . '</p>'
            . ($address === '' ? '' : '<p style="color:#777;font-size:85%">' . nl2br(self::esc($address)) . '</p>');
    }

    /**
     * The unsubscribe address of the email being sent right now, set by the
     * sender just before zen_mail() and read by mailHeaders().
     *
     * @var string
     */
    public static $sendingUnsubscribeUrl = '';

    /**
     * NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT: an address that isn't a customer
     * (a guest) has no format, and core sends those text-only. Our reminder is
     * sent as HTML to them; a customer's own TEXT choice is kept.
     */
    public static function mailFormat(&$format, $module): void
    {
        if ($module === self::MAIL_MODULE && (string)$format === '') {
            $format = 'HTML';
        }
    }

    /**
     * NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS: one-click unsubscribe headers
     * (RFC 8058) on our reminders. Mail providers show their own Unsubscribe
     * button for these, which beats a spam complaint.
     */
    public static function mailHeaders($params, $mail): void
    {
        $module = is_array($params) ? (string)($params['module'] ?? '') : '';
        if ($module !== self::MAIL_MODULE || self::$sendingUnsubscribeUrl === '' || !is_object($mail) || !method_exists($mail, 'addCustomHeader')) {
            return;
        }
        $mail->addCustomHeader('List-Unsubscribe', '<' . self::$sendingUnsubscribeUrl . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    /** "2", or "1.5" for a fractional quantity, never "2.0". */
    public static function quantity($qty): string
    {
        $q = (float)$qty;
        return $q == floor($q) ? (string)(int)$q : rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'UTF-8');
    }

    /**
     * Define the storefront text on the admin side (where Zen Cart loads only
     * the admin language files). Constants already defined win; English for a
     * language the plugin doesn't ship.
     */
    public static function loadStorefrontText(string $language): void
    {
        $base = dirname(__DIR__) . '/catalog/includes/languages/';
        $file = $base . basename($language) . '/extra_definitions/lang.abandoned_carts.php';
        if (!is_file($file)) {
            $file = $base . 'english/extra_definitions/lang.abandoned_carts.php';
        }
        $define = require $file;
        foreach ((array)$define as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * The scheduler's storefront address, built in the admin from its
     * configure.php for the installer's setting description.
     */
    public static function schedulerUrl(string $cronKey): string
    {
        $ssl = defined('ENABLE_SSL_CATALOG') && ENABLE_SSL_CATALOG === 'true' && defined('HTTPS_CATALOG_SERVER');
        $server = $ssl ? HTTPS_CATALOG_SERVER : (defined('HTTP_CATALOG_SERVER') ? HTTP_CATALOG_SERVER : '');
        $dir = ($ssl && defined('DIR_WS_HTTPS_CATALOG')) ? DIR_WS_HTTPS_CATALOG : (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
        return $server . $dir . 'index.php?main_page=abandoned_carts_cron&key=' . $cronKey;
    }

    /** The step's own wording (CONSTANT_2, CONSTANT_3) when defined, else the first reminder's. */
    private static function stepText(string $constant, int $step, string $default): string
    {
        return $step > 1 && defined($constant . '_' . $step) ? (string)constant($constant . '_' . $step) : self::text($constant, $default);
    }

    private static function text(string $constant, string $default): string
    {
        return defined($constant) ? (string)constant($constant) : $default;
    }
}
