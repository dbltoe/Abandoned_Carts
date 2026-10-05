# Customizing Abandoned Carts

## Wording

Every word of the email and the storefront pages is in
`zc_plugins/AbandonedCarts/v1.0.2/catalog/includes/languages/english/extra_definitions/lang.abandoned_carts.php`.
Copy a constant into an override language file to change it. The admin text is in the same path under `admin/`.

The reminder's subject and opening line can differ per reminder: define `ABANDONED_CARTS_EMAIL_SUBJECT_2`, `ABANDONED_CARTS_EMAIL_INTRO_2` (and `_3`). Abandoned Carts Pro defines them.

## The email's look

It's sent through `zen_mail()` with the module name `abandoned_carts`, so it uses your store's email template. With Preview Email installed it's under Tools > Preview Email > Abandoned Carts; with Preview Email Pro you can edit the template.

## The unsubscribe page

Copy `catalog/includes/templates/default/templates/tpl_abandoned_cart_default.php` into your template's `templates/` folder. It uses `centerColumn`, `buttonRow` and the stock button classes.

## Pages and links

- `main_page=abandoned_cart&cart=<id>&t=<token>`: Return to Your Cart.
- `main_page=abandoned_cart&cart=<id>&u=<token>`: unsubscribe (GET shows a confirmation; a POST with `List-Unsubscribe=One-Click` unsubscribes at once).
- `main_page=abandoned_carts_cron&key=<Scheduler Key>`: the scheduler.

Tokens are HMACs of the cart id under the Link Secret.

## Notifiers this plugin fires

| Notifier | Where | Parameters |
|---|---|---|
| `NOTIFY_ABANDONED_CARTS_BEFORE_COMPOSE` | scheduler, before each reminder is composed | p1 `['cart_id', 'step', 'row']`, p2 `&$cart` (add `coupon` => `['code', 'offer', 'expires']`) |
| `NOTIFY_ABANDONED_CARTS_SCHEDULER_END` | scheduler, after the run | p2 `&$report` (append lines to `$report['extra']`) |

The number of reminders per cart is `ABANDONED_CARTS_PRO_STEPS` (1-3), honored only while `ABANDONED_CARTS_PRO_LOADED` is defined (Abandoned Carts Pro's extra_datafiles), so a disabled add-on can't leave stores sending extra reminders.

## Session

The plugin keeps its own key in `$_SESSION['abandoned_carts']`. It never keys a cart by `zen_session_id()` (Zen Cart regenerates it at login) or by One Page Checkout's shared guest `customers_id`.
