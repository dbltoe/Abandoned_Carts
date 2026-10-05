<?php
/**
 * Abandoned Carts -- storefront page names.
 *
 * In extra_datafiles (loaded per plugin from v1.5.8 on) rather than a root
 * filenames.php, which only v2.2.0+ loads. Guarded, because v2.2.0+ may also
 * pick up a root file.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('FILENAME_ABANDONED_CART')) {
    define('FILENAME_ABANDONED_CART', 'abandoned_cart');
}
if (!defined('FILENAME_ABANDONED_CARTS_CRON')) {
    define('FILENAME_ABANDONED_CARTS_CRON', 'abandoned_carts_cron');
}
