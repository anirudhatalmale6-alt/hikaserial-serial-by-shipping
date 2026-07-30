# HikaSerial – Serials by Shipping Method

A small Joomla plugin (HikaSerial group) that generates and delivers the HikaSerial
serial/ticket **only** for orders whose customer chose one of the allowed shipping
methods (e.g. your "E-Mails" dispatch types). Orders that used any other shipping
method (e.g. delivery by post) get **no serial at all** — it is not generated, not
shown in the customer's front-end order view, and not e-mailed.

Built and verified against: **Joomla 5/6 · HikaShop 6.5.2 · HikaSerial 6.1.1 · PHP 8.4**

## How it works

The plugin gates HikaSerial at two levels:

**1. Generation (primary).** HikaSerial decides whether to assign serials when an
order reaches its assignable status, from `class.order::preUpdate()` →
`postUpdate()`. The plugin hooks `onSerialOrderPreUpdate` and, for orders whose
shipping method is **not** in your allowed list, suppresses the assignment. No
serial is created, so nothing appears in the customer's account/front-end or in the
order e-mail — exactly what you want for "normal" postal orders.

**2. Delivery (defence-in-depth).** In case a serial exists for any other reason,
the official generator rule hooks also skip attaching the ticket:
- `onCustomPdfSerialRule($serial, $params, &$do)` — PDF tickets
- `onCustomAttachSerialRule($serial, $params, &$do)` — image/QR tickets
- `onBeforeSerialMailSend` — strips any inline serials from the order e-mail.

For allowed (E-Mails) orders the plugin does nothing at all — HikaSerial generates,
displays and e-mails the ticket exactly as it does today, with your ticket layout
and default e-mail template untouched. **No HikaSerial settings need to be changed.**

## The two plugins

The solution ships as **two** small plugins that work together:

| ZIP | Group | What it does |
| --- | --- | --- |
| `plg_hikaserial_serialbyshipping.zip` | HikaSerial | Gates serial **generation + delivery** — non-allowed orders get no serial at all. This is where you configure the allowed shipping list. |
| `plg_hikashop_filesbyshipping.zip` | HikaShop | Hides the product **download button** ("Deine Reservierung") in the order e-mail, the front-end order page and the back-end order view for non-allowed orders. Inherits the shipping list from the plugin above. |

Install both. You only configure the shipping list **once** (on the HikaSerial plugin).

## Install

1. Joomla admin → **System → Install → Extensions** → upload
   `plg_hikaserial_serialbyshipping.zip`, then upload
   `plg_hikashop_filesbyshipping.zip`.
2. **System → Manage → Plugins** → **enable** both:
   - "Serials by Shipping - HikaSerial plugin"
   - "Hide download files by shipping - HikaShop plugin"
3. Open **"Serials by Shipping - HikaSerial plugin"** and set:
   - **Allowed shipping method(s)** — now shown with their real names, e.g.
     *Ticket(s) per E-Mail (#1)*. Pre-filled with 1, 4, 8, 17, 20, 26. Multi-select.
   - **When an order has no shipping method** — default "Do not send the ticket".
   - **Debug logging** — ON while testing, OFF in production.
4. The HikaShop plugin needs no configuration — leave its "Allowed shipping
   method(s)" empty and it uses the same list as the HikaSerial plugin. (Fill it
   only if you ever want the download-button rule to differ from the serial rule.)

That's it. No changes to HikaShop or HikaSerial configuration are required.

## Testing

1. Turn **Debug logging** ON on both plugins.
2. Place a test order using an **allowed** shipping method and mark it Paid →
   a serial is generated, shown in the customer's order/account, the PDF ticket
   is in the e-mail as usual, and the download button is present.
3. Place a test order using a **non-allowed** shipping method and mark it Paid →
   **no serial** is generated, nothing is shown in the front-end, the e-mail
   arrives with no ticket, **and no download button appears** in the e-mail, the
   front-end order page or the back-end order view.
4. Check the logs — each decision is logged with the order id:
   - `administrator/logs/plg_hikaserial_serialbyshipping.php` (generation/delivery)
   - `administrator/logs/plg_hikashop_filesbyshipping.php` (download buttons)

## Notes / scope

- Primary behaviour is the **generation gate**: non-allowed orders never get a
  serial, so nothing leaks into the front-end. The delivery hooks are a safety net.
- The download-button gate hooks HikaShop's `onAfterLoadFullOrder`, the single
  full-order loader shared by the e-mail, front-end and back-end order views, so a
  non-allowed order shows no download button in any of them.
- Shipping method names in the settings are resolved through HikaShop's own
  shipping class (custom form field), so you see the real names, not just IDs.
