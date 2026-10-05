<?php
/**
 * Abandoned Carts -- storefront observer.
 *
 *   NOTIFY_OPC_VALIDATE_SAVE_GUEST_INFO
 *   NOTIFY_OPC_ADDRESS_VALIDATION        a One Page Checkout guest saved their
 *                                        contact details (the only point a
 *                                        guest's email reaches the server)
 *   NOTIFY_LOGIN_SUCCESS(_VIA_CREATE_ACCOUNT)
 *                                        the cart now belongs to a customer;
 *                                        fires even for an OPC guest who logs
 *                                        in mid-checkout, when core skips its
 *                                        cart merge
 *   NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET
 *                                        the order went through
 *   NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT
 *   NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS
 *                                        HTML for guests and one-click
 *                                        unsubscribe headers, our mail only
 *
 * The cart's own changes are handled by class.abandoned_carts_cart.php, which
 * has to be listening earlier than an auto.* observer can be.
 *
 * Only OPC's getGuestEmailAddress() is called while a guest checkout is in
 * progress: OPC 2.7.0 logs a warning when it's called at any other time.
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

class zcObserverAbandonedCarts extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_OPC_VALIDATE_SAVE_GUEST_INFO',
            'NOTIFY_OPC_ADDRESS_VALIDATION',
            'NOTIFY_LOGIN_SUCCESS',
            'NOTIFY_LOGIN_SUCCESS_VIA_CREATE_ACCOUNT',
            'NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET',
            'NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT',
            'NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS',
        ]);
    }

    public function update(&$class, $eventID, $p1 = null, &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null, &$p6 = null, &$p7 = null)
    {
        try {
            switch ($eventID) {
                case 'NOTIFY_OPC_VALIDATE_SAVE_GUEST_INFO':
                    // p1 holds core's validation messages, p2 other plugins'.
                    if (empty($p1) && empty($p2) && is_object($class) && method_exists($class, 'isGuestCheckout') && $class->isGuestCheckout()) {
                        AbandonedCartsCapture::guestSaved((string)($_POST['email_address'] ?? ''));
                        AbandonedCartsCapture::cartChanged($GLOBALS['db']);
                    }
                    break;

                case 'NOTIFY_OPC_ADDRESS_VALIDATION':
                    $opc = $_SESSION['opc'] ?? null;
                    if (is_array($p1) && ($p1['which'] ?? '') === 'bill'
                        && is_object($opc) && method_exists($opc, 'isGuestCheckout') && $opc->isGuestCheckout()
                        && method_exists($opc, 'getGuestEmailAddress')) {
                        $first = (string)($p1['address_values']['firstname'] ?? '');
                        AbandonedCartsCapture::guestSaved((string)$opc->getGuestEmailAddress(), $first);
                        AbandonedCartsCapture::cartChanged($GLOBALS['db']);
                    }
                    break;

                case 'NOTIFY_LOGIN_SUCCESS':
                case 'NOTIFY_LOGIN_SUCCESS_VIA_CREATE_ACCOUNT':
                    AbandonedCartsCapture::cartChanged($GLOBALS['db']);
                    break;

                case 'NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET':
                    AbandonedCartsCapture::orderPlaced($GLOBALS['db'], (int)$p1);
                    break;

                case 'NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT':
                    AbandonedCartsCore::mailFormat($p2, $p3);
                    break;

                case 'NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS':
                    AbandonedCartsCore::mailHeaders($p1, $p2);
                    break;
            }
        } catch (\Throwable $e) {
            error_log('Abandoned Carts (' . $eventID . '): ' . $e->getMessage());
        }
    }
}
