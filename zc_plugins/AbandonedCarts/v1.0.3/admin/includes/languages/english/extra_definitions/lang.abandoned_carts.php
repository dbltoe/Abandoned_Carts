<?php
/**
 * Abandoned Carts -- admin text (English).
 *
 * In extra_definitions so the menu labels exist before the menus are drawn.
 *
 * @package  AbandonedCarts
 * @license  GNU General Public License v2.0 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 */

$define = [
    'BOX_CONFIGURATION_ABANDONED_CARTS' => 'Abandoned Carts',
    'BOX_CUSTOMERS_ABANDONED_CARTS' => 'Abandoned Carts',

    // Customers > Abandoned Carts
    'ABANDONED_CARTS_ADMIN_HEADING' => 'Abandoned Carts',
    'ABANDONED_CARTS_ADMIN_DETAIL_HEADING' => 'Abandoned Cart #%u',
    'ABANDONED_CARTS_ADMIN_BACK' => 'Back to Abandoned Carts',
    'ABANDONED_CARTS_ADMIN_INTRO' => 'Carts are recorded once the shopper can be emailed: a customer with an account, or a guest who saved their contact details at checkout. Anonymous carts are never stored.',
    'ABANDONED_CARTS_ADMIN_OFF' => 'Reminder emails are turned off (Configuration > Abandoned Carts > Send Reminder E-Mails?). Carts are still recorded.',

    'ABANDONED_CARTS_ADMIN_SCHEDULER' => 'Scheduler',
    'ABANDONED_CARTS_ADMIN_NEVER_RAN' => 'has never run. No reminder is sent until it does.',
    'ABANDONED_CARTS_ADMIN_LAST_RAN' => 'last ran %s.',
    'ABANDONED_CARTS_ADMIN_STALE' => 'That was %s ago: check the cron job.',
    'ABANDONED_CARTS_ADMIN_BUTTON_RUN' => 'Send Due Reminders Now',
    'ABANDONED_CARTS_ADMIN_RUN_HELP' => 'Opens the scheduler in a new tab and shows what it did.',
    'ABANDONED_CARTS_ADMIN_SETUP' => 'Scheduler Setup',
    'ABANDONED_CARTS_ADMIN_SETUP_CRON' => 'Run it every 15 minutes with a cron job. In cPanel (Cron Jobs), enter Minute */15 and * for Hour, Day, Month and Weekday, with this command:',
    'ABANDONED_CARTS_ADMIN_SETUP_URL' => 'Or have an outside cron service open this address every 15 minutes:',

    'ABANDONED_CARTS_ADMIN_SUMMARY' => 'Last %u days',
    'ABANDONED_CARTS_ADMIN_RECOVERED_REVENUE' => 'Recovered by a reminder: %u order(s), %s',
    'ABANDONED_CARTS_ADMIN_SENT' => 'Reminders sent: %u',

    'ABANDONED_CARTS_ADMIN_ALL' => 'All',
    'ABANDONED_CARTS_ADMIN_SEARCH' => 'E-Mail',
    'ABANDONED_CARTS_ADMIN_BUTTON_SEARCH' => 'Search',
    'ABANDONED_CARTS_ADMIN_BUTTON_RESET' => 'Reset',
    'ABANDONED_CARTS_ADMIN_NONE' => 'No carts match.',
    'ABANDONED_CARTS_ADMIN_SHOWING' => 'Showing %u to %u of %u',
    'ABANDONED_CARTS_ADMIN_PREVIOUS' => 'Previous',
    'ABANDONED_CARTS_ADMIN_NEXT' => 'Next',

    'ABANDONED_CARTS_ADMIN_COL_ID' => 'Cart',
    'ABANDONED_CARTS_ADMIN_COL_SHOPPER' => 'Shopper',
    'ABANDONED_CARTS_ADMIN_COL_ITEMS' => 'Items',
    'ABANDONED_CARTS_ADMIN_COL_TOTAL' => 'Cart Total',
    'ABANDONED_CARTS_ADMIN_COL_ACTIVITY' => 'Last Activity',
    'ABANDONED_CARTS_ADMIN_COL_STATUS' => 'Status',
    'ABANDONED_CARTS_ADMIN_COL_REMINDERS' => 'Reminders',
    'ABANDONED_CARTS_ADMIN_COL_ORDER' => 'Order',
    'ABANDONED_CARTS_ADMIN_COL_DATE' => 'Date',
    'ABANDONED_CARTS_ADMIN_COL_EVENT' => 'Event',
    'ABANDONED_CARTS_ADMIN_COL_DETAIL' => 'Detail',
    'ABANDONED_CARTS_ADMIN_BUTTON_DETAILS' => 'Details',
    'ABANDONED_CARTS_ADMIN_GUEST' => 'Guest',
    'ABANDONED_CARTS_ADMIN_NEXT_SEND' => 'Next reminder',
    'ABANDONED_CARTS_ADMIN_NO_MORE' => 'No more reminders for this cart.',

    'ABANDONED_CARTS_ADMIN_STATUS_OPEN' => 'Open',
    'ABANDONED_CARTS_ADMIN_STATUS_RECOVERED' => 'Recovered',
    'ABANDONED_CARTS_ADMIN_STATUS_ORDERED' => 'Ordered',
    'ABANDONED_CARTS_ADMIN_STATUS_EMPTIED' => 'Emptied',
    'ABANDONED_CARTS_ADMIN_STATUS_MERGED' => 'Merged',
    'ABANDONED_CARTS_ADMIN_STATUS_EXCLUDED' => 'Not Emailed',
    'ABANDONED_CARTS_ADMIN_STATUS_EXPIRED' => 'Too Old',
    'ABANDONED_CARTS_ADMIN_STATUS_UNSUBSCRIBED' => 'Unsubscribed',

    'ABANDONED_CARTS_ADMIN_EVENT_TRACKED' => 'Recorded',
    'ABANDONED_CARTS_ADMIN_EVENT_LOGGED_IN' => 'Shopper logged in',
    'ABANDONED_CARTS_ADMIN_EVENT_EMAILED' => 'Reminder sent',
    'ABANDONED_CARTS_ADMIN_EVENT_SEND_FAILED' => 'Reminder could not be sent',
    'ABANDONED_CARTS_ADMIN_EVENT_SEND_UNCONFIRMED' => 'Reminder counted as sent',
    'ABANDONED_CARTS_ADMIN_EVENT_CLICKED' => 'Return to Your Cart clicked',
    'ABANDONED_CARTS_ADMIN_EVENT_UNSUBSCRIBE' => 'Unsubscribe link used',
    'ABANDONED_CARTS_ADMIN_EVENT_RECOVERED' => 'Ordered after a reminder',
    'ABANDONED_CARTS_ADMIN_EVENT_ORDERED' => 'Ordered',
    'ABANDONED_CARTS_ADMIN_EVENT_EMPTIED' => 'Cart emptied',
    'ABANDONED_CARTS_ADMIN_EVENT_EXCLUDED' => 'Not emailed',
    'ABANDONED_CARTS_ADMIN_EVENT_EXPIRED' => 'Too old to remind',
    'ABANDONED_CARTS_ADMIN_EVENT_UNSUBSCRIBED' => 'Address is unsubscribed',

    'ABANDONED_CARTS_ADMIN_BUTTON_STOP' => 'Stop Reminders for This Cart',
    'ABANDONED_CARTS_ADMIN_STOPPED' => 'No more reminders will be sent for cart #%u.',
    'ABANDONED_CARTS_ADMIN_ERR_NOT_FOUND' => 'That cart record no longer exists.',
];

return $define;
