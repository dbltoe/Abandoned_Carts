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
 *   NOTIFY_FOOTER_END                    on One Page Checkout, for a guest, with
 *                                        Ask Guests Before Reminding? on: the
 *                                        "Email me a reminder" checkbox, put
 *                                        into OPC's contact block by script
 *                                        (OPC's template has no notifier there).
 *                                        OPC's Save posts every input in that
 *                                        block, so the box arrives with the
 *                                        guest's email; no box posted (no
 *                                        script) means no reminder.
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
    /** The checkbox's field name in OPC's guest Save. */
    public const OPT_IN_FIELD = 'ac_remind';

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
            'NOTIFY_FOOTER_END',
        ]);
    }

    public function update(&$class, $eventID, $p1 = null, &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null, &$p6 = null, &$p7 = null)
    {
        try {
            switch ($eventID) {
                case 'NOTIFY_OPC_VALIDATE_SAVE_GUEST_INFO':
                    // p1 holds core's validation messages, p2 other plugins'.
                    if (empty($p1) && empty($p2) && is_object($class) && method_exists($class, 'isGuestCheckout') && $class->isGuestCheckout()) {
                        // The "Email me a reminder" box (field OPT_IN_FIELD): ticked or not.
                        // Not posted means not ticked; null when the store doesn't ask.
                        $optedIn = AbandonedCartsCore::settingOn('ABANDONED_CARTS_GUEST_OPT_IN', false) ? ($_POST['ac_remind'] ?? '') === '1' : null;
                        AbandonedCartsCapture::guestSaved((string)($_POST['email_address'] ?? ''), '', $optedIn);
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

                case 'NOTIFY_FOOTER_END':
                    echo $this->optInScript();
                    break;
            }
        } catch (\Throwable $e) {
            error_log('Abandoned Carts (' . $eventID . '): ' . $e->getMessage());
        }
    }

    /**
     * The "Email me a reminder" checkbox for a One Page Checkout guest, or ''.
     * It goes just above OPC's Save/Cancel buttons in the contact block, and
     * back again whenever OPC redraws that block (Cancel reloads it by AJAX).
     * Ticking or unticking it opens the block for editing so the guest can
     * Save. Never ticked for them.
     */
    public function optInScript(): string
    {
        if ((string)($GLOBALS['current_page_base'] ?? '') !== 'checkout_one'
            || !AbandonedCartsCore::settingOn('ABANDONED_CARTS_GUEST_OPT_IN', false)
            || !AbandonedCartsCore::settingOn('ABANDONED_CARTS_EMAIL_GUESTS', true)
            || !AbandonedCartsCapture::isGuest()) {
            return '';
        }
        $state = &AbandonedCartsCapture::state();
        $label = defined('ABANDONED_CARTS_OPT_IN_LABEL') ? (string)ABANDONED_CARTS_OPT_IN_LABEL : 'Email me a reminder if I leave items in my cart.';
        return self::optInMarkup($label, !empty($state['opted_in']));
    }

    /**
     * The script itself. The label goes in as a JSON string with < > & ' "
     * hex-escaped, so a store's own wording can't end the script early, and
     * reaches the page as a text node, never as HTML.
     */
    public static function optInMarkup(string $label, bool $ticked): string
    {
        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        return '<script>(function(){'
            . 'var label=' . json_encode($label, $json) . ',name=' . json_encode(self::OPT_IN_FIELD, $json) . ',on=' . ($ticked ? 'true' : 'false') . ';'
            . 'function add(){var box=document.getElementById("checkoutOneGuestInfo");if(!box||document.getElementById("ac-remind")){return;}'
            . 'var w=document.createElement("div");w.className="ac-remind";w.style.margin="10px 0";'
            . 'var l=document.createElement("label");l.setAttribute("for","ac-remind");'
            . 'var c=document.createElement("input");c.type="checkbox";c.id="ac-remind";c.name=name;c.value="1";c.checked=on;'
            . 'l.appendChild(c);l.appendChild(document.createTextNode(" "+label));w.appendChild(l);'
            . 'c.addEventListener("change",function(){on=c.checked;if(window.jQuery){var e=jQuery("#opc-bill-edit:visible");if(e.length){e.trigger("click");}}c.blur();c.focus();});'
            . 'var b=box.querySelector(".opc-buttons");if(b){b.parentNode.insertBefore(w,b);}else{box.appendChild(w);}}'
            . 'add();if(window.jQuery){jQuery(document).ajaxComplete(add);}document.addEventListener("DOMContentLoaded",add);'
            . '})();</script>';
    }
}
