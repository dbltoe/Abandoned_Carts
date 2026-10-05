<?php
/**
 * Abandoned Carts -- Customers > Abandoned Carts.
 *
 *   index.php?cmd=abandoned_carts              the list: summary, counts by status, search, pages
 *   index.php?cmd=abandoned_carts&aID=12       one cart: items, reminders, events
 *
 * The one action (stop a cart's reminders) is a POST carrying `action` and
 * `aID`. Core's init_sessions refuses any admin POST without the session's
 * securityToken before this file runs (every release 1.5.8 -> 3.0.0);
 * zen_draw_form() adds the token.
 *
 * Declares no functions: the work is in the shared classes.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

// Reached only as admin/index.php?cmd=abandoned_carts, and index.php loads the
// bootstrap (which defines IS_ADMIN_FLAG) before routing here.
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

require 'includes/application_top.php';
// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsAdmin', false)) {
    require_once __DIR__ . '/../shared/AbandonedCartsAdmin.php';
}

// The admin doesn't make $currencies for every page; pages that show money do.
if (!isset($currencies) || !is_object($currencies)) {
    if (!class_exists('currencies')) {
        require_once DIR_WS_CLASSES . 'currencies.php';
    }
    $currencies = new currencies();
}

$acAdmin = new AbandonedCartsAdmin($db);
$acNow = date('Y-m-d H:i:s');
$acH = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, CHARSET);
};
$acMoney = static function ($amount, string $currency = '') use ($currencies): string {
    return $currencies->format((float)$amount, true, $currency !== '' ? $currency : DEFAULT_CURRENCY);
};
$acStatusLabel = static function (string $status): string {
    $key = 'ABANDONED_CARTS_ADMIN_STATUS_' . strtoupper($status);
    return defined($key) ? constant($key) : $status;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stop') {
    $acPostId = (int)($_POST['aID'] ?? 0);
    $acAdminName = function_exists('zen_get_admin_name') ? (string)zen_get_admin_name((int)($_SESSION['admin_id'] ?? 0)) : 'admin';
    if ($acAdmin->stop($acPostId, 'admin: ' . $acAdminName, $acNow)) {
        $messageStack->add_session(sprintf(ABANDONED_CARTS_ADMIN_STOPPED, $acPostId), 'success');
    } else {
        $messageStack->add_session(ABANDONED_CARTS_ADMIN_ERR_NOT_FOUND, 'error');
    }
    zen_redirect(zen_href_link(FILENAME_ABANDONED_CARTS, 'aID=' . $acPostId, 'SSL'));
}

// Core's zen_href_link() hands back HTML-ready links (& already &amp;), so
// they're printed as they come; only our own values go through $acH.
$acSelf = zen_href_link(FILENAME_ABANDONED_CARTS, '', 'SSL');
$acId = (int)($_GET['aID'] ?? 0);
$acCart = $acId > 0 ? $acAdmin->detail($acId) : null;
if ($acId > 0 && $acCart === null) {
    $messageStack->add(ABANDONED_CARTS_ADMIN_ERR_NOT_FOUND, 'error');
}
if ($acCart === null) {
    $acList = $acAdmin->search((string)($_GET['status'] ?? ''), (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1));
    $acCounts = $acAdmin->counts();
    $acDays = 30;
    $acSummary = $acAdmin->summary($acDays, $acNow);
    $acCronKey = AbandonedCartsCore::setting('ABANDONED_CARTS_CRON_KEY');
    $acHealth = AbandonedCartsAdmin::schedulerHealth(AbandonedCartsCore::setting('ABANDONED_CARTS_LAST_RUN'), time());
}
$acOrderLink = static function (int $ordersId): string {
    return $ordersId > 0 ? '<a href="' . zen_href_link(FILENAME_ORDERS, 'oID=' . $ordersId . '&action=edit', 'NONSSL') . '">#' . $ordersId . '</a>' : '';
};
?>
<!DOCTYPE html>
<html <?= HTML_PARAMS ?>>
<head>
<?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
<style>
.ac-admin h2{font-size:1.2em;margin:24px 0 8px;padding-bottom:4px;border-bottom:1px solid #d8dee4}
.ac-admin .ac-counts a{margin-right:14px}
.ac-admin .ac-counts a.ac-current{font-weight:bold;text-decoration:underline}
.ac-admin .ac-search{margin:12px 0}
.ac-admin .ac-search input[type=search]{min-width:280px}
.ac-admin .ac-box{margin:12px 0 18px;padding:10px 12px;border:1px solid #d8dee4;border-radius:4px;background:#fafbfc}
.ac-admin .ac-stale{color:#a40000;font-weight:bold}
.ac-admin dl.ac-facts{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:0}
.ac-admin dl.ac-facts dt{font-weight:bold}
.ac-admin dl.ac-facts dd{margin:0}
.ac-admin code{white-space:pre-wrap;word-break:break-all}
.ac-admin .ac-options{color:#555;font-size:90%}
</style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="container-fluid ac-admin">
<?php if ($acCart === null) { ?>
  <h1><?= $acH(ABANDONED_CARTS_ADMIN_HEADING) ?></h1>
  <p><?= $acH(ABANDONED_CARTS_ADMIN_INTRO) ?></p>
<?php if (!AbandonedCartsCore::settingOn('ABANDONED_CARTS_STATUS', true)) { ?>
  <p class="ac-stale"><?= $acH(ABANDONED_CARTS_ADMIN_OFF) ?></p>
<?php } ?>

  <div class="ac-box">
    <strong><?= $acH(ABANDONED_CARTS_ADMIN_SCHEDULER) ?>:</strong>
<?php if ($acHealth[0] === '') { ?>
    <span class="ac-stale"><?= $acH(ABANDONED_CARTS_ADMIN_NEVER_RAN) ?></span>
<?php } else { ?>
    <?= $acH(sprintf(ABANDONED_CARTS_ADMIN_LAST_RAN, zen_datetime_short($acHealth[0]))) ?>
<?php if (!$acHealth[2]) { ?>
    <span class="ac-stale"><?= $acH(sprintf(ABANDONED_CARTS_ADMIN_STALE, $acHealth[1])) ?></span>
<?php } ?>
<?php } ?>
<?php if ($acCronKey !== '') { ?>
    <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="<?= $acH(AbandonedCartsCore::schedulerUrl($acCronKey)) ?>"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_RUN) ?></a>
    <span class="help-block"><?= $acH(ABANDONED_CARTS_ADMIN_RUN_HELP) ?></span>
    <details<?= $acHealth[2] ? '' : ' open' ?>>
      <summary><?= $acH(ABANDONED_CARTS_ADMIN_SETUP) ?></summary>
      <p><?= $acH(ABANDONED_CARTS_ADMIN_SETUP_CRON) ?><br><code>curl -fsSL "<?= $acH(AbandonedCartsCore::schedulerUrl($acCronKey)) ?>" &gt;/dev/null</code></p>
      <p><?= $acH(ABANDONED_CARTS_ADMIN_SETUP_URL) ?><br><code><?= $acH(AbandonedCartsCore::schedulerUrl($acCronKey)) ?></code></p>
    </details>
<?php } ?>
  </div>

  <div class="ac-box">
    <strong><?= $acH(sprintf(ABANDONED_CARTS_ADMIN_SUMMARY, $acDays)) ?>:</strong>
    <?= $acH(sprintf(ABANDONED_CARTS_ADMIN_SENT, $acSummary['sent'])) ?> &middot;
    <?= $acH(sprintf(ABANDONED_CARTS_ADMIN_RECOVERED_REVENUE, $acSummary['recovered'], $acMoney($acSummary['revenue']))) ?>
  </div>

  <p class="ac-counts">
    <a href="<?= $acSelf ?>"<?= $acList['status'] === '' ? ' class="ac-current" aria-current="page"' : '' ?>><?= $acH(ABANDONED_CARTS_ADMIN_ALL) ?> (<?= array_sum($acCounts) ?>)</a>
<?php foreach ($acCounts as $acKey => $acN) { ?>
    <a href="<?= zen_href_link(FILENAME_ABANDONED_CARTS, 'status=' . $acKey, 'SSL') ?>"<?= $acList['status'] === $acKey ? ' class="ac-current" aria-current="page"' : '' ?>><?= $acH($acStatusLabel($acKey)) ?> (<?= (int)$acN ?>)</a>
<?php } ?>
  </p>

  <form class="ac-search form-inline" method="get" action="<?= $acSelf ?>" role="search">
    <input type="hidden" name="cmd" value="<?= $acH(FILENAME_ABANDONED_CARTS) ?>">
    <input type="hidden" name="status" value="<?= $acH($acList['status']) ?>">
    <label for="ac-q"><?= $acH(ABANDONED_CARTS_ADMIN_SEARCH) ?></label>
    <input type="search" class="form-control" name="q" id="ac-q" value="<?= $acH($acList['query']) ?>">
    <button type="submit" class="btn btn-primary"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_SEARCH) ?></button>
    <a class="btn btn-default" href="<?= $acSelf ?>"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_RESET) ?></a>
  </form>

<?php if ($acList['rows'] === []) { ?>
  <p><?= $acH(ABANDONED_CARTS_ADMIN_NONE) ?></p>
<?php } else { ?>
  <table class="table table-striped table-condensed">
    <thead><tr>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_ID) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_SHOPPER) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_ITEMS) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_TOTAL) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_ACTIVITY) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_STATUS) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_REMINDERS) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_ORDER) ?></th>
      <th scope="col"><span class="sr-only"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_DETAILS) ?></span></th>
    </tr></thead>
    <tbody>
<?php foreach ($acList['rows'] as $acRow) {
    $acRowId = (int)$acRow['abandoned_carts_id'];
    $acItems = [];
    foreach ($acRow['lines'] as $acLine) {
        $acItems[] = AbandonedCartsCore::quantity($acLine['quantity'] ?? 1) . ' x ' . ($acLine['name'] ?? '');
    }
    ?>
      <tr>
        <td>#<?= $acRowId ?></td>
        <td><?php if ((int)$acRow['customers_id'] > 0) { ?><a href="<?= zen_href_link(FILENAME_CUSTOMERS, 'cID=' . (int)$acRow['customers_id'] . '&action=edit', 'NONSSL') ?>"><?= $acH($acRow['firstname']) ?></a><?php } else { ?><?= $acH($acRow['firstname']) ?> <small>(<?= $acH(ABANDONED_CARTS_ADMIN_GUEST) ?>)</small><?php } ?><br><small><?= $acH($acRow['email']) ?></small></td>
        <td><?= $acH(implode(', ', $acItems)) ?></td>
        <td><?= $acH($acMoney($acRow['cart_total'])) ?></td>
        <td><?= $acH(zen_datetime_short($acRow['last_activity'])) ?></td>
        <td><?= $acH($acStatusLabel((string)$acRow['status'])) ?></td>
        <td><?= (int)$acRow['step'] ?></td>
        <td><?= $acOrderLink((int)$acRow['orders_id']) ?></td>
        <td><a class="btn btn-default btn-xs" href="<?= zen_href_link(FILENAME_ABANDONED_CARTS, 'aID=' . $acRowId, 'SSL') ?>" aria-label="<?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_DETAILS . ' #' . $acRowId) ?>"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_DETAILS) ?></a></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php
    $acFirst = ($acList['page'] - 1) * AbandonedCartsAdmin::PER_PAGE + 1;
    $acLast = min($acList['total'], $acList['page'] * AbandonedCartsAdmin::PER_PAGE);
    $acKeep = ($acList['status'] !== '' ? '&status=' . $acList['status'] : '') . ($acList['query'] !== '' ? '&q=' . urlencode($acList['query']) : '');
    ?>
  <nav aria-label="<?= $acH(ABANDONED_CARTS_ADMIN_HEADING) ?>">
    <?= $acH(sprintf(ABANDONED_CARTS_ADMIN_SHOWING, $acFirst, $acLast, $acList['total'])) ?>
<?php if ($acList['page'] > 1) { ?>
    <a href="<?= zen_href_link(FILENAME_ABANDONED_CARTS, 'page=' . ($acList['page'] - 1) . $acKeep, 'SSL') ?>"><?= $acH(ABANDONED_CARTS_ADMIN_PREVIOUS) ?></a>
<?php } ?>
<?php if ($acList['page'] < $acList['pages']) { ?>
    <a href="<?= zen_href_link(FILENAME_ABANDONED_CARTS, 'page=' . ($acList['page'] + 1) . $acKeep, 'SSL') ?>"><?= $acH(ABANDONED_CARTS_ADMIN_NEXT) ?></a>
<?php } ?>
  </nav>
<?php } ?>

<?php } else {
    $acIsOpen = $acCart['status'] === AbandonedCartsCore::OPEN;
    $acMore = $acIsOpen && (int)$acCart['step'] < AbandonedCartsCore::maxSteps();
    ?>
  <p><a href="<?= $acSelf ?>">&larr; <?= $acH(ABANDONED_CARTS_ADMIN_BACK) ?></a></p>
  <h1><?= $acH(sprintf(ABANDONED_CARTS_ADMIN_DETAIL_HEADING, (int)$acCart['abandoned_carts_id'])) ?></h1>

  <dl class="ac-facts">
    <dt><?= $acH(ABANDONED_CARTS_ADMIN_COL_SHOPPER) ?></dt>
    <dd><?php if ((int)$acCart['customers_id'] > 0) { ?><a href="<?= zen_href_link(FILENAME_CUSTOMERS, 'cID=' . (int)$acCart['customers_id'] . '&action=edit', 'NONSSL') ?>"><?= $acH($acCart['firstname']) ?></a><?php } else { ?><?= $acH($acCart['firstname']) ?> (<?= $acH(ABANDONED_CARTS_ADMIN_GUEST) ?>)<?php } ?> &lt;<?= $acH($acCart['email']) ?>&gt;</dd>
    <dt><?= $acH(ABANDONED_CARTS_ADMIN_COL_STATUS) ?></dt>
    <dd><?= $acH($acStatusLabel((string)$acCart['status'])) ?></dd>
    <dt><?= $acH(ABANDONED_CARTS_ADMIN_COL_ACTIVITY) ?></dt>
    <dd><?= $acH(zen_datetime_short($acCart['last_activity'])) ?></dd>
    <dt><?= $acH(ABANDONED_CARTS_ADMIN_COL_REMINDERS) ?></dt>
    <dd><?= (int)$acCart['step'] ?>
<?php if ($acMore) { ?>
      &middot; <?= $acH(ABANDONED_CARTS_ADMIN_NEXT_SEND) ?>: <?= $acH(zen_datetime_short($acCart['next_send'])) ?>
<?php } elseif ($acIsOpen) { ?>
      &middot; <?= $acH(ABANDONED_CARTS_ADMIN_NO_MORE) ?>
<?php } ?>
    </dd>
<?php if ((int)$acCart['orders_id'] > 0) { ?>
    <dt><?= $acH(ABANDONED_CARTS_ADMIN_COL_ORDER) ?></dt>
    <dd><?= $acOrderLink((int)$acCart['orders_id']) ?> (<?= $acH($acMoney($acCart['order_total'])) ?>)</dd>
<?php } ?>
  </dl>

  <h2><?= $acH(ABANDONED_CARTS_ADMIN_COL_ITEMS) ?></h2>
  <table class="table table-condensed">
    <tbody>
<?php foreach ($acCart['lines'] as $acLine) { ?>
      <tr>
        <td><?= $acH(AbandonedCartsCore::quantity($acLine['quantity'] ?? 1)) ?> &times;</td>
        <td><a href="<?= zen_href_link(FILENAME_PRODUCT, 'pID=' . (int)($acLine['products_id'] ?? 0) . '&action=new_product', 'NONSSL') ?>"><?= $acH($acLine['name'] ?? '') ?></a>
<?php foreach ((array)($acLine['options'] ?? []) as $acOption) { ?>
          <br><span class="ac-options"><?= $acH($acOption) ?></span>
<?php } ?>
        </td>
        <td class="text-right"><?= $acH($acMoney($acLine['line_total'] ?? 0)) ?></td>
      </tr>
<?php } ?>
      <tr><td></td><td class="text-right"><strong><?= $acH(ABANDONED_CARTS_ADMIN_COL_TOTAL) ?></strong></td><td class="text-right"><strong><?= $acH($acMoney($acCart['cart_total'])) ?></strong></td></tr>
    </tbody>
  </table>

<?php if ($acIsOpen) { ?>
  <?= zen_draw_form('ac_stop', FILENAME_ABANDONED_CARTS, '', 'post') ?>
    <input type="hidden" name="action" value="stop">
    <input type="hidden" name="aID" value="<?= (int)$acCart['abandoned_carts_id'] ?>">
    <button type="submit" class="btn btn-warning"><?= $acH(ABANDONED_CARTS_ADMIN_BUTTON_STOP) ?></button>
  </form>
<?php } ?>

  <h2><?= $acH(ABANDONED_CARTS_ADMIN_COL_EVENT) ?></h2>
  <table class="table table-striped table-condensed">
    <thead><tr>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_DATE) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_EVENT) ?></th>
      <th scope="col"><?= $acH(ABANDONED_CARTS_ADMIN_COL_DETAIL) ?></th>
    </tr></thead>
    <tbody>
<?php foreach ($acCart['events'] as $acEvent) {
    $acEventKey = 'ABANDONED_CARTS_ADMIN_EVENT_' . strtoupper(preg_replace('/[^a-z_]/', '', (string)$acEvent['event']));
    ?>
      <tr>
        <td><?= $acH(zen_datetime_short($acEvent['date_added'])) ?></td>
        <td><?= $acH(defined($acEventKey) ? constant($acEventKey) : $acEvent['event']) ?><?= (int)$acEvent['step'] > 0 ? ' (' . (int)$acEvent['step'] . ')' : '' ?></td>
        <td><?= $acH($acEvent['detail']) ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php } ?>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php
require DIR_WS_INCLUDES . 'application_bottom.php';
