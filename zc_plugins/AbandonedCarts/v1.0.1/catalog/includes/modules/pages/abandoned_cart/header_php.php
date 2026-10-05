<?php
/**
 * Abandoned Carts -- the links in the reminder email.
 *
 *   index.php?main_page=abandoned_cart&cart=<id>&t=<token>
 *       Return to Your Cart: puts the cart back and goes to the shopping cart.
 *
 *   index.php?main_page=abandoned_cart&cart=<id>&u=<token>
 *       Unsubscribe. GET shows a confirmation button; the button POSTs back
 *       with the session's securityToken. A POST whose body is
 *       "List-Unsubscribe=One-Click" (RFC 8058, sent by the mail provider's
 *       own Unsubscribe button) unsubscribes straight away.
 *
 * The tokens are HMACs of the cart id under the store's Link Secret, so a
 * guessed id gets nowhere. No parameter is named "action": core's CSRF check
 * applies to POSTs that carry one, and the one-click POST has no token.
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
    require_once dirname(__DIR__, 5) . '/shared/AbandonedCartsCapture.php';
}

$acSecret = AbandonedCartsCore::setting('ABANDONED_CARTS_SECRET');
$acCartId = (int)($_GET['cart'] ?? 0);
$acTracker = new AbandonedCartsTracker($db);

// ----- Return to Your Cart -----
if (isset($_GET['t'])) {
    $acRow = AbandonedCartsCore::tokenMatches($acCartId, $acSecret, (string)$_GET['t'], 'cart') ? $acTracker->find($acCartId) : null;
    if ($acRow === null) {
        $messageStack->add_session('shopping_cart', ABANDONED_CARTS_LINK_INVALID, 'caution');
        zen_redirect(zen_href_link(FILENAME_SHOPPING_CART, '', 'NONSSL'));
    }
    $acResult = $acTracker->restoreInto($acRow, $_SESSION['cart']);
    if (AbandonedCartsCapture::customerId() > 0 && method_exists($_SESSION['cart'], 'restore_contents')) {
        // A logged-in customer: core's own merge saves the restored lines to their account.
        $_SESSION['cart']->restore_contents();
    }
    // This visit now carries the recorded cart, so an order placed from here closes it as recovered.
    $acState = &AbandonedCartsCapture::state();
    $acState['key'] = (string)$acRow['session_key'];
    $acTracker->event($acCartId, 'clicked', (int)$acRow['step'], 'restored ' . $acResult['restored'] . ', skipped ' . $acResult['skipped']);
    if ($acResult['skipped'] > 0) {
        $messageStack->add_session('shopping_cart', sprintf(ABANDONED_CARTS_RESTORED_SOME, $acResult['skipped']), 'caution');
    } elseif ($acResult['restored'] > 0) {
        $messageStack->add_session('shopping_cart', ABANDONED_CARTS_RESTORED, 'success');
    }
    zen_redirect(zen_href_link(FILENAME_SHOPPING_CART, '', 'NONSSL'));
}

// ----- Unsubscribe -----
$acRow = AbandonedCartsCore::tokenMatches($acCartId, $acSecret, (string)($_GET['u'] ?? ''), 'unsubscribe') ? $acTracker->find($acCartId) : null;
$acView = $acRow === null ? 'invalid' : 'confirm';

if ($acRow !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acOneClick = trim((string)($_POST['List-Unsubscribe'] ?? '')) === 'One-Click';
    $acConfirmed = isset($_POST['ac_confirm'], $_POST['securityToken'], $_SESSION['securityToken'])
        && hash_equals((string)$_SESSION['securityToken'], (string)$_POST['securityToken']);
    if ($acOneClick || $acConfirmed) {
        $acTracker->unsubscribe((string)$acRow['email']);
        $acTracker->event($acCartId, 'unsubscribe', (int)$acRow['step'], $acOneClick ? 'one-click' : 'page');
        if ($acOneClick) {
            header('Content-Type: text/plain; charset=' . CHARSET);
            echo "Unsubscribed\n";
            zen_exit();
        }
        $acView = 'done';
    }
}

$acEmailShown = '';
if ($acRow !== null) {
    // Partly masked: the page is reachable by anyone holding the link.
    $acParts = explode('@', (string)$acRow['email'], 2);
    $acEmailShown = substr($acParts[0], 0, 2) . str_repeat('*', max(1, strlen($acParts[0]) - 2)) . (isset($acParts[1]) ? '@' . $acParts[1] : '');
}
$acFormAction = zen_href_link(FILENAME_ABANDONED_CART, 'cart=' . $acCartId . '&u=' . rawurlencode((string)($_GET['u'] ?? '')), 'SSL');

// A personal page: never indexed. Core's meta_tags.php builds the page title
// from NAVBAR_TITLE when a page defines it.
header('X-Robots-Tag: noindex, nofollow');
if (!defined('NAVBAR_TITLE')) {
    define('NAVBAR_TITLE', ABANDONED_CARTS_PAGE_TITLE);
}
$breadcrumb->add(ABANDONED_CARTS_PAGE_TITLE);
