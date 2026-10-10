# DYC B2B Registration

Custom B2B registration plugin for WooCommerce with Swedish Organisationsnummer (Luhn check + EU VIES API) and corporate email domain validation.

## Features
- **Standalone B2B Registration Form**: Use shortcode `[dyc_b2b_registration]` or `[dyc_b2b_registration_form]`.
- **Kadence Advanced Form Integration**: Build the registration form in Kadence Blocks instead of using the shortcode (see below).
- **Swedish Organisationsnummer Validation**:
  - Modulus 10 (Luhn algorithm) checksum verification.
  - EU VIES REST API verification (`SE` + 10-digit org.nr + `01`).
  - Interactive "Hämta uppgifter" AJAX button that auto-populates registered company name (shortcode form only).
- **Corporate Email Verification**: Rejects consumer email providers (Gmail, Hotmail, etc.).
- **Automatic Account Setup**:
  - Creates customer account and assigns the `business` user role.
  - Generates a unique username from the email address (`info`, `info-2`, ...); customers log in with their email.
  - Saves all billing and shipping metadata (including `billing_orgnr` and `billing_invoice_email`).
  - Automatically logs in the new customer.
- **Role-Based Field Visibility**:
  - `billing_company`, `billing_orgnr`, `billing_invoice_email` and `shipping_company` are hidden from retail B2C customers on checkout and account addresses.
  - Keeps standard WooCommerce registration (`/mina-sidor/`) 100% clean for retail consumers.

## Kadence Advanced Form Integration

Any Kadence Advanced Form that contains a `billing_orgnr` field is treated as a B2B registration form. Other Kadence forms on the site are not affected.

### Field names

Set each field's **Field Name** (the input's `name` attribute) to match the WooCommerce meta key:

| Field Name | Required | Notes |
|---|---|---|
| `billing_orgnr` | Yes | Identifies the form as a B2B registration. Accepts `5560125790`, `556012-5790` or a 12-digit number with century prefix. Saved as `NNNNNN-NNNN`. |
| `billing_company` | Yes | |
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

Validation errors are shown in the form with a Swedish message and nothing is created. If account creation still fails, the form shows the WooCommerce error instead of the success message, and the redirect is skipped.

## Requirements
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ with cURL extension
- Kadence Blocks (optional, for the Advanced Form integration)
