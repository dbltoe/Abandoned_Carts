<?php
/**
 * Abandoned Carts -- start the cart observer before core runs cart actions.
 *
 * Core handles add, update and remove (init_cart_handler.php) at breakpoint
 * 140 and only instantiates auto.* observers at 175, so an auto.* observer
 * never sees a shopper's add-to-cart. This one is created at 139.
 *
 * The file is named class.*, not auto.*: init_observers.php includes every
 * auto.* file itself, and a second include would redeclare the class.
 *
 * @package  AbandonedCarts
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$autoLoadConfig[0][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/class.abandoned_carts_cart.php',
];
$autoLoadConfig[139][] = [
    'autoType' => 'classInstantiate',
    'className' => 'AbandonedCartsCartObserver',
    'objectName' => 'abandonedCartsCartObserver',
];
