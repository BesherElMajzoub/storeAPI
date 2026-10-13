Otantik Queen · status on 13 Oct 2026

# What's left before the client tests, and before go-live

Everything still open, frontend and backend, in the order it should be done. Stage 1 is the short list that stands between today and the client's hands-on test. Stage 2 is what has to happen before real customers pay, including the move from the Hostinger test server to the new Contabo production server.

**49**cases passed live

**2**failed (host check, now cleared)

**68**not run yet (regression + phone checks)

**894**frontend tests passing

Who acts: Frontend Backend Owner Server Size: S under 1 h · M half a day · L a day or more

Stage 1

## Before handing the site to the client

Small fixes and decisions. None needs a new server. Most can be done today.

Frontend

Update 13 Oct: F2–F5 are fixed and tested on the branch (`ca6f179`, `6e8ccf5`, 899 tests passing). They go live with F1 in one push. F3 still needs the owner's real business hours and, optionally, a WhatsApp number.

1. F1

   **Push the free-shipping coupon support for the admin**

   Ready on the branch (`23fe4af`), not live. Until it is pushed, the admin lists QA-FREESHIP as "0% off", and saving that coupon would turn it into a percentage coupon.

   FrontendS · push only
2. F2

   **FAQ answer on cancelling contradicts the policy**

   FAQ #16 says "cancel within 2 hours by contacting customer service". The Returns page and the order screens say "cancel it yourself within 3 hours of payment from My Orders, then send a request until it ships". Rewrite the FAQ answer in English and Arabic.

   FrontendS
3. F3

   **Help page offers a "Live Chat" that does not exist**

   Remove the card, or turn it into WhatsApp once the owner gives the number. The same page says "Mon–Fri 9 AM–6 PM EST" for a Los Angeles business; the owner confirms the real hours and time zone.

   FrontendOwnerS
4. F4

   **Admin order shows "Refunded" and "Refund failed" side by side**

   Seen today on ORD-PEQ6SARTFB. The cause is B2 below; the frontend can also show the refund failure in place of "Refunded" so the admin is never misled.

   FrontendS
5. F5

   **Status menu offers moves the server refuses**

   On a cancelled order, the admin status menu still lists Pending, Processing, Shipped and Delivered. The server rejects them; the menu should not offer them.

   FrontendS

Backend

1. B1

   **Pin Express to UPS 2nd Day Air**

   Owner decision of 12 Oct. Live Express is currently UPS Next Day Air Saver ($27.76 on the test address). Message drafted; confirm it was sent.

   BackendS
2. B2

   **A failed refund still reads `payment_status: refunded`**

   When Stripe reported the refund on ORD-PEQ6SARTFB as failed, the refund block was correct (`failed`, `expired_or_canceled_card`), but the payment status stayed "refunded". It should say the money was not returned.

   BackendS
3. B3

   **Question: was the retried refund's failure recorded?**

   The first refund failed by webhook after about 2 minutes. After Retry, the new refund still read "succeeded" 9 minutes later. Please check in Stripe whether it failed, and whether the webhook updates the newest refund on the order.

   BackendS
4. B4

   **Catalogue for the client's test**

   All 32 public categories and about 82 of 85 products are `demo-*`. QA products sit among them: "تجريب 3" (19 test orders), "تجريب 2", and a draft named "Retest ADM-PROD-1 (draft, delete me)". The owner decides: test on demo data, or load part of the real catalogue first.

   OwnerBackendM

Owner checks (need a phone, an inbox or a printer)

1. O1

   **Phone and inbox checks from the rev. 5 sheet**
   - SMK-MOB: place one order on the phone. UI-BTN-1: screen-record the Cancel button on the iPhone.
   - B9-1: emails for orders 66–69 (no "#" in subjects; carrier and number on the hand-shipped email; "View order" links).
   - LBL-2: print the order 66 label. B8-1: upload 5 portrait phone photos.
   - SMK-REG and SMK-CPN: register a new account, then pay with a coupon.

   OwnerM
2. O2

   **The other 60 or so regression rows**

   These re-check older features (checkout, account, catalogue, Arabic pages). They can be the client's own test script, or the owner runs them first.

   OwnerL

Hostinger's browser check (H1-1, PERF-01b) no longer blocks anyone: normal browsers, Google and link previews all get the real page, and the owner's phone loads the site. Only automated test browsers still see the check for a second. It goes away entirely on the new server.

Stage 2

## Before go-live on the Contabo server

Hostinger did several jobs for us without a setting anyone had to remember. On a bare server, each one has to be set up on purpose. Items marked "breaks payments" lose money or orders if missed.

Server: website (frontend)

1. S1

   **Port the web server rules from `public/.htaccess`**
   - Page routing: serve prerendered `/path/index.html`, else fall back to `index.html`.
   - `www` → bare domain 301 (without it the API refuses the browser, as on 11 Oct).
   - Caching: hashed assets one year, HTML no-cache; gzip or brotli.
   - Security headers: HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP.

   If the server runs nginx, the frontend writes the equivalent config block.

   ServerFrontendM
2. S2

   **New deploy pipeline**

   `.github/workflows/deploy.yml` uploads by FTP to Hostinger. Switch it to SSH/rsync to Contabo, with new GitHub secrets. Confirm the Sentry secrets exist.

   FrontendServerM
3. S3

   **HTTPS certificates and DNS cut-over**

   Certificates for `otantikqueen.com`, `www` and `apis.otantikqueen.com`, with auto-renewal. Lower the DNS TTL a day ahead. If the API address changes, the frontend build and the CSP change with it.

   ServerS

Server: API (backend)

1. S4

   **Scheduler and queue workers** · breaks payments

   The cron that captures card holds every few minutes must run, or authorised payments expire uncaptured. The media worker (photo sizes) and mail run under a process manager such as Supervisor.

   BackendServerM
2. S5

   **Webhooks and sign-in pointed at the new server** · breaks payments

   Stripe webhook endpoint and its new signing secret (payments, `refund.failed`, `charge.refund.updated`). EasyPost tracking webhook. Google sign-in authorised origins and redirect URIs. API CORS origins and `APP_URL`.

   BackendS
3. S6

   **Data, files and email**

   Move the database and the uploaded media. Set up sending mail (SPF, DKIM and DMARC on the domain, or customers' order emails land in spam). Nightly backups of database and media, stored off the server.

   BackendServerM
4. S7

   **Protection Hostinger gave for free**

   Firewall (only 22, 80, 443 open), SSH keys only, automatic security updates, rate limits on login and checkout, uptime monitoring. Optional: Cloudflare in front for DDoS protection.

   ServerM

Launch switches

1. L1

   **Cancel window back to 3 hours**

   It is 10 minutes on the test server so cancellations can be tested quickly. Every customer text says 3 hours.

   BackendS
2. L2

   **Stripe and EasyPost to live keys** · breaks payments

   Both are in test mode. Switch on the production server only, and place one real low-value order end to end, refunded after.

   BackendOwnerS
3. L3

   **Real catalogue in, test data out**

   Delete the demo categories and products, the QA products, the test orders, test coupons (QA-FREESHIP) and test accounts. Then rebuild the site so the sitemap lists the real products.

   OwnerBackendFrontendM
4. L4

   **Product availability flag**

   PDP-03 (an out-of-stock size can't be picked) waits on the admin "V2" flag being turned on.

   BackendS

Stage 3

## Agreed for after launch

Not needed for the client's test or for go-live. Listed so nothing is forgotten.

1. P1

   **Admin comfort**

   Orders table layout and a phone-friendly admin menu; a save bar that stays in view; filters by name instead of ID.

   FrontendL
2. P2

   **Code health**

   Split the largest files, stricter checks at the API boundary, an end-to-end smoke test in the pipeline, one sign-in check call at start-up.

   FrontendL
3. P3

   **Product decisions for later**

   Guest checkout (today customers need an account), HEIC photo uploads from iPhones, and a list of what address verification changed beyond the ZIP code.

   OwnerBackendM

Sources: live retest sheet Otantik_Queen_Retest_2026-10_rev5.xlsx, backend reply of 12 Oct, live checks on 13 Oct (API b1a3133, frontend a18886a).