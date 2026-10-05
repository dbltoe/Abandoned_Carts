<?php
/**
 * Abandoned Carts -- what Customers > Abandoned Carts reads and does.
 *
 * Kept out of the admin page so the harnesses can run it against FakeDb.
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

class AbandonedCartsAdmin
{
    public const PER_PAGE = 50;

    /** The scheduler should run every 15 minutes; an hour without a run gets a warning. */
    public const STALE_MINUTES = 60;

    protected $db;

    public function __construct($db)
    {
        AbandonedCartsCore::defineTables();
        $this->db = $db;
    }

    /** @return array<string,int> every status, zero when none */
    public function counts(): array
    {
        $out = array_fill_keys(AbandonedCartsCore::STATUSES, 0);
        $r = AbandonedCartsCore::fresh($this->db, "SELECT status, COUNT(*) AS n FROM " . TABLE_ABANDONED_CARTS . " GROUP BY status");
        while (!$r->EOF) {
            if (isset($out[$r->fields['status']])) {
                $out[$r->fields['status']] = (int)$r->fields['n'];
            }
            $r->MoveNext();
        }
        return $out;
    }

    /**
     * @return array{rows:array, total:int, page:int, pages:int, status:string, query:string}
     */
    public function search(string $status, string $query, int $page): array
    {
        $status = in_array($status, AbandonedCartsCore::STATUSES, true) ? $status : '';
        $query = trim($query);
        $where = [];
        if ($status !== '') {
            $where[] = "status = '" . $status . "'";
        }
        if ($query !== '') {
            $like = $this->db->prepare_input(addcslashes($query, '%_\\'));
            $where[] = "email LIKE '%" . $like . "%'";
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $c = AbandonedCartsCore::fresh($this->db, "SELECT COUNT(*) AS n FROM " . TABLE_ABANDONED_CARTS . $whereSql);
        $total = (int)$c->fields['n'];
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT * FROM " . TABLE_ABANDONED_CARTS . $whereSql
            . " ORDER BY last_activity DESC LIMIT " . (($page - 1) * self::PER_PAGE) . ", " . self::PER_PAGE
        );
        $rows = [];
        while (!$r->EOF) {
            $row = $r->fields;
            $row['lines'] = json_decode((string)$row['cart_lines'], true) ?: [];
            $rows[] = $row;
            $r->MoveNext();
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'status' => $status, 'query' => $query];
    }

    /** One cart with its lines and events, or null. */
    public function detail(int $id): ?array
    {
        $tracker = new AbandonedCartsTracker($this->db);
        $row = $tracker->find($id);
        if ($row === null) {
            return null;
        }
        $row['lines'] = json_decode((string)$row['cart_lines'], true) ?: [];
        $row['events'] = [];
        $r = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT * FROM " . TABLE_ABANDONED_CARTS_EVENTS . " WHERE abandoned_carts_id = " . $id . " ORDER BY abandoned_carts_events_id"
        );
        while (!$r->EOF) {
            $row['events'][] = $r->fields;
            $r->MoveNext();
        }
        return $row;
    }

    /**
     * What the reminders did over the last $days: emails sent, and carts a
     * reminder brought back as an order (with what those orders were worth,
     * in the default currency).
     *
     * @return array{sent:int, recovered:int, revenue:float}
     */
    public function summary(int $days, string $now): array
    {
        $since = date('Y-m-d H:i:s', strtotime($now) - $days * 86400);
        $sent = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT COUNT(*) AS n FROM " . TABLE_ABANDONED_CARTS_EVENTS . " WHERE event = 'emailed' AND date_added >= '" . $since . "'"
        );
        $recovered = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT COUNT(*) AS n FROM " . TABLE_ABANDONED_CARTS . " WHERE status = 'recovered' AND last_modified >= '" . $since . "'"
        );
        $revenue = AbandonedCartsCore::fresh(
            $this->db,
            "SELECT SUM(order_total) AS revenue FROM " . TABLE_ABANDONED_CARTS . " WHERE status = 'recovered' AND last_modified >= '" . $since . "'"
        );
        return [
            'sent' => (int)$sent->fields['n'],
            'recovered' => (int)$recovered->fields['n'],
            'revenue' => round((float)($revenue->fields['revenue'] ?? 0), 2),
        ];
    }

    /** The admin stops an open cart's reminders. */
    public function stop(int $id, string $adminName, string $now): bool
    {
        $tracker = new AbandonedCartsTracker($this->db, $now);
        $row = $tracker->find($id);
        if ($row === null || $row['status'] !== AbandonedCartsCore::OPEN) {
            return false;
        }
        $tracker->setStatus($id, 'excluded', (int)$row['step'], 'stopped by ' . $adminName);
        return true;
    }

    /**
     * [last run (Y-m-d H:i:s or ''), how long ago in words, healthy?]
     *
     * @return array{0:string, 1:string, 2:bool}
     */
    public static function schedulerHealth(string $lastRun, int $nowTs): array
    {
        $ts = $lastRun === '' ? false : strtotime($lastRun);
        if ($ts === false) {
            return ['', '', false];
        }
        $minutes = max(0, (int)floor(($nowTs - $ts) / 60));
        if ($minutes < 120) {
            $ago = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        } elseif ($minutes < 2880) {
            $ago = (int)floor($minutes / 60) . ' hours';
        } else {
            $ago = (int)floor($minutes / 1440) . ' days';
        }
        return [$lastRun, $ago, $minutes <= self::STALE_MINUTES];
    }
}
