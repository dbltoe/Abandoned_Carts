# Installing Abandoned Carts

## Requirements

- Zen Cart 1.5.8 through 3.0.0, PHP 7.4 through 8.5.
- Email sending working (Configuration > E-Mail Options > Send E-Mails is true).
- A cron job, or an outside cron service, that can open a web address every 15 minutes.
- Optional: lat9's One Page Checkout 2.3.6 or later, for guest reminders.

## Install

1. Upload `zc_plugins/AbandonedCarts` into your store's `zc_plugins` folder.
2. Modules > Plugin Manager > Abandoned Carts > **Install**.
3. Set up the scheduler (below). Nothing is sent until it runs.
4. Look over Configuration > Abandoned Carts, and make sure Configuration > My Store > Store Address and Phone is filled in: it goes on every reminder.

Carts are recorded from the moment the plugin is installed.

## The scheduler

The scheduler is a storefront address with a secret key:

    https://www.example.com/index.php?main_page=abandoned_carts_cron&key=YOUR-KEY

Your address is under Configuration > Abandoned Carts > Scheduler Key, and on Customers > Abandoned Carts under Scheduler Setup. Open it every 15 minutes.

**cPanel > Cron Jobs:** Minute `*/15`, and `*` for Hour, Day, Month and Weekday, with:

    curl -fsSL "https://www.example.com/index.php?main_page=abandoned_carts_cron&key=YOUR-KEY" >/dev/null

A wrong or missing key gets a bare 403. **Send Due Reminders Now** on Customers > Abandoned Carts runs it once in a new tab and shows the report. That page warns when the scheduler hasn't run for an hour.

## Upgrading

Upload the new version's folder beside the old one (`zc_plugins/AbandonedCarts/vX.Y.Z`) and click **Upgrade** in Plugin Manager. Settings, records, the scheduler key and the link secret are kept.

## Uninstalling

Modules > Plugin Manager > Abandoned Carts > **Uninstall**, then remove the cron job.

- With **Delete Cart Records on Uninstall?** false (the default), the cart records, the unsubscribe list, the scheduler key and the link secret are kept. A later install picks up where it left off, and links in emails already sent keep working.
- With it true, all of it is removed.

If Abandoned Carts Pro is installed, uninstall it first.
