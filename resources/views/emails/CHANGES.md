# Email templates: changes for the backend

Drop-in replacements. Every variable the mailables pass today is still used the same way; nothing new is required.

## Where the files go (`resources/views/emails/`)

| File | Action |
|---|---|
| `layouts/brand.blade.php` | Replace |
| `partials/button.blade.php` | **New**: the one button used by every email |
| `otp.blade.php` | Replace (it came to us as `otp.blade (2).php`; use the existing file name) |
| `cancellation_request_decided.blade.php` | Replace |
| `order_confirmed.blade.php` | Replace |
| `order_shipped.blade.php` | Replace |
| `contact_reply.blade.php` | Replace |
| `reset.blade.php` | Replace (see question 4) |

`otp` and `cancellation_request_decided` used to be standalone pages. They now extend the shared layout like the others, so all emails share one header, button and footer.

## What changed

- **Header subtitle centred.** It was a `max-width: 400px` paragraph with no auto margins, so it sat at the left of the header. It now has `margin: 0 auto` and `align="center"`. This was the visible bug in the code email.
- **Verification code** shows in one box, so it can be selected and copied in one go. Before, it was 6 separate table cells, and copying gave digits split by tabs and new lines. The wording is simpler, and the "Check your inbox" line is removed.
- **Readable text colours.** The site's AA colours replace `#9CA3AF` and gold text on white, which were about 2.5:1 and 2:1: slate `#5b6170` and gold ink `#7a5f12`, the same tokens as the site.
- **Inbox preview line.** Each email sets `@section('preheader')`. The layout also supports the optional sections `subheading` and `footer_note`. Existing templates that use only `title`, `heading` and `content` keep working.
- **Footer** always shows the support email and the site link. It reads `mail.brand.support_email` and falls back to `otantikqueen@gmail.com`.
- `color-scheme: light` meta tags make dark mode (Apple Mail, Outlook) less likely to invert the colours.
- **Cancellation email.**
  - The title and heading now agree ("approved" / "declined"). Before, the title said "Accepted".
  - The red/green badges are gone.
  - The declined text no longer gives a reason.
  - The fallback URL is `https://otantikqueen.com` instead of `http://localhost:8000`.
- **Order confirmed:** the cancel deadline is shown in the shop's time zone, set by `mail.brand.timezone` and defaulting to `America/Los_Angeles`. Before, it showed in server time (UTC).
- **Order shipped:**
  - greeting, carrier, tracking number and delivery date in one box;
  - "GroundAdvantage" is shown as "Ground Advantage";
  - a "View your order" link, plus the "Track your shipment" button when `tracking_url` exists.
- Order numbers are shown without a `#`, the same as on the site.

## Please check (questions for the backend)

1. **Cancellation "View your order" link.** It goes to `/orders/{order_number}`, but the site's order pages are `/orders/{id}`, for example `/orders/63`. The template now uses `$orderUrl` when the mailable passes it. Please pass `$orderUrl = frontend_url.'/orders/'.$order->id`, as `order_confirmed` already does.
2. **Order shipped link** is built from `$order->id` (`/orders/{id}`). Please confirm that is the order's primary key.
3. **Order shipped tracking fields.** The template reads `tracking_number`, `shipping_carrier`, `shipping_service` and `tracking_url` from the order. Since manual shipping now also creates a shipment record (retest LBL-5), please check that these fields are still filled on the order in both cases: a label bought here, and a carrier entered by hand.
4. **`reset.blade.php`** sends a reset *link*. The site's password reset now uses the 6-digit code email (`otp`, purpose `password_reset`), and the frontend removes the token from the address bar. If nothing sends this template any more, it can be deleted.

## Test before release

Send each email to a test inbox and open it in Gmail (web and phone app), Outlook and Apple Mail. Check the code email, an approved and a declined cancellation, order confirmed, and both kinds of shipped email.
