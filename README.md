# DYC B2B Registration

Custom B2B registration plugin for WooCommerce with Swedish Organisationsnummer (Luhn check + EU VIES API) and corporate email domain validation.

## Features
- **Standalone B2B Registration Form**: Use shortcode `[dyc_b2b_registration]` or `[dyc_b2b_registration_form]`.
- **Kadence Advanced Form Integration**: Build the registration form in Kadence Blocks instead of using the shortcode (see below).
- **Swedish Organisationsnummer Validation**:
  - Modulus 10 (Luhn algorithm) checksum verification.
  - EU VIES REST API verification (`SE` + 10-digit org.nr + `01`).
  - Interactive "Hämta uppgifter" AJAX button that auto-populates registered company name (shortcode form).
  - Automatic lookup when the customer leaves the org.nr field, filling in a read-only company name field (Kadence form).
  - The company name registered at VIES always overwrites the submitted company name.
- **Corporate Email Verification**: Rejects consumer email providers (Gmail, Hotmail, etc.).
- **Automatic Account Setup**:
  - Creates customer account and assigns the `business` user role.
  - Generates a unique username from the email address (`info`, `info-2`, ...); customers log in with their email.
  - Saves all billing and shipping metadata (including `billing_orgnr` and `billing_invoice_email`).
  - Automatically logs in the new customer.
  - Shows a one-time "Företaget är nu registrerat!" dialog on the page the customer is redirected to (Kadence form).
- **Role-Based Field Visibility**:
  - `billing_company`, `billing_orgnr`, `billing_invoice_email` and `shipping_company` are shown on checkout and My Account addresses only to the `business`, `administrator` and `shop_manager` roles, and hidden from retail customers (`customer` role) and guests.
  - Keeps standard WooCommerce registration (`/mina-sidor/`) 100% clean for retail consumers.
  - Runs after Kadence Woo Extras' checkout field editor, which would otherwise re-add the fields for everyone.
- **Locked Organisationsnummer**: Once stored, `billing_orgnr` is read-only for the customer in My Account and at checkout, and any submitted change is ignored server-side. Users without a stored org.nr can still enter one. Change it from WP-Admin if needed.
- **WP-Admin**: Org.nr and invoice email are shown on the order and user edit screens.
- **Briox Sync**: New business customers are created as customers in Briox, and later address changes are sent to Briox (see below).

## Setup

1. Add `billing_orgnr` (Organisationsnr) and `billing_invoice_email` (Faktura email) as billing fields in Kadence Woo Extras' checkout field editor. The plugin does not add these fields itself; it only controls who sees them and locks the org.nr.
2. Build the registration form, either with the shortcode or as a Kadence Advanced Form (see below).

## Kadence Advanced Form Integration

Any Kadence Advanced Form that contains a `billing_orgnr` field is treated as a B2B registration form. Other Kadence forms on the site are not affected.

### Field names

Set each field's **Field Name** (the input's `name` attribute) to match the WooCommerce meta key:

| Field Name | Required | Notes |
|---|---|---|
| `billing_orgnr` | Yes | Identifies the form as a B2B registration. Accepts `5560125790`, `556012-5790` or a 12-digit number with century prefix. Saved as `NNNNNN-NNNN`. |
| `billing_company` | Yes | Read-only and filled in from VIES when the customer leaves the org.nr field. Unlocks for manual entry if VIES has no name (service down or name not disclosed). The VIES name is also enforced server-side. |
| `billing_first_name` | Yes | |
| `billing_last_name` | Yes | |
| `billing_email` | Yes | Must be a corporate address and not already registered. Used as the login email. |
| `billing_address_1` | Yes | |
| `billing_postcode` | Yes | |
| `billing_city` | Yes | |
| `billing_invoice_email` | No | Validated as an email address if filled in. |
| `billing_phone` | No | |
| `billing_address_2` | No | |
| `billing_country` | No | Defaults to `SE`. |
| `shipping_first_name`, `shipping_last_name`, `shipping_company`, `shipping_address_1`, `shipping_address_2`, `shipping_postcode`, `shipping_city`, `shipping_country` | No | Each falls back to the matching billing value when empty. |
| `password` | No | Minimum 8 characters. If the form has no password field, WooCommerce generates one and emails the customer a link to set their own. |
| `password_confirm` | No | If present, must match `password`. |

### Submit actions

The plugin validates the form and creates the account itself through Kadence's submission hooks, so no submit action is needed for registration to work.

- **Redirect**: Recommended. Point it to My Account (`/mina-sidor/`) so the newly logged-in customer lands there. It only fires if the account was created.
- **Email** and **Database Entries**: Do not enable. Both include every submitted field, so the customer's password would be emailed or stored in plain text. Note that Kadence falls back to Email if no actions are saved, so make sure it is deselected.
- **Auto Email**: Not needed. WooCommerce already sends its "new account" email.

### Error handling

Validation errors are shown in the form with a Swedish message and nothing is created. Org.nr lookup errors are shown directly under the org.nr field when the customer leaves it. If account creation still fails, the form shows the WooCommerce error instead of the success message, and the redirect is skipped.

## Briox Integration

Business customers (role `business`) are synced to the invoicing system Briox (Swedish API, `https://api-se.briox.services/v2`). The full plan, API findings and test log are in [BRIOX_INTEGRATION_PLAN.md](BRIOX_INTEGRATION_PLAN.md).

### Configuration

Add to `wp-config.php` (above the "stop editing" line):

```php
define( 'DYC_BRIOX_ENV', 'test' );       // 'test' or 'prod' (shown in WP-Admin)
define( 'DYC_BRIOX_CLIENT_ID', '...' );  // account number under "Your account" in Briox
define( 'DYC_BRIOX_AUTH_TOKEN', '...' ); // Admin -> Users -> External Applications -> generate token
```

The plugin exchanges these for an access token (valid 30 days) and refreshes it automatically. Tokens are stored in the option `dyc_briox_tokens`. Changing the credentials (e.g. test to prod) makes the plugin fetch new tokens.

**The authentication token can only be exchanged once.** After that the connection lives on refreshes alone, so:
- A daily background job (`dyc_briox_keep_token_alive`) refreshes the access token when it has less than 3 days left, even if no customers register.
- If the connection breaks anyway (refresh and exchange both fail), administrators see a red notice in WP-Admin. To fix it: generate a new authentication token in Briox (Admin → Users → External Applications), replace `DYC_BRIOX_AUTH_TOKEN` in `wp-config.php`, then sync affected customers with "Synka till Briox nu".

### What is synced

| When | What happens |
|---|---|
| A business customer registers (Kadence form or shortcode) | A Briox customer is created; Briox assigns the customer number |
| The customer saves an address in My Account, or an admin saves the user profile | The Briox customer is updated |

- **One Briox customer per WordPress user**, even if the org.nr already exists in Briox. The Briox customer number is stored in user meta `_dyc_briox_customer_id`, and the user is never created twice.
- **New customers get:** payment terms 15 days, price list A, invoices by print, and the invoice email from `billing_invoice_email` (falling back to `billing_email`). Reminders get no email. These defaults can be changed with the `dyc_briox_customer_defaults` filter. They are only sent on create, so changes made in Briox stick.
- **Updates** read the customer from Briox, change only the fields the website owns (name, org.nr, VAT number, email, phone, reference, addresses, invoice email) and send the complete customer back. The customer and its number stay the same, but Briox's update overwrites all fields: any field left out of the request is cleared. So a partial update would wipe the other fields.
- **Invoice template:** the API can't set a customer's default template. "DYC-Faktura" is set as the account-wide default template in Briox.

### Background processing and retries

Syncs run in the background through WooCommerce's Action Scheduler (hook `dyc_briox_sync_customer`, group `dyc-briox`), so registration never waits for, or fails because of, Briox.

- Temporary errors (timeouts, Briox down, HTTP 5xx/429) are retried after 1 min, 5 min, 30 min, 2 h and 12 h. Validation errors are not retried.
- If Briox created a customer but the answer was lost, the retry finds it by email and org.nr instead of creating a duplicate.
- **Action Scheduler needs WP-Cron to run.** If `DISABLE_WP_CRON` is set in `wp-config.php`, a server cron job must call `wp-cron.php` (e.g. every minute), or queued syncs will wait.

### Monitoring

- **User profile (WP-Admin):** a "Briox" section shows the customer number, sync status and last error, with a **"Synka till Briox nu"** button that syncs immediately.
- **Log:** WooCommerce → Status → Logs, source `dyc-briox`. Tokens are never logged.
- **Queue:** Tools → Scheduled Actions, search for `dyc_briox_sync_customer`.

## Requirements
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ with cURL extension
- Kadence Blocks (optional, for the Advanced Form integration)
- Kadence Woo Extras checkout field editor (or another way of registering `billing_orgnr` and `billing_invoice_email` as WooCommerce billing fields)
- For the Briox sync: Briox API credentials, and a working WP-Cron (or a server cron calling `wp-cron.php`)

## Development

The local site caches PHP with OPcache. If `opcache.validate_timestamps = 0` in the project's `local.ini`, changes to this plugin don't reach the website until the container is restarted with `docker compose restart wordpress`.
