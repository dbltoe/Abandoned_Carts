<?php
/**
 * Abandoned Carts -- admin page names, and the storefront pages the admin
 * links to (the scheduler and the email links).
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('FILENAME_ABANDONED_CARTS')) {
    define('FILENAME_ABANDONED_CARTS', 'abandoned_carts');
}
if (!defined('FILENAME_ABANDONED_CART')) {
    define('FILENAME_ABANDONED_CART', 'abandoned_cart');
}
if (!defined('FILENAME_ABANDONED_CARTS_CRON')) {
    define('FILENAME_ABANDONED_CARTS_CRON', 'abandoned_carts_cron');
}
