# Briox Integration Plan

Sync new B2B customers from the WooCommerce site to Briox (invoicing system).

**How to use this file:** This file lives in the `dyc-b2b-registration` plugin folder (versioned in its git repo). Check off steps as they are completed and add a line to the [Session log](#session-log). To resume in a new session, ask Claude to read this file and continue from the first unchecked step.

**Status (2026-10-14):** v1.5.0 adds the "E-Faktura" checkbox (`billing_einvoice` → Briox `invoicedocumentdeliverymethod` `02`, falling back to `00` when e-invoicing isn't enabled) on branch `einvoice`. Previous status:  Phase 4 done; v1.4.0 (settings page + automatic billing fields) built on branch `briox-settings`. Next: Phase 5 (go live, user) (last updated 2026-10-11)

---

## Context for a new session

- **Site:** Local WordPress in Docker, run from `/home/allan/wordpress-docker` (containers `wordpress_app` and `wordpress_db`, site at http://localhost:8000).
- **Registration plugin:** `wordpress/wp-content/plugins/dyc-b2b-registration/`. It has its own git repo; see its README. It creates the `business` user role and stores:
  - user meta `billing_company`, `billing_orgnr` (format `NNNNNN-NNNN`), `billing_email`, `billing_invoice_email` (optional), `billing_phone`, `billing_first_name`, `billing_last_name`
  - the `billing_*` and `shipping_*` address fields
  - registration through a Kadence Advanced Form (form #10282, page `/foretagsregistrering/`) or the shortcode `[dyc_b2b_registration]`
- **PHP cache:** `local.ini` may have `opcache.validate_timestamps = 0`. If so, PHP changes only reach the website after `docker compose restart wordpress`. CLI runs (`docker exec wordpress_app php ...`) are not affected.
- **Git:** no global git identity is configured. Commits so far use `git -c user.name="DYC Development" -c user.email="dev@dyc.local"`. Work goes on a branch, which the user merges into `main` (fast-forward).
- **Test data:** use fake domains such as `@dyc-test-a.se`. Delete WordPress test users afterwards, but **keep Briox test customers** (user's wish, useful for troubleshooting).
- **WP-Cron is disabled on the dev site** (`define( 'DISABLE_WP_CRON', true );` in wp-config.php) and no server cron runs, so Action Scheduler jobs are never processed automatically here. Other plugins' jobs have been waiting since June 2026; **don't run the whole queue**. To process only a Briox sync job: `ActionScheduler::runner()->process_action( $action_id, 'manual' )` for the pending `dyc_briox_sync_customer` action, or use the "Synka till Briox nu" button on the user profile.
- **Credentials:** never in chat or git. Since v1.4.0 they go on **WooCommerce → Settings → Briox** (bound to the site address) or, overriding that, in `wordpress/wp-config.php` as constants. The dev site uses wp-config.php.
- **wp-config.php is a single-file Docker bind mount.** Never replace it (e.g. `sed -i`, which writes a new file): the container keeps the deleted copy, and a restart then fails ("error mounting … wp-config.php"). Edit in place (`cat x > wp-config.php`); if it happens, `docker compose up -d --force-recreate wordpress` fixes it.

## API reference (summary)

| | |
|---|---|
| Docs (Sweden) | https://apidoc-se.briox.services/ (raw spec: `/swagger.php`) |
| Docs (Finland, not used) | https://apidoc-fi.briox.services/ |
| Base URL | `https://api-se.briox.services/v2` |
| Support | apisupport@briox.se |

The Swedish API is the Finnish one plus Swedish-only features (Skatteverket, Kivra, payroll, household services). Responses are JSON wrapped in `{"data": {...}}`.

### Authentication
1. In Briox, an admin goes to **Admin → Users → External Applications**, activates the integration and generates an **authentication token**. The **client ID** appears to be the Briox account number. (Help-center guides: [eTasku](https://help.briox.fi/hc/en-us/articles/360023073411-Getting-started-with-eTasku), [Hillava](https://help.briox.fi/hc/en-us/articles/360016834019-Activate-the-Hillava-Integration).)
2. `POST /token?clientid=…&token=…` returns `access_token`, `refresh_token`, `expire_date`, `expire_timestamp`.
3. Send the access token in the `Authorization` header as the **raw token** (no `Bearer ` prefix). Verified 2026-10-10.
4. **The authentication token can only be exchanged once** (a second `/token` call with it gives `400 Token(s) are invalid`, verified 2026-10-11). After that the connection lives on refreshes; if the chain breaks, a new auth token must be generated in Briox and put in wp-config.php.
5. `POST /tokenrefresh?refreshtoken=…&token=…` returns a **new access token AND a new refresh token**. The old access token stops working immediately (401). Save the new pair at once, and allow only one refresh at a time (lock), or a parallel request can end up with a dead token.
6. **Request bodies must wrap the object:** `{"customer": {...}}`. A bare object gives `400 "Invalid data in request."`, and a form-encoded body gives `400 "Request must be valid json"`.

### Customer endpoints
| Method | Path | Use |
|---|---|---|
| GET | `/customer?companynumber=…&page=&limit=` | Look up a customer by org.nr (duplicate check). **Exact match on the dashed format** `556012-5790`; `5560125790` finds nothing |
| POST | `/customer` | Create |
| GET | `/customer/{id}` | Read. **`{id}` is the customer number (`custno`)**; the list response has no separate `id` field |
| PUT | `/customer/{id}` | Update |
| DELETE | `/customer/{id}` | Delete (test cleanup) |
| GET | `/customer/email/{email}` | Look up a customer by email. Returns `data.customer` as an **array** (several possible); not found = `400 "Customer missing!"` |

### Field mapping (draft)
Required fields in Briox: `custno` and `name`.

| WordPress | Briox `customer` field |
|---|---|
| `billing_company` | `name` |
| `billing_orgnr` | `companynumber` |
| `SE` + org.nr digits + `01` | `vatnumber` |
| fixed value `1` (company) | `customerbusinesstype` |
| `billing_email` | `email` |
| `billing_invoice_email`, falling back to `billing_email` | `edocument: [{type: "invoice", email, cc: "", bcc: ""}]` only (**no reminder email**, per 2.3) |
| fixed value | `invoicedocumentdeliverymethod: "00"` (**print**, per 2.3) |
| `billing_phone` | `phone` |
| first and last name | `customerref` |
| billing address | `address[]` entry with `type: "invoice"`: `addressline1`, `addressline2`, `zip`, `city`, `countrycode` (`SE`) |
| shipping address | `address[]` entry with `type: "delivery"`: `name`, `addressline1`, `addressline2`, `zip`, `city`, `countrycode`; plus top-level `deliveryname` |
| fixed value | `customertype: 0` (domestic VAT) |
| fixed value | `currency: "SEK"` |
| fixed value | `paymentterms: "15"` (15 days; ids from `GET /payment/term`: 0, 15, 20, 30, AG, K, PF) |
| fixed value | `pricelist: "A"` |
| **not possible via API** | Invoice template "DYC-Faktura" (customer card → "Förvalda mallar"); see 2.3 |
| omit (recommended, see 2.1) | `custno`: Briox assigns highest existing + 1 |

### Unknowns (answer in Phase 1)
- [x] Valid values for `address[].type`: **`invoice`** and **`delivery`** (the delivery entry also has `name`)
- [x] Does Briox assign `custno` automatically if it is omitted? **Yes: highest existing number + 1.** Creating `90001` manually made the next automatic number `90002`, so manual high numbers move the whole sequence
- [x] `Authorization` header format: **raw token** works (`Authorization: <access_token>`)
- [x] Access token lifetime and refresh-token behaviour: lifetime is **30 days** (`expire_timestamp`); refresh rotates both tokens and invalidates the old access token immediately
- [x] Org.nr format in `companynumber`: Briox stores **`NNNNNN-NNNN`** (e.g. `559104-3673`), the same as our `billing_orgnr`. `vatnumber` = `SE` + 10 digits + `01`
- [x] Error response format: always `{"error":["<message>"],"error_code":[],"data":null}`. `error_code` is empty, so errors can only be told apart by the message text. **Not-found is HTTP 400, not 404.** Seen:
  - 400 `Token(s) are invalid` (bad client ID / auth token at `/token`)
  - 401 `Access token is invalid`
  - 400 `Invalid data in request.` (body not wrapped in `{"customer": …}`)
  - 400 `Request must be valid json`
  - 400 `No name specified`
  - 400 `The customer no already exists. Please choose another.`
  - 400 `Customer missing!` (GET unknown custno or email)
  - 400 `Payment method is invalid.` (unknown `paymentterms`)
- [ ] Rate limits (not documented)
- [x] `PUT /customer/{id}` behaviour: **updates the same customer (same custno), but overwrites all fields: fields not sent are cleared.** Scalar fields not sent are cleared (tested on 90002: email, org.nr, VAT number, payment terms, reference wiped; delivery methods reset to `00`). `address[]`/`edocument[]` not sent are kept, but the delivery address `name` is cleared. Updates must be read → merge → PUT the complete object (implemented; 90002 restored this way)
- [x] How to register a custom integration / get an Application ID: **not needed for the test account**, since the token exchange works with only client ID + auth token. Check whether production needs anything else.

---

## Phase 0: Prerequisites (user)
- [x] Scan the API documentation and draft this plan
- [x] Confirm the Briox account is Swedish (login at briox.se, not briox.fi)
- [x] Email apisupport@briox.se: how to register a custom integration (Application ID), and whether there are API limits
- [x] Test account: activate the integration under External Applications and generate an authentication token
- [x] Note the test account's account number (client ID)
- [x] Add the credentials to `wordpress/wp-config.php`:
  ```php
  define( 'DYC_BRIOX_ENV', 'test' );           // 'test' or 'prod'
  define( 'DYC_BRIOX_CLIENT_ID', '...' );
  define( 'DYC_BRIOX_AUTH_TOKEN', '...' );

  ```

## Phase 1: Explore by hand against the test account (Claude)
- [x] 1.1 Get an access token with `/token`; record its lifetime; test `/tokenrefresh`. Token obtained (30-day lifetime) and saved in WP option `dyc_briox_tokens` (autoload off). Refresh works and rotates both tokens; the current pair is in the same option
- [x] 1.2 `GET /company/info` and `GET /customer?limit=5`; confirm the header format and response shape. Connected to **"Dockyard Test Account"** (org.nr 559465-4047), which has 0 customers. The list response has `data.customers[]` and `data.metainformation` {current_page, limit, total_pages, total_count}
- [x] 1.3 In the Briox web interface, create a test company customer (invoice email, invoice and delivery address, payment terms); `GET` it to learn the exact field formats. Sample: "Movx AB", custno `1`. Findings are in the field mapping and unknowns. Notes:
  - Briox filled in `description` and `sni_codes` itself, apparently from the company register via org.nr.
  - The list endpoint returns a **flat** address format (`address`, `zip`, `deladdress`, `delzip`, …); the detail endpoint returns the nested `address[]` array.
  - Other values in the sample: `interestinvoicing: "1"`, `einvoice_preference: "unknown"`, `currency: "SEK"`, `customertype: "0"`.
- [x] 1.4 `POST /customer` with and without `custno`; does Briox assign a number? Yes (see Unknowns). Notes:
  - The draft mapping payload was stored exactly: addresses, both edocument entries, payment terms `30`, price list `A`.
  - Unlike UI-created customers, `description` and `sni_codes` stay **empty** via the API (no company-register lookup).
  - `order`/`quote` edocuments not sent come back with `email: null`, and their delivery methods default to `00` (print).
  - **Briox accepts several customers with the same org.nr**; the plugin must do the duplicate check itself.
- [x] 1.5 `GET /customer?companynumber=` with both org.nr formats: only the dashed format matches. Email lookup works too (returns an array)
- [x] 1.6 Trigger errors (missing name, duplicate `custno`, bad token); record the error format (see Unknowns)
- [x] 1.7 ~~Delete all test customers~~: **the user chose to keep them** for troubleshooting. Test customers in the Briox test account:
  - `1` Movx AB (created by the user in the UI)
  - `2` TEST API utan kundnr (API, no custno sent)
  - `90001` TEST API med kundnr 90001 (API, custno sent)
  - `90002` TEST API utan kundnr 2 (API, no custno, numbered after 90001)
  - Customers 2, 90001 and 90002 all use Volvo's org.nr `556012-5790` and `@dyc-test-a.se` emails.
  - The test account's next automatic customer number is now **90003**.
- [x] 1.8 Fill in the [Unknowns](#unknowns-answer-in-phase-1) above and write the findings in the [Session log](#session-log). Only rate limits remain unknown

## Phase 2: Decisions (user)
- [x] 2.1 Customer numbering: Briox auto / own range (e.g. `50000` + WordPress user ID) / next free number. **Recommendation: Briox auto** (omit `custno`). An own high range would move Briox's automatic numbering for customers created in the UI too (see 1.4)
- [x] 2.2 Existing Briox customer with the same org.nr: link to it (recommended) or create a new one. Briox itself does **not** prevent duplicates, so the plugin's lookup is the only protection. Also decide what to do when several matches exist
- [x] 2.3 Defaults for new business customers: payment terms, invoice delivery (email), price list
- [x] 2.4 Should My Account changes (address, invoice email) also update Briox?
- [x] 2.5 Code location: separate plugin `dyc-briox-sync` (recommended) or inside `dyc-b2b-registration`

Record each answer here:
- 2.1: **Briox assigns the customer number** (omit `custno`).
- 2.2: **One Briox customer per WordPress user.** If the org.nr already exists in Briox for another user, still create a new Briox customer, matching WooCommerce, where only the username is unique. Store the Briox custno on the WP user (`_dyc_briox_customer_id`); a user with a stored custno is never created again (retries and later syncs update that customer). No org.nr lookup is needed for linking.
- 2.3: **Template "DYC-Faktura"**, payment terms **15 days**, price list **A**, invoices by **print** (`00`), **no reminder email** (don't fill the reminder edocument).
  - The template can't be set via the API. The customer card's "Förvalda mallar" setting doesn't appear in `GET /customer/{id}` (verified: user set DYC-Faktura on customer 2; nothing in the response changed except null → ""), and neither the Customer nor the CustomerInvoice schema has a template field.
  - Options: (a) make DYC-Faktura the account-wide default template in Briox settings (recommended, no code needed); (b) ask apisupport@briox.se whether there's an undocumented field for the customer's default template.
  - Decision: **(a)**. The user sets DYC-Faktura as the account-wide default template in Briox (test and production). No code needed.
- 2.4: **Yes.** When a business customer saves their billing/shipping address (incl. company, invoice email) in My Account, update their Briox customer (`PUT /customer/{custno}`). The org.nr is locked, so it never changes. Users without a stored custno get created instead.
- 2.5: **Inside `dyc-b2b-registration`** (not a separate plugin). Phase 3 steps adjusted accordingly.

## Phase 3: Build (Claude)
- [x] 3.1 Add a Briox module to `dyc-b2b-registration` (per 2.5, e.g. `includes/class-dyc-briox-*.php`); read credentials from the `wp-config.php` constants
- [x] 3.2 Fire `do_action( 'dyc_b2b_customer_registered', $customer_id )` after the account is created (Kadence and shortcode handlers); the Briox module hooks into it
- [x] 3.3 API client: get, store and refresh tokens; timeouts; retry temporary errors; log to the WooCommerce logger (source `dyc-briox`)
- [x] 3.4 Mapper: WordPress user → Briox `customer` object (see the field mapping)
- [x] 3.5 Background sync with Action Scheduler: if the user has `_dyc_briox_customer_id`, update; otherwise create (no org.nr linking, per 2.2) and save the returned custno; retry on failure. Watch for a lost response after a successful create, which would cause a duplicate on retry (e.g. check `GET /customer/email/{email}` before re-creating)
- [x] 3.6 Update sync on My Account changes (2.4 = yes): `woocommerce_customer_save_address` → queue `PUT /customer/{custno}`. First test PUT behaviour on a test customer (partial vs full replace, see Unknowns) and send the full object if needed. Possibly also sync on admin edits of a business user (`profile_update`)
- [x] 3.7 Admin: Briox customer number and sync status on the user profile, plus a "Sync now" button
- [x] 3.8 Update the plugin README (Briox section)

**Phase 3 implementation (plugin v1.3.0):**
- `includes/class-dyc-briox-client.php`: tokens in option `dyc_briox_tokens` (with a credentials hash, so changed wp-config credentials fetch new tokens), refresh 1 day before expiry under a MySQL `GET_LOCK`, one retry after a 401, errors as `WP_Error` with `status` + `retryable`.
- `includes/class-dyc-briox-sync.php`: triggers `dyc_b2b_customer_registered`, `woocommerce_customer_save_address`, `personal_options_update`, `edit_user_profile_update` → Action Scheduler (`dyc_briox_sync_customer`, group `dyc-briox`). Retries after 1m/5m/30m/2h/12h. User meta: `_dyc_briox_customer_id`, `_dyc_briox_sync` (status), `_dyc_briox_attempts`, `_dyc_briox_create_pending` (lost-response guard). Defaults via filter `dyc_briox_customer_defaults`. Profile section with a "Synka till Briox nu" button; log source `dyc-briox`.

## Phase 4: Test against the test account
- [x] 4.1 New company registers → customer created in Briox with all fields; Briox ID saved in WordPress
- [x] 4.2 Company (org.nr) already exists in Briox for another user → a **new** Briox customer is created (decision 2.2). Shown by 90003–90005, which all share org.nr 556012-5790
- [x] 4.3 No invoice email → invoices go to `billing_email`
- [x] 4.4 VIES down at registration → customer created with the company name as typed
- [x] 4.5 Briox down or token invalid → sync queued and retried, error logged, registration still succeeds
- [x] 4.6 Access token expired → refreshed automatically
- [x] 4.7 My Account edit → Briox updated (2.4 = yes); fields not edited stay intact in Briox
- [x] 4.8 Create an invoice in Briox for the test customer → correct org.nr, VAT number, 15-day terms, **DYC-Faktura template** (via the account default), delivered by print
- [x] 4.9 Delete the WordPress test data (Briox test customers are kept, per the user)
- [x] 4.10 Lost create response → retry finds the customer instead of duplicating it. **Done in a CLI test**, see the session log
- [x] 4.11 Token refresh through the plugin (force an expired token in the option and sync)

Also tested 2026-10-11: 4.3 + 4.4 in one live registration with VIES blocked and no invoice email → Briox 90006 with the typed company name, invoice edocument = billing_email. 4.6/4.11: token with 1 h left was refreshed; daily keep-alive refreshes at 2 days left but not at 5; broken chain shows the WP-Admin notice, which disappears after recovery. 4.8 done by the user (invoice with DYC-Faktura, 15 days, print).

Already covered by the 2026-10-11 tests: 4.1 (live Kadence form → Briox customer 90005 with VIES name, 15 days, list A, print, invoice email), 4.5 (simulated outage → retry in 60 s, Swedish status), 4.7 (live My Account edit → Briox updated, payment terms untouched), 4.10.

## Phase 5: Go live
- [x] 5.0 **Check that WP-Cron runs in production.** Confirmed by the user: a server cron job runs in production. If `DISABLE_WP_CRON` is set there too, a server cron must call `wp-cron.php` (e.g. every minute), or syncs will wait. Check Tools → Scheduled Actions for old pending jobs.
- [ ] 5.1 Production credentials in `wp-config.php`, `DYC_BRIOX_ENV` = `prod`. In the production Briox account: DYC-Faktura set as the account-wide default template, and payment term `15` + price list `A` exist
- [ ] 5.2 Register one real (internal) company and verify it in Briox
- [ ] 5.3 Watch the `dyc-briox` log for the first week
- [ ] 5.4 Decide whether existing business customers should be synced once in bulk

---

## Session log

| Date | What happened |
|---|---|
| 2026-10-10 | Scanned the Briox API (the FI link given; found and chose the SE API). Drafted this plan. Next: Phase 0 (user). |
| 2026-10-10 | Phase 0 done by the user. First token test failed (`400 Token(s) are invalid`, wrong client ID; use the number under "Your account"); after the user corrected the client ID it worked. Access token (30 days) saved in WP option `dyc_briox_tokens`. Raw-token Authorization header confirmed. Next: 1.1 refresh test, then 1.3 (user creates a sample customer in the Briox UI). |
| 2026-10-10 | 1.3 done: read the user's sample customer "Movx AB" (custno 1). Address types are `invoice`/`delivery`; org.nr is dashed; customer ID in URLs = custno; payment terms `30`, price list `A`. Next: 1.1 refresh test, 1.4–1.7 (create/lookup/errors/cleanup via API). |
| 2026-10-10 | Phase 1 done. Refresh rotates both tokens (old access token dies at once). Create needs the body wrapped as `{"customer": …}`; custno is optional (auto = highest + 1). Briox allows duplicate org.nrs; lookup needs the dashed format. Errors use message text only, and not-found = 400. User asked to keep the test customers (2, 90001, 90002). Next: Phase 2 decisions (user). |
| 2026-10-10 | Phase 2: user decided 2.1 (Briox numbering), 2.2 (always a new Briox customer per WP user), 2.3 values (DYC-Faktura, 15 days, list A, print, no reminder email), 2.5 (code inside dyc-b2b-registration). Payment term `15` confirmed via `GET /payment/term`. Found that the customer's default template ("Förvalda mallar") isn't exposed by the API. Open: template approach, 2.4. |
| 2026-10-10 | Phase 2 done: template via the account-wide default in Briox (user sets it); My Account changes update Briox (2.4 = yes). New unknown: PUT behaviour (partial vs full), test first in 3.6. Next: Phase 3 build. |
| 2026-10-11 | Phase 3 built on branch `briox-sync` (v1.3.0). PUT keeps the customer/custno but clears every field not sent, so updates use read → merge → PUT. Tested: CLI create (90003), update keeps a Briox-side payment-term change, outage → retry, lost response → recovered 90004 without a duplicate; live Kadence registration → 90005; live My Account edit → updated; admin section renders for business users only. Found `DISABLE_WP_CRON` on the dev site with no server cron (queue never runs automatically; jobs processed by hand). WP test users deleted; Briox customers 90003–90005 kept. Next: merge, remaining Phase 4 tests, check cron in production (5.0). |
| 2026-10-11 | Merged `briox-sync`. Found that the auth token can only be exchanged once, so added (branch `briox-token-keepalive`) a daily keep-alive refresh (3-day margin) and a WP-Admin notice when the connection breaks. Tested 4.3, 4.4, 4.6/4.11; user did 4.8 and confirmed a server cron in production (5.0). Briox test customer 90006 added (kept). Phase 4 complete. Next: Phase 5 go-live (user). |
| 2026-10-14 | v1.5.0 on branch `einvoice`: "E-Faktura" checkbox (Kadence Field Name `billing_einvoice`, also on the shortcode form) → user meta `billing_einvoice = yes` → Briox customer created with `invoicedocumentdeliverymethod: "02"`. The test account rejects 02 with `400 "E-Invoice/Print Service is not enabled."`, so the plugin falls back to `00` and shows a warning in the sync status. Tested: with checkbox → 90009 (fallback + warning), without → 90010. Real e-invoice (02 accepted) can only be verified in production. |
| 2026-10-11 | Built a production zip (v1.3.0) for the user. Then v1.4.0 on branch `briox-settings`: the plugin adds `billing_orgnr`/`billing_invoice_email` when no other plugin defines them; WooCommerce → Settings → Briox with status, client ID, auth token (exchanged on save, never re-displayed), environment and payment terms (from `GET /payment/term`); wp-config.php constants override it; stored credentials bound to the site address. All tested (incl. the settings path with the wp-config constants temporarily disabled). Incident during the test: `sed -i` broke the wp-config.php bind mount; the container was recreated (site down ~1 min). |
