<?php
/**
 * Korapay Payment Gateway — Click-through redirect
 *
 * Fires when a logged-in client clicks "Pay with Korapay" on an invoice.
 * Verifies session ownership of the invoice, initializes a fresh charge
 * at Korapay server-to-server, and 302s the browser to the hosted
 * checkout URL.
 *
 * Never initializes a charge just from page views — only on explicit click.
 * Never trusts the POSTed invoice id without checking session ownership.
 *
 * Author:  Decipher
 * Version: 1.0.0
 */

require_once __DIR__ . "/../../../init.php";

use WHMCS\Database\Capsule;

App::load_function("gateway");

$gatewayModuleName = "korapay";
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// --- 0. Gateway enabled? --------------------------------------------
if (!$gatewayParams["type"]) {
    http_response_code(503);
    die("Module not activated");
}

// --- 1. Require logged-in client session ----------------------------
// WHMCS stores the client id in $_SESSION["uid"] once authenticated.
if (empty($_SESSION["uid"])) {
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/clientarea.php");
    die();
}
$clientId = (int) $_SESSION["uid"];

// --- 2. Pull + validate invoice id ----------------------------------
$invoiceId = (int) ($_POST["invoiceid"] ?? $_GET["invoiceid"] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    die("Missing invoice id");
}

$invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
if (!$invoice) {
    http_response_code(404);
    die("Invoice not found");
}

// Ownership check — the session's client id MUST match invoice.userid.
// Without this, a logged-in client could submit another client's invoice
// number and we'd create a Korapay charge tied to the wrong account.
if ((int) $invoice->userid !== $clientId) {
    logTransaction($gatewayModuleName, [
        "reason"    => "session does not own invoice",
        "invoiceid" => $invoiceId,
        "session_uid" => $clientId,
    ], "Redirect Rejected");
    http_response_code(403);
    die("Forbidden");
}

// Already paid? Back to invoice.
if (strtolower($invoice->status) === "paid") {
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
    die();
}

// --- 3. Gather customer details -------------------------------------
$client = Capsule::table("tblclients")->where("id", $clientId)->first();
if (!$client) {
    http_response_code(500);
    die("Client record missing");
}
$customerName  = trim($client->firstname . " " . $client->lastname) ?: "Customer";
$customerEmail = $client->email;

// --- 3b. Currency gate — Korapay only supports NGN ------------------
// Uses the same tblclients.currency -> tblcurrencies.id join as S-4 in
// the callback. Refuse to initialize checkout for non-NGN invoices so
// the customer never pays the wrong amount in the wrong currency.
$invoiceCurrencyId  = $client->currency ?? 0;
$invoiceCurrencyRow = Capsule::table("tblcurrencies")
    ->where("id", $invoiceCurrencyId)
    ->first();
$invoiceCurrencyCode = $invoiceCurrencyRow ? $invoiceCurrencyRow->code : "";

if ($invoiceCurrencyCode !== "NGN") {
    logTransaction($gatewayModuleName, [
        "reason"           => "non-NGN invoice blocked at redirect",
        "invoice_currency" => $invoiceCurrencyCode,
        "invoiceid"        => $invoiceId,
    ], "Redirect Rejected");
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=currency-unsupported");
    die();
}

// --- 4. Build reference + init call ---------------------------------
$refPrefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gatewayParams["referencePrefix"] ?? "")) ?: "INV-";
$reference = $refPrefix . $invoiceId . "-" . time() . "-" . substr(bin2hex(random_bytes(4)), 0, 8);
$systemUrl = rtrim($gatewayParams["systemurl"], "/");

$payload = [
    "reference"        => $reference,
    "amount"           => (float) $invoice->total,
    "currency"         => "NGN",
    "notification_url" => $systemUrl . "/modules/gateways/callback/korapay.php",
    "redirect_url"     => $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true",
    "customer"         => [
        "name"  => $customerName,
        "email" => $customerEmail,
    ],
    "metadata" => [
        "invoice_id" => (string) $invoiceId,
        "client_id"  => (string) $clientId,
        "source"     => "whmcs",
    ],
];

$ch = curl_init("https://api.korapay.com/merchant/api/v1/charges/initialize");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($payload),
    CURLOPT_HTTPHEADER      => [
        "Authorization: Bearer " . trim($gatewayParams["secretKey"]),
        "Content-Type: application/json",
        "Accept: application/json",
    ],
    CURLOPT_TIMEOUT         => 20,
    CURLOPT_CONNECTTIMEOUT  => 10,
    // S-6: pin SSL verification — never rely on php.ini defaults
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    logTransaction($gatewayModuleName, [
        "error"      => "cURL failed",
        "curl_error" => $curlErr,
        "ref"        => $reference,
        "invoiceid"  => $invoiceId,
    ], "Initialize Failed");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=gateway-unreachable");
    die();
}

$decoded = json_decode($response, true);
if (!is_array($decoded) || empty($decoded["status"]) || empty($decoded["data"]["checkout_url"])) {
    logTransaction($gatewayModuleName, [
        "http"      => $httpCode,
        "ref"       => $reference,
        "invoiceid" => $invoiceId,
    ], "Initialize Failed");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=initialize-failed");
    die();
}

$checkoutUrl = $decoded["data"]["checkout_url"];

logTransaction($gatewayModuleName, [
    "invoiceid" => $invoiceId,
    "ref"       => $reference,
    "amount"    => $invoice->total,
    "result"    => "checkout initialized",
], "Redirect Issued");

// --- 5. Redirect to hosted checkout ---------------------------------
header("Location: " . $checkoutUrl, true, 302);
die();
