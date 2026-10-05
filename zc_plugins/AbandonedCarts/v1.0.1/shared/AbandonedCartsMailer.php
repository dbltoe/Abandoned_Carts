<?php
/**
 * Abandoned Carts -- the scheduler pass.
 *
 * Each run takes a database lock, then works through the carts whose next
 * reminder is due. Before anything is sent a cart is checked, in this order:
 *
 *   1. too old (Don't Remind About Carts Older Than)  -> expired
 *   2. the address is on the unsubscribe list         -> unsubscribed
 *   3. an order was placed since (any payment path,
 *      PayPal IPN included)                           -> recovered / ordered
 *   4. guests off, newsletter-only, minimum value     -> excluded
 *   5. the shopper is still clicking around           -> waits
 *
 * A cart is marked "sending step n" before zen_mail() and cleared after it, so
 * a run that dies mid-send can't email the same reminder twice: a send that
 * was never cleared is counted as sent once it's STUCK_MINUTES old.
 *
 * Old records are deleted at the end of every run (Keep Cart Records).
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

class AbandonedCartsMailer
{
    /** Carts handled per run; the rest wait for the next. */
    public const BATCH = 100;

    /** Records purged per run. */
    public const PURGE_BATCH = 500;

    protected $db;

    /** @var AbandonedCartsTracker */
    protected $tracker;

    protected $now;

    /** @var callable|null (int cartId, int step, array cart, array row): array cart -- see onBeforeCompose() */
    protected $beforeCompose;

    /**
     * Let the caller change a reminder's content before it's composed:
     * Abandoned Carts Pro adds a coupon here. The scheduler page wires this to
     * NOTIFY_ABANDONED_CARTS_BEFORE_COMPOSE.
     */
    public function onBeforeCompose(callable $fn): void
    {
        $this->beforeCompose = $fn;
    }

    public function __construct($db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
        $this->tracker = new AbandonedCartsTracker($db, $this->now);
    }

    /** A per-store lock name (GET_LOCK names are server-wide). */
    public static function lockName(): string
    {
        AbandonedCartsCore::defineTables();
        return 'zc_abcarts_' . substr(sha1((defined('DB_DATABASE') ? DB_DATABASE : '') . '|' . TABLE_ABANDONED_CARTS), 0, 24);
    }

    /**
     * One scheduler pass.
     *
     * @param callable $link  (int cartId, string purpose 'cart'|'unsubscribe'): string, an absolute URL
     * @param callable $money (float amount, string currency): string, converted from the default currency
     * @param callable $send  (array mail: name, email, subject, text, html, cart_id, step): bool, false when the send failed
     * @return array{locked:bool, sent:int, ordered:int, excluded:int, expired:int, waiting:int, unsubscribed:int, purged:int, errors:string[], extra:string[]}
     */
    public function run(callable $link, callable $money, callable $send): array
    {
        $report = ['locked' => false, 'sent' => 0, 'ordered' => 0, 'excluded' => 0, 'expired' => 0, 'waiting' => 0, 'unsubscribed' => 0, 'purged' => 0, 'errors' => [], 'extra' => []];
        $lock = AbandonedCartsCore::fresh($this->db, "SELECT GET_LOCK('" . self::lockName() . "', 0) AS got");
        if ($lock->EOF || (int)$lock->fields['got'] !== 1) {
            $report['locked'] = true;
            return $report;
        }
        $this->db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . $this->now . "' WHERE configuration_key = 'ABANDONED_CARTS_LAST_RUN' LIMIT 1");
        try {
            $this->settleStuck($report);
            $r = AbandonedCartsCore::fresh(
                $this->db,
                "SELECT abandoned_carts_id FROM " . TABLE_ABANDONED_CARTS
                . " WHERE status = 'open' AND sending_step = 0 AND step < " . AbandonedCartsCore::maxSteps()
                // "Never" (0001-01-01) sorts before now: a cart that has had its last
                // reminder stays done even if Reminders per Cart is raised later.
                . " AND next_send <> '" . AbandonedCartsCore::NEVER . "' AND next_send <= '" . $this->now . "' ORDER BY next_send LIMIT " . self::BATCH
            );
            $ids = [];
            while (!$r->EOF) {
                $ids[] = (int)$r->fields['abandoned_carts_id'];
                $r->MoveNext();
            }
            foreach ($ids as $id) {
                try {
                    $this->runOne($id, $link, $money, $send, $report);
                } catch (\Throwable $e) {
                    $report['errors'][] = 'cart ' . $id . ': ' . $e->getMessage();
                }
            }
            $this->purge($report);
        } finally {
            AbandonedCartsCore::fresh($this->db, "SELECT RELEASE_LOCK('" . self::lockName() . "') AS released");
        }
        return $report;
    }

    protected function runOne(int $id, callable $link, callable $money, callable $send, array &$report): void
    {
        $row = $this->tracker->find($id);
        if ($row === null || $row['status'] !== AbandonedCartsCore::OPEN) {
            return;
        }
        $step = (int)$row['step'];
        $email = trim((string)$row['email']);

        $maxAge = AbandonedCartsCore::settingInt('ABANDONED_CARTS_MAX_AGE_DAYS', 7, 1, 60);
        if ((string)$row['last_activity'] < $this->tracker->addHours($this->now, -24 * $maxAge)) {
            $this->tracker->setStatus($id, 'expired', $step, 'older than ' . $maxAge . ' days');
            $report['expired']++;
            return;
        }
        if ($this->tracker->isUnsubscribed($email)) {
            $this->tracker->setStatus($id, 'unsubscribed', $step);
            $report['unsubscribed']++;
            return;
        }
        $order = $this->orderSince($email, (string)$row['date_added']);
        if ($order !== null) {
            $this->tracker->closeAsOrdered($id, $step, (int)$order['orders_id'], (float)$order['order_total']);
            $report['ordered']++;
            return;
        }
        $why = $this->exclusion($row);
        if ($why !== '') {
            $this->tracker->setStatus($id, 'excluded', $step, $why);
            $report['excluded']++;
            return;
        }
        if (!AbandonedCartsCore::settingOn('ABANDONED_CARTS_STATUS', true)) {
            return;
        }
        if ($this->stillBrowsing($row)) {
            $wait = max(5, AbandonedCartsCore::settingInt('ABANDONED_CARTS_ACTIVE_MINUTES', 20, 0, 240));
            $this->db->Execute(
                "UPDATE " . TABLE_ABANDONED_CARTS . " SET next_send = '" . date('Y-m-d H:i:s', strtotime($this->now) + $wait * 60) . "'"
                . " WHERE abandoned_carts_id = " . $id . " LIMIT 1"
            );
            $report['waiting']++;
            return;
        }

        $next = $step + 1;
        $cart = [
            'firstname' => (string)$row['firstname'],
            'email' => $email,
            'lines' => json_decode((string)$row['cart_lines'], true) ?: [],
            'cart_total' => (float)$row['cart_total'],
            'currency' => (string)$row['currency'],
        ];
        $unsubscribeUrl = $link($id, 'unsubscribe');
        if ($this->beforeCompose !== null) {
            $changed = ($this->beforeCompose)($id, $next, $cart, $row);
            if (is_array($changed)) {
                $cart = $changed;
            }
        }
        $mail = AbandonedCartsCore::composeReminder($cart, $next, $link($id, 'cart'), $unsubscribeUrl, $money);
        $mail += ['name' => (string)$row['firstname'], 'email' => $email, 'cart_id' => $id, 'step' => $next, 'language' => (string)$row['language'], 'unsubscribe_url' => $unsubscribeUrl];

        $this->db->Execute(
            "UPDATE " . TABLE_ABANDONED_CARTS . " SET sending_step = " . $next . ", sending_since = '" . $this->now . "'"
            . " WHERE abandoned_carts_id = " . $id . " AND sending_step = 0 LIMIT 1"
        );
        $ok = $send($mail);
        if ($ok === false) {
            $this->db->Execute(
                "UPDATE " . TABLE_ABANDONED_CARTS . " SET sending_step = 0, next_send = '" . $this->tracker->addHours($this->now, 1) . "'"
                . " WHERE abandoned_carts_id = " . $id . " LIMIT 1"
            );
            $this->tracker->event($id, 'send_failed', $next);
            $report['errors'][] = 'cart ' . $id . ': the email could not be sent; trying again in an hour';
            return;
        }
        $this->markSent($id, $next, (string)$row['last_activity']);
        $this->tracker->event($id, 'emailed', $next, $email);
        $report['sent']++;
    }

    protected function markSent(int $id, int $step, string $lastActivity): void
    {
        $nextSend = $step < AbandonedCartsCore::maxSteps()
            ? $this->tracker->addHours($lastActivity, AbandonedCartsCore::stepDelayHours($step + 1))
            : AbandonedCartsCore::NEVER;
        $this->db->Execute(
            "UPDATE " . TABLE_ABANDONED_CARTS . " SET step = " . $step . ", sending_step = 0, last_sent = '" . $this->now . "',"
            . " next_send = '" . $nextSend . "', last_modified = '" . $this->now . "' WHERE abandoned_carts_id = " . $id . " LIMIT 1"
        );
    }

    /** A run that died between "sending" and "sent": after STUCK_MINUTES the send is counted, never repeated. */
    protected function settleStuck(array &$report): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime($this->now) - AbandonedCartsCore::STUCK_MINUTES * 60);
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT abandoned_carts_id, sending_step, last_activity FROM " . TABLE_ABANDONED_CARTS
            . " WHERE sending_step > 0 AND sending_since < '" . $cutoff . "' LIMIT " . self::BATCH
        );
        while (!$r->EOF) {
            $id = (int)$r->fields['abandoned_carts_id'];
            $this->markSent($id, (int)$r->fields['sending_step'], (string)$r->fields['last_activity']);
            $this->tracker->event($id, 'send_unconfirmed', (int)$r->fields['sending_step'], 'counted as sent; the run that sent it did not finish');
            $r->MoveNext();
        }
    }

    /** The newest order from this address since the cart was first recorded. */
    protected function orderSince(string $email, string $since): ?array
    {
        if ($email === '' || !defined('TABLE_ORDERS')) {
            return null;
        }
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT orders_id, order_total FROM " . TABLE_ORDERS
            . " WHERE customers_email_address = '" . $this->db->prepare_input($email) . "' AND date_purchased >= '" . $this->db->prepare_input($since) . "'"
            . " ORDER BY orders_id DESC LIMIT 1"
        );
        return $r->EOF ? null : $r->fields;
    }

    /** Why the settings say this cart isn't emailed, or ''. */
    protected function exclusion(array $row): string
    {
        $guest = (int)$row['is_guest'] === 1 || (int)$row['customers_id'] <= 0;
        if ($guest && !AbandonedCartsCore::settingOn('ABANDONED_CARTS_EMAIL_GUESTS', true)) {
            return 'guest; E-Mail Guests is off';
        }
        if (AbandonedCartsCore::settingOn('ABANDONED_CARTS_NEWSLETTER_ONLY', false)) {
            if ($guest) {
                return 'guest; E-Mail Only Newsletter Subscribers is on';
            }
            $r = AbandonedCartsCore::fresh($this->db, "SELECT customers_newsletter FROM " . TABLE_CUSTOMERS . " WHERE customers_id = " . (int)$row['customers_id'] . " LIMIT 1");
            if ($r->EOF || (string)$r->fields['customers_newsletter'] !== '1') {
                return 'not a newsletter subscriber';
            }
        }
        $min = (float)AbandonedCartsCore::setting('ABANDONED_CARTS_MIN_VALUE', '0');
        if ($min > 0 && (float)$row['cart_total'] < $min) {
            return 'under the minimum cart value';
        }
        return '';
    }

    /** Who's Online shows this shopper clicking within the waiting window. */
    protected function stillBrowsing(array $row): bool
    {
        $minutes = AbandonedCartsCore::settingInt('ABANDONED_CARTS_ACTIVE_MINUTES', 20, 0, 240);
        if ($minutes === 0 || !defined('TABLE_WHOS_ONLINE')) {
            return false;
        }
        $since = strtotime($this->now) - $minutes * 60;
        $where = [];
        if ((string)$row['session_id'] !== '') {
            $where[] = "session_id = '" . $this->db->prepare_input((string)$row['session_id']) . "'";
        }
        if ((int)$row['customers_id'] > 0) {
            $where[] = 'customer_id = ' . (int)$row['customers_id'];
        }
        foreach ($where as $w) {
            $r = AbandonedCartsCore::fresh($this->db, "SELECT time_last_click FROM " . TABLE_WHOS_ONLINE . " WHERE " . $w . " LIMIT 1");
            if (!$r->EOF && (int)$r->fields['time_last_click'] >= $since) {
                return true;
            }
        }
        return false;
    }

    /** Delete records past Keep Cart Records, with their events. */
    protected function purge(array &$report): void
    {
        $days = AbandonedCartsCore::settingInt('ABANDONED_CARTS_RETENTION_DAYS', 30, 7, 365);
        $cutoff = $this->tracker->addHours($this->now, -24 * $days);
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT abandoned_carts_id FROM " . TABLE_ABANDONED_CARTS . " WHERE last_activity < '" . $cutoff . "' LIMIT " . self::PURGE_BATCH
        );
        $ids = [];
        while (!$r->EOF) {
            $ids[] = (int)$r->fields['abandoned_carts_id'];
            $r->MoveNext();
        }
        if ($ids === []) {
            return;
        }
        $in = implode(', ', $ids);
        $this->db->Execute("DELETE FROM " . TABLE_ABANDONED_CARTS_EVENTS . " WHERE abandoned_carts_id IN (" . $in . ")");
        $this->db->Execute("DELETE FROM " . TABLE_ABANDONED_CARTS . " WHERE abandoned_carts_id IN (" . $in . ")");
        $report['purged'] += count($ids);
    }
}
