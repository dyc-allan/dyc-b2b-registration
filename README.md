# DYC B2B Registration

Custom B2B registration plugin for WooCommerce with Swedish Organisationsnummer (Luhn check + EU VIES API) and corporate email domain validation.

## Features
- **Standalone B2B Registration Form**: Use shortcode `[dyc_b2b_registration]` or `[dyc_b2b_registration_form]`.
- **Swedish Organisationsnummer Validation**:
  - Modulus 10 (Luhn algorithm) checksum verification.
  - EU VIES REST API verification (`SE` + 10-digit org.nr + `01`).
  - Interactive "Hämta uppgifter" AJAX button that auto-populates registered company name.
- **Corporate Email Verification**: Rejects consumer email providers (Gmail, Hotmail, etc.).
- **Automatic Account Setup**:
  - Creates customer account and assigns the `business` user role.
  - Saves all billing and shipping metadata (including `billing_orgnr`).
  - Automatically logs in the new customer.
- **Role-Based Field Visibility**:
  - `billing_company`, `billing_orgnr`, and `shipping_company` are hidden from retail B2C customers on checkout and account addresses.
  - Keeps standard WooCommerce registration (`/mina-sidor/`) 100% clean for retail consumers.

## Requirements
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ with cURL extension
