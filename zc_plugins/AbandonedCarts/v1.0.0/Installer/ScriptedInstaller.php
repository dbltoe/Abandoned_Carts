<?php
/**
 * Abandoned Carts -- Plugin Manager installer.
 *
 * Limited to the API every supported release has (v1.5.8 -> v3.0.0):
 *
 *   - Zen Cart calls only executeInstall(), executeUninstall() and
 *     executeUpgrade(). Any other method here is a helper, never a hook.
 *   - Returning false does not refuse an install; an errorContainer entry
 *     does. All checks run before anything is written, because nothing rolls
 *     back.
 *   - executeUpgrade() takes an optional argument: v1.5.8 passes none.
 *   - Database work goes through executeInstallerSql() or the queryFactory;
 *     the configuration helpers added in v2.0.1/v2.1.0 don't exist on v1.5.8.
 *
 * Every step is idempotent, so an upgrade is a re-run of the install.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

// Guarded: on a version upgrade the old version's files may already have
// loaded this class from the other version folder ("Cannot redeclare class").
if (!class_exists('AbandonedCartsCore', false)) {
    require_once dirname(__DIR__) . '/shared/AbandonedCartsCore.php';
}

class ScriptedInstaller extends ScriptedInstallBase
{
    public const CONFIG_GROUP_TITLE = 'Abandoned Carts';

    public const ADMIN_PAGE_KEYS = ['configAbandonedCarts', 'customersAbandonedCarts'];

    /** Settings an uninstall that keeps the data also keeps: the cron job and old email links carry them. */
    public const KEPT_KEYS = ['ABANDONED_CARTS_CRON_KEY', 'ABANDONED_CARTS_SECRET'];

    /**
     * The plugin's tables, by TABLE_* constant (each carries DB_PREFIX), with
     * the columns that prove an existing table is ours and not another plugin's.
     */
    public const OWNERSHIP = [
        'TABLE_ABANDONED_CARTS' => ['session_key', 'cart_lines', 'sending_step'],
        'TABLE_ABANDONED_CARTS_EVENTS' => ['abandoned_carts_id', 'event'],
        'TABLE_ABANDONED_CARTS_UNSUBSCRIBES' => ['email_hash'],
    ];

    protected function executeInstall()
    {
        AbandonedCartsCore::defineTables();

        $clash = $this->acForeignTable();
        if ($clash !== '') {
            $this->errorContainer->addError(
                0,
                'Abandoned Carts was not installed: the database already has a table named "' . $clash
                . '" that belongs to something else. Rename or remove that table, then install again.',
                true
            );
            return false;
        }

        // Known before the keys are written: the scheduler address in its
        // description carries the key. An upgrade keeps the store's existing ones.
        $cronKey = $this->acExistingValue('ABANDONED_CARTS_CRON_KEY');
        if ($cronKey === '') {
            $cronKey = bin2hex(random_bytes(20));
        }
        $secret = $this->acExistingValue('ABANDONED_CARTS_SECRET');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
        }

        $groupId = $this->acGetOrCreateConfigGroup();
        if ($groupId === 0) {
            return false;
        }
        if ($this->acAddConfigurationKeys($groupId, $cronKey) === false) {
            return false;
        }
        foreach (['ABANDONED_CARTS_CRON_KEY' => $cronKey, 'ABANDONED_CARTS_SECRET' => $secret] as $key => $value) {
            $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . $this->dbConn->prepare_input($value) . "'"
                . " WHERE configuration_key = '" . $this->dbConn->prepare_input($key) . "' AND configuration_value = '' LIMIT 1"
            );
        }
        $this->acRegisterAdminPages($groupId);
        if ($this->acCreateTables() === false) {
            return false;
        }

        $this->acLog('Abandoned Carts: installed/upgraded.');
        return true;
    }

    protected function executeUpgrade($oldVersion = null)
    {
        return $this->executeInstall();
    }

    protected function executeUninstall()
    {
        AbandonedCartsCore::defineTables();
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);

        $deleteData = $this->acExistingValue('ABANDONED_CARTS_DELETE_ON_UNINSTALL') === 'true';
        if ($deleteData) {
            foreach (array_keys(self::OWNERSHIP) as $table) {
                $this->executeInstallerSql("DROP TABLE IF EXISTS " . constant($table));
            }
        }

        // Keeping the data keeps the scheduler key and the link secret too: the
        // store's cron job carries the one, and every email already sent carries
        // the other. A re-install finds the rows and adopts them.
        $keep = $deleteData ? '' : "configuration_key NOT IN ('" . implode("', '", self::KEPT_KEYS) . "') AND ";
        $groupId = $this->acGetConfigGroupId();
        if ($groupId > 0) {
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION . " WHERE " . $keep . "configuration_group_id = " . $groupId);
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id = " . $groupId);
        }
        // Strays outside the group, by name: only this plugin's own keys, never
        // Abandoned Carts Pro's (ABANDONED_CARTS_PRO_*), which Pro removes itself.
        $ours = array_map(static function ($key) {
            return $key['key'];
        }, $this->acConfigurationKeys());
        $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION . " WHERE " . $keep . "configuration_key IN ('" . implode("', '", $ours) . "')");

        $this->acLog('Abandoned Carts: uninstalled' . ($deleteData ? ', data deleted.' : ', cart records kept.'));
        return true;
    }

    /* ----------------------------------------------------------------- *
     * Checks
     * ----------------------------------------------------------------- */

    /** The first table that exists but isn't shaped like ours, or ''. */
    protected function acForeignTable(): string
    {
        foreach (self::OWNERSHIP as $table => $markers) {
            $full = constant($table);
            $r = AbandonedCartsCore::fresh($this->dbConn, "SHOW TABLES LIKE '" . $this->dbConn->prepare_input($full) . "'");
            if ($r->EOF) {
                continue;
            }
            $columns = [];
            $c = AbandonedCartsCore::fresh($this->dbConn, "SHOW COLUMNS FROM " . $full);
            while (!$c->EOF) {
                $columns[] = $c->fields['Field'];
                $c->MoveNext();
            }
            if (array_diff($markers, $columns) !== []) {
                return $full;
            }
        }
        return '';
    }

    /* ----------------------------------------------------------------- *
     * Tables
     * ----------------------------------------------------------------- */

    protected function acCreateTables(): bool
    {
        $never = "'" . AbandonedCartsCore::NEVER . "'";
        $sql = [
            "CREATE TABLE IF NOT EXISTS " . TABLE_ABANDONED_CARTS . " (
                abandoned_carts_id int(11) unsigned NOT NULL AUTO_INCREMENT,
                session_key char(32) NOT NULL DEFAULT '',
                session_id varchar(128) NOT NULL DEFAULT '',
                customers_id int(11) NOT NULL DEFAULT 0,
                is_guest tinyint(1) NOT NULL DEFAULT 0,
                email varchar(96) NOT NULL DEFAULT '',
                firstname varchar(64) NOT NULL DEFAULT '',
                languages_id int(11) NOT NULL DEFAULT 0,
                language varchar(32) NOT NULL DEFAULT '',
                currency char(3) NOT NULL DEFAULT '',
                contents mediumtext,
                cart_lines mediumtext,
                item_count float NOT NULL DEFAULT 0,
                cart_total decimal(15,4) NOT NULL DEFAULT 0.0000,
                status varchar(16) NOT NULL DEFAULT 'open',
                step tinyint(3) NOT NULL DEFAULT 0,
                sending_step tinyint(3) NOT NULL DEFAULT 0,
                sending_since datetime NOT NULL DEFAULT $never,
                next_send datetime NOT NULL DEFAULT $never,
                last_sent datetime NOT NULL DEFAULT $never,
                last_activity datetime NOT NULL DEFAULT $never,
                coupon_id int(11) NOT NULL DEFAULT 0,
                orders_id int(11) NOT NULL DEFAULT 0,
                order_total decimal(15,4) NOT NULL DEFAULT 0.0000,
                date_added datetime NOT NULL DEFAULT $never,
                last_modified datetime NOT NULL DEFAULT $never,
                PRIMARY KEY (abandoned_carts_id),
                KEY idx_ac_session (session_key),
                KEY idx_ac_due (status, next_send),
                KEY idx_ac_customer (customers_id),
                KEY idx_ac_email (email),
                KEY idx_ac_activity (last_activity)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_ABANDONED_CARTS_EVENTS . " (
                abandoned_carts_events_id int(11) unsigned NOT NULL AUTO_INCREMENT,
                abandoned_carts_id int(11) NOT NULL DEFAULT 0,
                event varchar(32) NOT NULL DEFAULT '',
                step tinyint(3) NOT NULL DEFAULT 0,
                detail varchar(255) NOT NULL DEFAULT '',
                date_added datetime NOT NULL DEFAULT $never,
                PRIMARY KEY (abandoned_carts_events_id),
                KEY idx_ac_events_cart (abandoned_carts_id)
            )",
            "CREATE TABLE IF NOT EXISTS " . TABLE_ABANDONED_CARTS_UNSUBSCRIBES . " (
                email_hash char(64) NOT NULL DEFAULT '',
                date_added datetime NOT NULL DEFAULT $never,
                PRIMARY KEY (email_hash)
            )",
        ];
        foreach ($sql as $statement) {
            if ($this->executeInstallerSql($statement) === false) {
                return false;
            }
        }
        return true;
    }

    /* ----------------------------------------------------------------- *
     * Configuration
     * ----------------------------------------------------------------- */

    protected function acConfigurationKeys(string $cronKey = ''): array
    {
        $yesNo = "zen_cfg_select_option(array('true', 'false'), ";
        $readOnly = 'zen_cfg_read_only(';
        $schedulerUrl = htmlspecialchars(AbandonedCartsCore::schedulerUrl($cronKey), ENT_QUOTES, 'UTF-8');

        return [
            [
                'key' => 'ABANDONED_CARTS_STATUS',
                'title' => 'Send Reminder E-Mails?',
                'value' => 'true',
                'description' => 'When true, the scheduler emails shoppers who left items in their cart. When false, carts are still recorded (so nothing is missed when you turn it back on) but no email is sent.',
                'sort_order' => 10,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'ABANDONED_CARTS_DELAY_HOURS',
                'title' => 'Reminder E-Mail, Hours After Last Activity',
                'value' => '1',
                'description' => 'How long after the shopper last changed their cart the reminder is sent. 1 to 168 (one week).<br><br>The scheduler has to run at least this often for the timing to hold; every 15 minutes is best.',
                'sort_order' => 20,
                'set_function' => '',
            ],
            [
                'key' => 'ABANDONED_CARTS_MAX_AGE_DAYS',
                'title' => 'Don\'t Remind About Carts Older Than (Days)',
                'value' => '7',
                'description' => 'A cart whose last activity is older than this is never emailed, so turning reminders on (or a scheduler that stopped for a while) doesn\'t email about stale carts. 1 to 60.',
                'sort_order' => 30,
                'set_function' => '',
            ],
            [
                'key' => 'ABANDONED_CARTS_MIN_VALUE',
                'title' => 'Minimum Cart Value',
                'value' => '0',
                'description' => 'Carts worth less than this (in your default currency, with tax when your prices show tax) are not emailed. 0 for any cart.',
                'sort_order' => 40,
                'set_function' => '',
            ],
            [
                'key' => 'ABANDONED_CARTS_EMAIL_GUESTS',
                'title' => 'E-Mail Guests?',
                'value' => 'true',
                'description' => 'One Page Checkout\'s guest checkout: when true, a guest who saved their contact details at checkout and then left is emailed too. When false, only shoppers with an account are.',
                'sort_order' => 50,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'ABANDONED_CARTS_NEWSLETTER_ONLY',
                'title' => 'E-Mail Only Newsletter Subscribers?',
                'value' => 'false',
                'description' => 'When true, only customers who have opted in to your newsletter are emailed. Guests never opt in, so none are emailed. Stores that must have consent before sending marketing email (for example in the EU, UK or Canada) should consider true.',
                'sort_order' => 60,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'ABANDONED_CARTS_ACTIVE_MINUTES',
                'title' => 'Wait While the Shopper Is Still Browsing (Minutes)',
                'value' => '20',
                'description' => 'A shopper Who\'s Online shows clicking around in the last this-many minutes isn\'t emailed yet; the reminder waits until they\'ve gone. 0 to turn the check off.',
                'sort_order' => 70,
                'set_function' => '',
            ],
            [
                'key' => 'ABANDONED_CARTS_RETENTION_DAYS',
                'title' => 'Keep Cart Records (Days)',
                'value' => '30',
                'description' => 'Cart records, including the email addresses guests gave at checkout, are deleted this many days after the cart was last active. 7 to 365. The unsubscribe list keeps only a one-way hash of each address and is never purged.',
                'sort_order' => 80,
                'set_function' => '',
            ],
            [
                'key' => 'ABANDONED_CARTS_DELETE_ON_UNINSTALL',
                'title' => 'Delete Cart Records on Uninstall?',
                'value' => 'false',
                'description' => 'When false, uninstalling keeps the cart records, the unsubscribe list, the scheduler key and the link secret, so a re-install picks up where it left off and links in emails already sent still work.',
                'sort_order' => 90,
                'set_function' => $yesNo,
            ],
            [
                'key' => 'ABANDONED_CARTS_CRON_KEY',
                'title' => 'Scheduler Key',
                'value' => '',
                'description' => 'The secret in the scheduler address. Generated at install.<br><br>The scheduler sends the reminders. Run it every 15 minutes with a cron job using this command. In cPanel (Cron Jobs), enter Minute */15, and * for Hour, Day, Month and Weekday.<br><br><code>curl -fsSL "' . $schedulerUrl . '" &gt;/dev/null</code><br><br>Or have an outside cron service open this address every 15 minutes:<br><code>' . $schedulerUrl . '</code>',
                'sort_order' => 900,
                'set_function' => $readOnly,
            ],
            [
                'key' => 'ABANDONED_CARTS_SECRET',
                'title' => 'Link Secret',
                'value' => '',
                'description' => 'Signs the Return to Your Cart and Unsubscribe links in the emails. Generated at install; changing it breaks every link already sent.',
                'sort_order' => 910,
                'set_function' => $readOnly,
            ],
            [
                'key' => 'ABANDONED_CARTS_LAST_RUN',
                'title' => 'Scheduler Last Ran',
                'value' => '',
                'description' => 'Set by the scheduler each time it runs. Customers > Abandoned Carts warns when it hasn\'t run for a while.',
                'sort_order' => 920,
                'set_function' => $readOnly,
            ],
        ];
    }

    protected function acAddConfigurationKeys(int $groupId, string $cronKey = '')
    {
        $db = $this->dbConn;
        foreach ($this->acConfigurationKeys($cronKey) as $key) {
            $setFunction = empty($key['set_function']) ? 'NULL' : "'" . $db->prepare_input($key['set_function']) . "'";
            $ok = $this->executeInstallerSql(
                "INSERT IGNORE INTO " . TABLE_CONFIGURATION . "
                    (configuration_title, configuration_key, configuration_value, configuration_description,
                     configuration_group_id, sort_order, date_added, use_function, set_function)
                 VALUES
                    ('" . $db->prepare_input($key['title']) . "', '" . $db->prepare_input($key['key']) . "',
                     '" . $db->prepare_input($key['value']) . "', '" . $db->prepare_input($key['description']) . "',
                     " . $groupId . ", " . (int)$key['sort_order'] . ", now(), NULL, " . $setFunction . ")"
            );
            if ($ok === false) {
                return false;
            }
            // The value belongs to the owner and survives an upgrade; the
            // wording, order and input type belong to the plugin.
            $ok = $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_title = '" . $db->prepare_input($key['title']) . "',
                        configuration_description = '" . $db->prepare_input($key['description']) . "',
                        configuration_group_id = " . $groupId . ",
                        sort_order = " . (int)$key['sort_order'] . ",
                        set_function = " . $setFunction . "
                  WHERE configuration_key = '" . $db->prepare_input($key['key']) . "'
                  LIMIT 1"
            );
            if ($ok === false) {
                return false;
            }
        }
        return true;
    }

    protected function acExistingValue(string $key): string
    {
        $r = AbandonedCartsCore::fresh(
            $this->dbConn,
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . $this->dbConn->prepare_input($key) . "' LIMIT 1"
        );
        return $r->EOF ? '' : (string)$r->fields['configuration_value'];
    }

    protected function acGetConfigGroupId(): int
    {
        $r = AbandonedCartsCore::fresh(
            $this->dbConn,
            "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP
            . " WHERE configuration_group_title = '" . $this->dbConn->prepare_input(self::CONFIG_GROUP_TITLE) . "' LIMIT 1"
        );
        return $r->EOF ? 0 : (int)$r->fields['configuration_group_id'];
    }

    protected function acGetOrCreateConfigGroup(): int
    {
        $id = $this->acGetConfigGroupId();
        if ($id > 0) {
            return $id;
        }
        $ok = $this->executeInstallerSql(
            "INSERT INTO " . TABLE_CONFIGURATION_GROUP . "
                (configuration_group_title, configuration_group_description, sort_order, visible)
             VALUES ('" . $this->dbConn->prepare_input(self::CONFIG_GROUP_TITLE) . "',
                     'Reminder emails to shoppers who left items in their cart.', 0, 1)"
        );
        if ($ok === false) {
            return 0;
        }
        $id = $this->acGetConfigGroupId();
        if ($id > 0) {
            $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION_GROUP . " SET sort_order = " . $id . " WHERE configuration_group_id = " . $id . " LIMIT 1"
            );
        }
        return $id;
    }

    /** Without an admin_pages row the group never shows under Configuration. */
    protected function acRegisterAdminPages(int $groupId): void
    {
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);
        zen_register_admin_page(
            'configAbandonedCarts',
            'BOX_CONFIGURATION_ABANDONED_CARTS',
            'FILENAME_CONFIGURATION',
            'gID=' . $groupId,
            'configuration',
            'Y',
            $groupId
        );
        zen_register_admin_page(
            'customersAbandonedCarts',
            'BOX_CUSTOMERS_ABANDONED_CARTS',
            'FILENAME_ABANDONED_CARTS',
            '',
            'customers',
            'Y',
            55
        );
    }

    protected function acLog(string $message): void
    {
        if (function_exists('zen_record_admin_activity')) {
            zen_record_admin_activity($message, 'info');
        }
    }
}
