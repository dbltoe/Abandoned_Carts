<?php
/**
 * Abandoned Carts -- plugin manifest.
 *
 * pluginDescription is echoed unescaped into Plugin Manager's info box on every
 * release from v1.5.8 to v3.0.0, so the Read Me link lives here. On v1.5.8,
 * v2.0 and v2.1 the description and pluginId are written only when Plugin
 * Manager first sees the plugin, so both have to be right before the first
 * store installs it.
 *
 * The GitHub and forum links render nothing while empty: a link that 404s is
 * worse than none.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

$acPluginDir = 'zc_plugins/AbandonedCarts/v1.0.0/';
$acReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . $acPluginDir . 'readme.html';
$acGithubUrl = 'https://github.com/dbltoe/Abandoned_Carts';
$acForumUrl = '';

$acGap = '6px';
$acButton = static function ($url, $label) use ($acGap) {
    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary" role="button"'
        . ' style="margin:0 ' . $acGap . ' 0 0">' . $label . '</a>';
};
$acLinks = '<div style="margin:10px 0 0;padding:0 0 0 ' . $acGap . '">'
    . $acButton($acReadmeUrl, 'Read Me')
    . ($acGithubUrl !== '' ? $acButton($acGithubUrl, 'GitHub') : '')
    . '</div>'
    . ($acForumUrl !== ''
        ? '<div style="margin:8px 0 0;padding:0 0 0 ' . $acGap . '"><a href="' . $acForumUrl . '" target="_blank" rel="noopener noreferrer">Forum Support Thread</a></div>'
        : '');

return [
    'pluginVersion' => 'v1.0.0',
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
