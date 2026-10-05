<?php
/**
 * Abandoned Carts -- storefront text, including the reminder email.
 *
 * The admin side loads this file too (AbandonedCartsCore::loadStorefrontText)
 * when it sends or previews the email, so the wording lives in one place.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

$define = [
    // The reminder email. %s in the subject and intro is the store name.
    'ABANDONED_CARTS_EMAIL_SUBJECT' => 'You left something in your cart at %s',
    'ABANDONED_CARTS_EMAIL_GREETING' => 'Hi %s,',
    'ABANDONED_CARTS_EMAIL_GREETING_NONAME' => 'Hello,',
    'ABANDONED_CARTS_EMAIL_INTRO' => 'You left these in your cart at %s. They\'re saved for you:',
    'ABANDONED_CARTS_EMAIL_TOTAL' => 'Cart total: %s',
    'ABANDONED_CARTS_EMAIL_TOTAL_LABEL' => 'Cart total',
    'ABANDONED_CARTS_EMAIL_CTA_TEXT' => 'Pick up where you left off:',
    'ABANDONED_CARTS_EMAIL_BUTTON' => 'Return to Your Cart',
    'ABANDONED_CARTS_EMAIL_CLOSING' => 'Prices and availability are confirmed at checkout.',
    'ABANDONED_CARTS_EMAIL_UNSUBSCRIBE_TEXT' => 'Don\'t want cart reminders? Unsubscribe: %s',
    'ABANDONED_CARTS_EMAIL_UNSUBSCRIBE_LINK' => 'Unsubscribe from cart reminders',

    // The Return to Your Cart link.
    'ABANDONED_CARTS_RESTORED' => 'Welcome back! Your cart is just as you left it.',
    'ABANDONED_CARTS_RESTORED_SOME' => 'Welcome back! We restored your cart, but %d item(s) are no longer available.',
    'ABANDONED_CARTS_LINK_INVALID' => 'That cart link has expired. Your cart is shown below.',

    // The unsubscribe page.
    'ABANDONED_CARTS_PAGE_TITLE' => 'Cart Reminders',
    'ABANDONED_CARTS_UNSUB_HEADING' => 'Unsubscribe from Cart Reminders',
    'ABANDONED_CARTS_UNSUB_CONFIRM' => 'Stop emailing %s about items left in a cart?',
    'ABANDONED_CARTS_UNSUB_BUTTON' => 'Unsubscribe',
    'ABANDONED_CARTS_UNSUB_DONE' => 'Done. We won\'t email %s about items left in a cart again. Order confirmations and other emails aren\'t affected.',
    'ABANDONED_CARTS_UNSUB_INVALID' => 'That unsubscribe link isn\'t valid. If you keep getting cart reminders, please contact us.',
];

return $define;
