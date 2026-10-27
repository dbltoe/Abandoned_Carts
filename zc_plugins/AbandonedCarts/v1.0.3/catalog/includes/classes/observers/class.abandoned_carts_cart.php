<?php
/**
 * Abandoned Carts -- the cart observer.
 *
 * Created at autoloader breakpoint 139 by config.abandoned_carts.php, so it is
 * listening when core runs the shopper's cart action at 140. It records the
 * cart whenever it changes, once the shopper can be emailed; an anonymous
 * shopper's cart is never written anywhere.
 *
 * Nothing in here may break the cart: every handler swallows its own errors.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsCapture', false)) {
    require_once dirname(__DIR__, 4) . '/shared/AbandonedCartsCapture.php';
}

class AbandonedCartsCartObserver extends base
{
    /** Whether the reset in progress also clears the saved (database) cart. */
    protected $resetDatabase = false;

    public function __construct()
    {
        $this->attach($this, [
            'NOTIFIER_CART_ADD_CART_END',
            'NOTIFIER_CART_UPDATE_QUANTITY_END',
            'NOTIFIER_CART_REMOVE_END',
            'NOTIFIER_CART_RESTORE_CONTENTS_END',
            'NOTIFIER_CART_RESET_START',
            'NOTIFIER_CART_RESET_END',
        ]);
    }

    public function update(&$class, $eventID, $p1 = null, &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null, &$p6 = null, &$p7 = null)
    {
        try {
            switch ($eventID) {
                case 'NOTIFIER_CART_RESET_START':
                    $this->resetDatabase = (bool)$p2;
                    break;
                case 'NOTIFIER_CART_RESET_END':
                    $state = &AbandonedCartsCapture::state();
                    if (!empty($state['ordering'])) {
                        // The order just placed already closed the cart.
                        $state['ordering'] = false;
                    } elseif ($this->resetDatabase) {
                        // Emptied on purpose. A reset without the database is a
                        // logoff clearing the session; the saved cart lives on.
                        AbandonedCartsCapture::cartEmptied($GLOBALS['db']);
                    }
                    $this->resetDatabase = false;
                    break;
                default:
                    AbandonedCartsCapture::cartChanged($GLOBALS['db']);
                    break;
            }
        } catch (\Throwable $e) {
            error_log('Abandoned Carts (' . $eventID . '): ' . $e->getMessage());
        }
    }
}
