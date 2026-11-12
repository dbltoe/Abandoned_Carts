<?php
/**
 * Abandoned Carts -- table names (admin).
 *
 * extra_datafiles are loaded per plugin on both sides from v1.5.8 on; the
 * definitions themselves live in AbandonedCartsCore and are guarded.
 *
 * @package  AbandonedCarts
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsCore', false)) {
    require_once dirname(__DIR__, 3) . '/shared/AbandonedCartsCore.php';
}
AbandonedCartsCore::defineTables();
