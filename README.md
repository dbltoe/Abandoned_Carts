# Abandoned Carts

Version 1.0.4 · Zen Cart 1.5.8 through 3.0.0 · PHP 7.4 through 8.5 · GPL v2 · by My Zen Cart Host (dbltoe)

Emails shoppers who left items in their cart, automatically, with a link that puts the cart back exactly as they left it.

## What it does

About seven in ten online carts are left behind. Abandoned Carts emails the shopper a while after they stop, with what they left, the total and a **Return to Your Cart** button that puts the cart back exactly as it was, options and all.

- It runs by itself. Once the scheduler is set up there's nothing to send by hand.
- It reaches customers with an account *and* One Page Checkout guests who gave their email at checkout, the shoppers older tools can't see.
- It stops the moment the shopper orders, empties the cart or unsubscribes, and it waits while they're still clicking around.
- Every email says it's an advertisement, has a one-click unsubscribe link (and the header mail providers use for their own Unsubscribe button) and your store's postal address.
- For stores that need consent first, it can ask One Page Checkout guests with an "Email me a reminder" checkbox, and remind only those who tick it.
- Customers > Abandoned Carts shows every recorded cart, what was sent, and the orders reminders brought back.
- No template edits, no core edits: it installs and uninstalls from Plugin Manager.

Carts are recorded only once the shopper can be emailed, and only when your settings allow emailing them. A visitor who never logs in or gives an email at checkout isn't stored anywhere, and neither is a shopper your settings rule out.

## Requirements

- Zen Cart 1.5.8 through 3.0.0, PHP 7.4 through 8.5.
- Email sending working in your store (Configuration > E-Mail Options > Send E-Mails is true).
- A way to open the scheduler address every 15 minutes: a cron job (cPanel and most hosts have one) or an outside cron service.
- Optional: lat9's One Page Checkout 2.3.6 or later, for guests. Without it, reminders go to customers with an account.

## Installing

1. Upload the `zc_plugins/AbandonedCarts` folder from this package into your store's `zc_plugins` folder, so you have `zc_plugins/AbandonedCarts/v1.0.4/manifest.php`.
2. In the admin, open **Modules > Plugin Manager**, select **Abandoned Carts** and click **Install**.
3. Set up the scheduler (next section). Nothing is sent until it runs.
4. Look over **Configuration > Abandoned Carts**. The defaults are a good start.

Recording starts at once: carts left from now on are picked up.

Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin Manager.

## Setting up the scheduler

The scheduler is a storefront address with a secret key. Each time it's opened it sends the reminders that are due. Open it every 15 minutes, so a reminder set for one hour goes out close to on time.

Your address is shown under **Configuration > Abandoned Carts > Scheduler Key**, and on Customers > Abandoned Carts under **Scheduler Setup**. It looks like this:

```
https://www.example.com/index.php?main_page=abandoned_carts_cron&key=YOUR-KEY
```

### cPanel

Open **Cron Jobs**, enter Minute `*/15` and `*` for Hour, Day, Month and Weekday, and use this command with your address:

```
curl -fsSL "https://www.example.com/index.php?main_page=abandoned_carts_cron&key=YOUR-KEY" >/dev/null
```

### No cron on your host?

Any outside cron service that can open a web address every 15 minutes will do.

### Checking it

Click **Send Due Reminders Now** on Customers > Abandoned Carts. A new tab shows what the run did. The page also shows when the scheduler last ran, and warns when it hasn't run for an hour.

> Keep the key private. Anyone with the address can make the scheduler run (it only ever sends reminders that are due, but still).

## Configuration

Under **Configuration > Abandoned Carts**:

| Setting | Default | What it does |
| --- | --- | --- |
| Send Reminder E-Mails? | true | Turns the emails on or off. Carts are still recorded while it's off, so nothing is missed when you turn it back on. |
| Reminder E-Mail, Hours After Last Activity | 1 | How long after the shopper last changed their cart the reminder goes out. 1 to 168. |
| Don't Remind About Carts Older Than (Days) | 7 | Carts whose last activity is older than this are never emailed. Stops a stalled scheduler (or turning reminders on) from emailing about stale carts. |
| Minimum Cart Value | 0 | Carts worth less are not emailed. In your default currency, with tax when your prices show tax. |
| E-Mail Guests? | true | Whether One Page Checkout guests who saved their contact details are emailed. When false, nothing about a guest is kept. |
| E-Mail Only Newsletter Subscribers? | false | When true, only customers who opted in to your newsletter are emailed, and nothing is kept about anyone else. A guest can't subscribe, so a guest is reminded only after ticking the box *Ask Guests Before Reminding?* adds. See Privacy and the law. |
| Ask Guests Before Reminding? | false | When true, One Page Checkout shows a guest an "Email me a reminder if I leave items in my cart." checkbox with their contact details, never ticked for them. Only a guest who ticks it and saves is recorded and reminded. See One Page Checkout guests. |
| Wait While the Shopper Is Still Browsing (Minutes) | 20 | A shopper Who's Online shows clicking in the last this-many minutes isn't emailed yet. 0 turns the check off. |
| Keep Cart Records (Days) | 30 | Cart records, guest emails included, are deleted this many days after the cart was last active. 7 to 365. |
| Delete Cart Records on Uninstall? | false | When false, uninstalling keeps the records, the unsubscribe list, the scheduler key and the link secret. |
| Scheduler Key | generated | The secret in the scheduler address. Read-only. |
| Link Secret | generated | Signs the links in the emails. Read-only; changing it would break every link already sent. |
| Scheduler Last Ran | (set by the scheduler) | Read-only. |

## Who gets a reminder

A cart is recorded when the shopper changes it while the plugin knows their email (a logged-in customer, or a guest who saved their details at checkout) and your settings allow emailing them. Each change pushes the reminder back, so nobody is emailed while they're still shopping.

When the reminder is due, the scheduler checks, in this order, and sends only if every check passes:

1. The cart isn't older than *Don't Remind About Carts Older Than*.
2. The address isn't on the unsubscribe list.
3. No order has come in from that address since the cart was recorded. This catches every way an order can arrive, PayPal's IPN included.
4. The settings allow emailing this shopper: guests, the guest's checkbox, newsletter-only. If they don't (you changed a setting since the cart was recorded), the record is deleted.
5. The cart is worth at least the *Minimum Cart Value*.
6. The shopper isn't still clicking around (Who's Online). If they are, it waits and tries again.

One reminder per cart. A cart closes when the shopper orders (shown as *Recovered* if a reminder had gone out, else *Ordered*), empties it, or unsubscribes. A customer who comes back in a new session keeps one cart record, not one per visit.

## One Page Checkout guests

With One Page Checkout's guest checkout, a guest's cart is never saved to your database the way a customer's is, and their email exists only after they fill in the contact block and click **Save**. That moment is when Abandoned Carts records them. A guest who types an email and leaves without clicking Save can't be reached by any plugin, because the address never reaches your server.

If a guest logs in to an account partway through checkout, the cart moves to their account record.

### Asking guests first

With *Ask Guests Before Reminding?* true, the contact block shows a checkbox just above its Save button: **Email me a reminder if I leave items in my cart.** It's never ticked for the guest. Only a guest who ticks it and clicks Save is recorded. A guest who later unticks it and saves again is forgotten: their cart record and its history are deleted. The box is added by a short script, so the template needs no edits; if the script doesn't run, the box isn't there and the guest isn't recorded. Change its wording with `ABANDONED_CARTS_OPT_IN_LABEL`.

This works with One Page Checkout 2.3.6 and later, including 2.7.0. Nothing in One Page Checkout is changed.

## The Return to Your Cart link

The button opens your store and puts the cart back as it was: the same products, quantities and options, text options and checkboxes included. Items already in the shopper's current cart are kept as they are. Products you've since disabled or deleted are left out, and the shopper is told how many. Prices are always today's, never the ones in the email.

A logged-in customer's restored cart is saved to their account. Nobody is ever logged in by the link.

## Unsubscribing

Every email has an unsubscribe link. It opens a page that asks for confirmation (the address is shown partly masked), and one click unsubscribes. The email also carries the `List-Unsubscribe` and one-click headers (RFC 8058), so Gmail, Yahoo and others show their own Unsubscribe button, which unsubscribes straight away.

The unsubscribe list holds a one-way hash of each address, never the address itself, and only stops cart reminders. Order confirmations and your other emails aren't affected.

## Customers > Abandoned Carts

- The scheduler's health and the **Send Due Reminders Now** button.
- The last 30 days: reminders sent, carts recovered and what those orders were worth.
- Every recorded cart, with counts by status, an email search, and pages of 50.
- Each cart's items, reminders and history (recorded, reminder sent, link clicked, ordered...), with **Stop Reminders for This Cart** while it's open.

## The email

The reminder lists the items with their options and prices, the cart total, the **Return to Your Cart** button, a line saying it's an advertisement from your store, an unsubscribe link and your store's name and address (Configuration > My Store > Store Address and Phone). It's sent through Zen Cart's normal email, so it uses your email template, is archived when email archiving is on, and follows your email settings.

Guests have no email preference in your store, so their reminder is sent as HTML. A customer who chose text-only email gets text.

With **Preview Email** installed, the reminder appears under Tools > Preview Email as *Abandoned Carts: Cart Reminder*, built by the same code that sends it.

## Privacy and the law

We don't give legal advice; here's what the plugin does so you can decide.

- **United States (CAN-SPAM):** a cart reminder is a commercial email. Every reminder says it's an advertisement from your store, has a working unsubscribe, and carries your postal address. Make sure Store Address and Phone is filled in.
- **EU and UK (GDPR, PECR), Canada (CASL), Australia (Spam Act 2003):** these need consent before marketing email, and a cart someone didn't finish isn't consent. Set *E-Mail Only Newsletter Subscribers?* true and *Ask Guests Before Reminding?* true, so only newsletter subscribers and guests who tick the box are reminded (or *E-Mail Guests?* false to leave guests out altogether). Keep *Keep Cart Records* short, and say in your privacy notice that you remind shoppers who ask.
- Nothing is kept about a shopper your settings say can't be emailed, guest emails are kept only as long as *Keep Cart Records* says, and the unsubscribe list never holds an address.

## Recover Cart Sales

Abandoned Carts can run beside the older Recover Cart Sales module. Recover Cart Sales sends by hand, to customers with an account; Abandoned Carts sends automatically and reaches guests too. If you use both, be careful not to email the same customer from each.

## Abandoned Carts Pro

The paid add-on adds a series of up to three reminders on your own timing (for example 1 hour, 1 day and 3 days), a single-use coupon in the reminder you choose, and a recovered-revenue report. It needs this plugin installed.

## Customizing

- **Wording:** every word of the email and pages is in `catalog/includes/languages/english/extra_definitions/lang.abandoned_carts.php`. Copy a constant into your own override language file to change it.
- **The unsubscribe page:** copy `tpl_abandoned_cart_default.php` into your template's `templates` folder.
- **The email's look:** it uses your store's email template (`email/email_template_default.html`), or Preview Email Pro if you have it.
- **Your own code:** the notifiers the plugin fires, and what Abandoned Carts Pro hooks into, are listed in `docs/CUSTOMIZING.md` in the download.

## Upgrading

Upload the new version's folder beside the old one and click **Upgrade** in Plugin Manager. Your settings, records, scheduler key and link secret are kept.

From 1.0.0 or 1.0.1 to 1.0.2: the new setting *Ask Guests Before Reminding?* arrives off, so nothing changes until you turn it on. Records your settings already rule out (a guest with *E-Mail Guests?* false, for example) are deleted the next time the scheduler runs, where 1.0.1 kept them, marked Not Emailed, until *Keep Cart Records* ran out.

From 1.0.2 to 1.0.3, only the plugin's panel in Plugin Manager changes. From 1.0.3 to 1.0.4, this guide replaces the old HTML readme: the ReadMe button in Plugin Manager now opens it inside the admin, for superusers, in its own window. Nothing changes on the storefront, in the emails or in the settings.

## Uninstalling

Click **Uninstall** in Plugin Manager. With *Delete Cart Records on Uninstall?* false (the default), the records, the unsubscribe list, the scheduler key and the link secret are kept, so a later install picks up where it left off and links in emails already sent still work. With it true, all of it is removed. Remove the cron job too.

## Troubleshooting

| What you see | What to check |
| --- | --- |
| No reminders go out | Customers > Abandoned Carts shows when the scheduler last ran. Click **Send Due Reminders Now** and read the report. Check Send Reminder E-Mails? and Send E-Mails. |
| The scheduler says Forbidden | The key in your cron job doesn't match the Scheduler Key. Copy the address again from Scheduler Setup. |
| A cart shows *Not Emailed* | Its history says why: under the minimum value, or stopped by an admin. (A shopper your settings rule out isn't kept at all.) |
| Guests are never recorded | Guest checkout must be One Page Checkout's, and the guest must click Save on their contact details. Check *E-Mail Guests?*; with *Ask Guests Before Reminding?* true, only guests who tick the box are recorded. |
| "could not be sent" in the report | Zen Cart couldn't send the email. Check your email settings; the reminder is retried an hour later. |

## Support

Support is through the Abandoned Carts support thread on the Zen Cart forum (https://www.zen-cart.com/threads/207392), which is also linked from the plugin's Plugin Manager panel, and myzencartzone.com (https://myzencartzone.com). The source, releases and issue tracker are on GitHub (https://github.com/dbltoe/Abandoned_Carts). The download's `docs` folder has more on installing, every setting, customizing and compatibility.

## Changelog

See `CHANGELOG.md`. Version 1.0.4 replaces the old HTML readme with this guide, opened from the ReadMe button in Plugin Manager.

## License

Abandoned Carts is free software under the GNU General Public License, version 2 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html); the full text is in `LICENSE`. Copyright © 2026 My Zen Cart Host (dbltoe).
