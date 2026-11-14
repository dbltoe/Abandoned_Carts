<?php
/**
 * Abandoned Carts -- plugin manifest.
 *
 * pluginDescription is echoed unescaped into Plugin Manager's info box on every
 * release from v1.5.8 to v3.0.0, so the ReadMe link lives here. On v1.5.8,
 * v2.0 and v2.1 the description and pluginId are written only when Plugin
 * Manager first sees the plugin, so both have to be right before the first
 * store installs it. After that only this plugin's installer refreshes the
 * description there (at Install, Upgrade and Uninstall), which is what keeps
 * the one state-dependent part, the Settings button, right.
 *
 * The GitHub and forum links render nothing while empty: a link that 404s is
 * worse than none.
 *
 * @package  AbandonedCarts
 * @license  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU General Public License v2.0
 */

/*
 * ReadMe opens README.md through the plugin's own admin page
 * (index.php?cmd=abandoned_carts_readme): behind the admin login, superusers
 * only, in its own window. The button is shown only to superusers; the page
 * enforces that too, because Zen Cart 1.5.8-2.1 keep the description they
 * first saw. Forum Thread and GitHub go off-site, so they open in a new
 * window with rel="noopener noreferrer" (zencart/zencart#8031).
 */
$acReadmeUrl = 'index.php?cmd=abandoned_carts_readme';
// Zen Cart 3.0.0 reads every manifest on every admin page, early (template resolver),
// before admin access is set up: zen_is_superuser() there is a fatal "Undefined constant
// SUPERUSER_PROFILE" that takes down the whole admin. Ask only once that exists.
$acShowReadme = true;
if (function_exists('zen_is_superuser') && defined('SUPERUSER_PROFILE')) {
    $acShowReadme = zen_is_superuser();
}
$acGithubUrl = 'https://github.com/dbltoe/Abandoned_Carts';
$acForumUrl = 'https://www.zen-cart.com/threads/207392';

/*
 * The Settings button: Configuration > Abandoned Carts, shown only once the
 * plugin is installed. Its gID is assigned at install, so it's read from the
 * admin page the installer registers (page key configAbandonedCarts). The
 * link is relative because Plugin Manager is an admin page, so it survives a
 * domain or admin-folder rename.
 *
 * Zen Cart reads manifests only on the Plugin Manager page, but the lookup is
 * guarded so a read anywhere else (no database, catalog side, CLI) just
 * leaves the button out. v2.2 and later re-read this file on every Plugin
 * Manager visit; on v1.5.8, v2.0 and v2.1 the installer saves the re-read
 * description at Install, Upgrade and Uninstall (acRefreshPluginDescription).
 */
$acSettingsUrl = '';
if (defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true && defined('TABLE_ADMIN_PAGES')
    && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
    $acPage = $GLOBALS['db']->Execute(
        "SELECT main_page, page_params FROM " . TABLE_ADMIN_PAGES
        . " WHERE page_key = 'configAbandonedCarts' LIMIT 1"
    );
    if (!$acPage->EOF && defined($acPage->fields['main_page'])) {
        $acSettingsUrl = 'index.php?cmd=' . constant($acPage->fields['main_page'])
            . '&' . $acPage->fields['page_params'];
    }
}

/*
 * One row: ReadMe | Settings | Forum Thread | GitHub, styled like Plugin
 * Manager's own buttons. Spacing is inline, which beats any theme .btn
 * margin: every gap is 4px (the wrapper supplies the space before the first
 * button, each button the space after itself) and the side padding is 8px, so
 * all four fit on one line from a 1366px-wide window up and the Author line
 * stays above the fold. Settings opens in the same tab (it's the admin
 * itself); the others open in a new one. ReadMe is left out for an admin
 * who isn't a superuser.
 */
$acGap = '4px';
$acButtonStyle = ' style="margin:0 ' . $acGap . ' 0 0;padding-left:8px;padding-right:8px"';
$acButton = static function ($url, $label, $newTab = true) use ($acButtonStyle) {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
        . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '')
        . ' class="btn btn-primary" role="button"' . $acButtonStyle . '>' . $label . '</a>';
};
$acLinks = '<div style="margin:10px 0 0;padding:0 0 0 ' . $acGap . '">'
    . ($acShowReadme ? $acButton($acReadmeUrl, 'ReadMe') : '')
    . ($acSettingsUrl !== '' ? $acButton($acSettingsUrl, 'Settings', false) : '')
    . ($acForumUrl !== '' ? $acButton($acForumUrl, 'Forum Thread') : '')
    . ($acGithubUrl !== '' ? $acButton($acGithubUrl, 'GitHub') : '')
    . '</div>';

return [
    'pluginVersion' => 'v1.0.4',
    'pluginName' => 'Abandoned Carts',
    'pluginDescription' =>
        'Emails shoppers who left items in their cart, automatically, with a link that puts the cart '
        . 'back exactly as they left it. Works for customers with an account and for One Page Checkout '
        . 'guests who saved their contact details, and stops the moment they order.'
        . $acLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    // Plugins Library id. 0 until the Library assigns one (submissions are on
    // hold); it must be set before the first store installs from the Library.
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200', 'v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $acGithubUrl,
    'pluginGroups' => [],
];
