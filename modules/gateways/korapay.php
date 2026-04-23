<?php
/**
 * WHMCS Payment Gateway integration for Korapay
 *
 * Korapay is the payment processor. This file is the WHMCS integration
 * built and maintained by Decipher Media Solutions LTD.
 * Not affiliated with or endorsed by Korapay. Korapay does not ship an
 * official WHMCS module, so this integration exists to bridge the gap.
 *
 * Flow: Checkout Redirect (hosted). Customer clicks Pay, we initialize a
 * Korapay charge server-to-server (on click, not on page render), then
 * redirect them to Korapay's hosted checkout. Authoritative confirmation
 * happens via webhook (callback/korapay.php), never via the browser return.
 *
 * Integration author:  Decipher Media Solutions LTD
 * License:             MIT
 * Version:             1.0.0
 *
 * PCI Scope: SAQ-A. No card data ever touches your servers.
 *
 * @see https://github.com/Decipher-PGFR/korapay-whmcs
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module metadata.
 */
function korapay_MetaData()
{
    return [
        "DisplayName"                 => "Korapay (Decipher integration)",
        "APIVersion"                  => "1.1",
        "DisableLocalCreditCardInput" => true,
        "TokenisedStorage"            => false,
        "Description"                 => "WHMCS integration for Korapay's hosted checkout. Korapay is the payment processor; this integration is built and maintained by Decipher Media Solutions LTD. NGN only, HMAC-verified webhook, server-side re-verify, exact-amount reconciliation. Not affiliated with or endorsed by Korapay.",
        "IntegrationDeveloper"        => "Decipher Media Solutions LTD",
        "IntegrationDeveloperURL"     => "https://decipher.ng",
        "Category"                    => "Payments",
        "SupportURL"                  => "https://github.com/Decipher-PGFR/korapay-whmcs/issues",
        "Author"                      => "Decipher Media Solutions LTD (integration author \u2014 not the payment processor)",
    ];
}

/**
 * Define gateway configuration fields rendered under
 * Setup > Payments > Payment Gateways > Korapay.
 */
function korapay_config()
{
    return [
        "FriendlyName" => [
            "Type"  => "System",
            "Value" => "Korapay",
        ],
        "publicKey" => [
            "FriendlyName" => "Public Key",
            "Type"         => "text",
            "Size"         => "64",
            "Default"      => "",
            "Description"  => "Your Korapay public key (starts with pk_live_ or pk_test_).",
        ],
        "secretKey" => [
            "FriendlyName" => "Secret Key",
            "Type"         => "password",
            "Size"         => "64",
            "Default"      => "",
            "Description"  => "Your Korapay secret key (starts with sk_live_ or sk_test_). Used for server-to-server calls and webhook signature verification.",
        ],
        "testMode" => [
            "FriendlyName" => "Test Mode",
            "Type"         => "yesno",
            "Description"  => "Tick this when using pk_test_/sk_test_ keys.",
        ],
        // Exact-amount enforcement is always on in the callback.
        // No admin toggle \u2014 partial payments are not supported.
    ];
}

/**
 * Render the payment button shown on the invoice page.
 *
 * IMPORTANT: no network calls here. We only render a form that POSTs to
 * our redirect endpoint on click, which does the actual charge init.
 * Rendering is cheap and idempotent; a single user refreshing the invoice
 * page does NOT create any charges in Korapay.
 */
function korapay_link($params)
{
    $invoiceId    = (int) $params["invoiceid"];
    $currencyCode = $params["currency"];
    $systemUrl    = rtrim($params["systemurl"], "/");
    $testMode     = !empty($params["testMode"]);

    if ($currencyCode !== "NGN") {
        return '<div style="color:#b91c1c;font-size:0.9rem">Korapay is currently NGN-only. Please change invoice currency to NGN.</div>';
    }
    if (empty($params["secretKey"])) {
        return '<div style="color:#b91c1c;font-size:0.9rem">Korapay is not configured. Contact support.</div>';
    }

    $action = htmlspecialchars($systemUrl . "/modules/gateways/callback/korapay_redirect.php", ENT_QUOTES, "UTF-8");
    $label  = $testMode ? "Pay with Korapay (TEST MODE)" : "Pay with Korapay";

    return <<<HTML
<form action="{$action}" method="post" style="margin:0;">
  <input type="hidden" name="invoiceid" value="{$invoiceId}">
  <button type="submit" style="
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 24px;border:0;border-radius:8px;
      font-family:inherit;font-weight:600;font-size:0.95rem;
      background:#0b3d2e;color:#fff;cursor:pointer;">
    {$label}
  </button>
</form>
HTML;
}
