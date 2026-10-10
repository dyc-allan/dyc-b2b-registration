# Briox Integration Plan

Sync new B2B customers from the WooCommerce site to Briox (invoicing system).

**How to use this file:** This file lives in the `dyc-b2b-registration` plugin folder (versioned in its git repo). Check off steps as they are completed and add a line to the [Session log](#session-log). To resume in a new session, ask Claude to read this file and continue from the first unchecked step.

**Status:** Phase 0, waiting on prerequisites (last updated 2026-10-10)

---

## Context for a new session

- **Site:** Local WordPress in Docker, run from `/home/allan/wordpress-docker` (containers `wordpress_app` and `wordpress_db`, site at http://localhost:8000).
- **Registration plugin:** `wordpress/wp-content/plugins/dyc-b2b-registration/`. It has its own git repo; see its README. It creates the `business` user role and stores:
  - user meta `billing_company`, `billing_orgnr` (format `NNNNNN-NNNN`), `billing_email`, `billing_invoice_email` (optional), `billing_phone`, `billing_first_name`, `billing_last_name`
  - the `billing_*` and `shipping_*` address fields
  - registration through a Kadence Advanced Form (form #10282, page `/foretagsregistrering/`) or the shortcode `[dyc_b2b_registration]`
- **PHP cache:** `local.ini` may have `opcache.validate_timestamps = 0`. If so, PHP changes only reach the website after `docker compose restart wordpress`. CLI runs (`docker exec wordpress_app php ...`) are not affected.
- **Git:** no global git identity is configured. Commits so far use `git -c user.name="DYC Development" -c user.email="dev@dyc.local"`. Work goes on a branch, which the user merges into `main` (fast-forward).
- **Test data:** use fake domains such as `@dyc-test-a.se` and delete test users and customers afterwards.
- **Credentials:** never in chat or git. Put them in `wordpress/wp-config.php` as constants (see Phase 0).

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
3. Send the access token in the `Authorization` header (the exact format, raw token or `Bearer …`, is still unknown).
4. `POST /tokenrefresh?refreshtoken=…&token=…` returns a new access token.

### Customer endpoints
| Method | Path | Use |
|---|---|---|
| GET | `/customer?companynumber=…&page=&limit=` | Look up a customer by org.nr (duplicate check) |
| POST | `/customer` | Create |
| GET | `/customer/{id}` | Read |
| PUT | `/customer/{id}` | Update |
| DELETE | `/customer/{id}` | Delete (test cleanup) |
| GET | `/customer/email/{email}` | Look up a customer by email |

### Field mapping (draft)
Required fields in Briox: `custno` and `name`.

| WordPress | Briox `customer` field |
|---|---|
| `billing_company` | `name` |
| `billing_orgnr` | `companynumber` |
| `SE` + org.nr digits + `01` | `vatnumber` |
| fixed value `1` (company) | `customerbusinesstype` |
| `billing_email` | `email` |
| `billing_invoice_email`, falling back to `billing_email` | `edocument: [{type: "invoice", email}]` + `invoicedocumentdeliverymethod: "03"` (email) |
| `billing_phone` | `phone` |
| first and last name | `customerref` |
| billing and shipping address | `address[]`: `type`, `addressline1`, `addressline2`, `zip`, `city`, `countrycode` |
| fixed value | `customertype: 0` (domestic VAT) |
| fixed value | `currency: "SEK"` |
| from account settings | `paymentterms` (key must exist in the Briox account) |
| **to decide** | `custno` |

### Unknowns (answer in Phase 1)
- [ ] Valid values for `address[].type` (billing / delivery / visiting?)
- [ ] Does Briox assign `custno` automatically if it is omitted?
- [ ] `Authorization` header format: raw token or `Bearer <token>`?
- [ ] Access token lifetime and refresh-token behaviour
- [ ] Org.nr format Briox expects in `companynumber` (`5560125790` or `556012-5790`)
- [ ] Error response format (400/401/404)
- [ ] Rate limits (not documented)
- [ ] How to register a custom integration / get an Application ID

---

## Phase 0: Prerequisites (user)
- [x] Scan the API documentation and draft this plan
- [ ] Confirm the Briox account is Swedish (login at briox.se, not briox.fi)
- [ ] Email apisupport@briox.se: how to register a custom integration (Application ID), and whether there are API limits
- [ ] Test account: activate the integration under External Applications and generate an authentication token
- [ ] Note the test account's account number (client ID)
- [ ] Add the credentials to `wordpress/wp-config.php`:
  ```php
  define( 'DYC_BRIOX_ENV', 'test' );           // 'test' or 'prod'
  define( 'DYC_BRIOX_CLIENT_ID', '...' );
  define( 'DYC_BRIOX_AUTH_TOKEN', '...' );
  // define( 'DYC_BRIOX_APPLICATION_ID', '...' ); // if Briox requires one
  ```

## Phase 1: Explore by hand against the test account (Claude)
- [ ] 1.1 Get an access token with `/token`; record its lifetime; test `/tokenrefresh`
- [ ] 1.2 `GET /company/info` and `GET /customer?limit=5`; confirm the header format and response shape
- [ ] 1.3 In the Briox web interface, create a test company customer (invoice email, invoice and delivery address, payment terms); `GET` it to learn the exact field formats
- [ ] 1.4 `POST /customer` with and without `custno`; does Briox assign a number?
- [ ] 1.5 `GET /customer?companynumber=` with both org.nr formats
- [ ] 1.6 Trigger errors (missing name, duplicate `custno`, bad token); record the error format
- [ ] 1.7 Delete all test customers
- [ ] 1.8 Fill in the [Unknowns](#unknowns-answer-in-phase-1) above and write the findings in the [Session log](#session-log)

## Phase 2: Decisions (user)
- [ ] 2.1 Customer numbering: Briox auto / own range (e.g. `50000` + WordPress user ID) / next free number
- [ ] 2.2 Existing Briox customer with the same org.nr: link to it (recommended) or create a new one
- [ ] 2.3 Defaults for new business customers: payment terms, invoice delivery (email), price list
- [ ] 2.4 Should My Account changes (address, invoice email) also update Briox?
- [ ] 2.5 Code location: separate plugin `dyc-briox-sync` (recommended) or inside `dyc-b2b-registration`

Record each answer here:
- 2.1:
- 2.2:
- 2.3:
- 2.4:
- 2.5:

## Phase 3: Build (Claude)
- [ ] 3.1 Create the `dyc-briox-sync` plugin and its git repo; read credentials from the `wp-config.php` constants
- [ ] 3.2 Registration plugin: fire `do_action( 'dyc_b2b_customer_registered', $customer_id )` after the account is created (Kadence and shortcode handlers)
- [ ] 3.3 API client: get, store and refresh tokens; timeouts; retry temporary errors; log to the WooCommerce logger (source `dyc-briox`)
- [ ] 3.4 Mapper: WordPress user → Briox `customer` object (see the field mapping)
- [ ] 3.5 Background sync with Action Scheduler: look up by org.nr → link or create → save `_dyc_briox_customer_id` on the user; retry on failure
- [ ] 3.6 Update sync on My Account changes (if 2.4 = yes): `woocommerce_customer_save_address` → `PUT /customer/{id}`
- [ ] 3.7 Admin: Briox customer number and sync status on the user profile, plus a "Sync now" button
- [ ] 3.8 README for the new plugin

## Phase 4: Test against the test account
- [ ] 4.1 New company registers → customer created in Briox with all fields; Briox ID saved in WordPress
- [ ] 4.2 Company already exists in Briox → linked, no duplicate
- [ ] 4.3 No invoice email → invoices go to `billing_email`
- [ ] 4.4 VIES down at registration → customer created with the company name as typed
- [ ] 4.5 Briox down or token invalid → sync queued and retried, error logged, registration still succeeds
- [ ] 4.6 Access token expired → refreshed automatically
- [ ] 4.7 My Account edit → Briox updated (if 2.4 = yes)
- [ ] 4.8 Create an invoice in Briox for the test customer → addressed to the invoice email, with correct org.nr and VAT number
- [ ] 4.9 Delete the test data in Briox and WordPress

## Phase 5: Go live
- [ ] 5.1 Production credentials in `wp-config.php`, `DYC_BRIOX_ENV` = `prod`
- [ ] 5.2 Register one real (internal) company and verify it in Briox
- [ ] 5.3 Watch the `dyc-briox` log for the first week
- [ ] 5.4 Decide whether existing business customers should be synced once in bulk

---

## Session log

| Date | What happened |
|---|---|
| 2026-10-10 | Scanned the Briox API (the FI link given; found and chose the SE API). Drafted this plan. Next: Phase 0 (user). |
