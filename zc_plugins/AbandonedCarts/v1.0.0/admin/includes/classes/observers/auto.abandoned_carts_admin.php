<?php
/**
 * Abandoned Carts -- admin observer.
 *
 * Zen Cart finds this file itself (init_observers.php scans every installed
 * plugin's classes/observers/ for auto.*.php). It must NOT also be listed in
 * an auto_loader; a second include fatals with "Cannot redeclare class".
 *
 * Only the email hooks live here, for a reminder sent from the admin side
 * (Preview Email's Send Test): HTML for an address that isn't a customer, and
 * the one-click unsubscribe headers.
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
    require_once dirname(__DIR__, 4) . '/shared/AbandonedCartsCore.php';
}

class zcObserverAbandonedCartsAdmin extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT',
            'NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS',
        ]);
    }

    public function update(&$class, $eventID, $p1 = null, &$p2 = null, &$p3 = null)
    {
        if ($eventID === 'NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT') {
            AbandonedCartsCore::mailFormat($p2, $p3);
        } elseif ($eventID === 'NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS') {
            AbandonedCartsCore::mailHeaders($p1, $p2);
        }
    }
}
