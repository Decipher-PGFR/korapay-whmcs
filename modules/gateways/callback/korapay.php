<?php
/**
 * Korapay Payment Gateway \u2014 Webhook / Callback
 *
 * Korapay POSTs a JSON payload here on every charge event.
 * We only care about "charge.success" for this module.
 *
 * Trust model \u2014 we NEVER trust the webhook body on its own:
 *   1. Verify HMAC SHA256 signature header against the `data` field,
 *      signed with the merchant Secret Key (Korapay does not issue a
 *      separate webhook secret).
 *   2. Re-verify the charge server-to-server via GET /charges/:reference.
 *   3. Reconcile amount + currency + status against the WHMCS invoice.
 *   4. Only then call addInvoicePayment.
 *
 * Any single-step bypass is a bug.
 *
 * @see https://docs.korapay.com/docs/webhook
 * @see https://github.com/Decipher-PGFR/korapay-whmcs
 *
 * Author:  Decipher Media Solutions LTD
 * License: MIT
 * Version: 1.0.0
 */

require_once __DIR__ . "/../../../init.php";

use WHMCS\Database\Capsule;

App::load_function("gateway");
App::load_function("invoice");

$gatewayModuleName = "korapay";
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// --- 0. Gateway enabled? --------------------------------------------
if (!$gatewayParams["type"]) {
    http_response_code(503);
    die("Module Not Activated");
}

// Close the WHMCS session immediately. Webhook requests don't need
// session state, and leaving it open creates a new tblsessions row on
// every Korapay POST (including retries and tamper probes).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$secretKey = trim($gatewayParams["secretKey"]);

// --- 1. Read raw body + signature -----------------------------------
$rawBody   = file_get_contents("php://input");
$headers   = function_exists("getallheaders") ? getallheaders() : [];
$signature = "";
foreach ($headers as $name => $value) {
    if (strtolower($name) === "x-korapay-signature") {
        $signature = trim($value);
        break;
    }
}

if (empty($rawBody) || empty($signature) || empty($secretKey)) {
    logTransaction($gatewayModuleName, ["reason" => "missing body/signature/secret"], "Webhook Rejected");
    http_response_code(400);
    die("Bad request");
}

// --- 2. HMAC SHA256 verify ------------------------------------------
// Korapay signs the `data` field of the payload, not the entire body.
$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload["data"])) {
    logTransaction($gatewayModuleName, ["reason" => "payload not json"], "Webhook Rejected");
    http_response_code(400);
    die("Malformed");
}

// Extract the raw "data" JSON substring from the body to avoid
// PHP json_encode float-precision corruption (serialize_precision != -1).
// Korapay signs JSON.stringify(data) \u2014 we must match their exact bytes.
//
// Depth-counted brace matcher: walks from the opening { after "data":
// and counts brace depth, respecting JSON string boundaries (skips
// content inside double quotes, handles escaped quotes).
$dataJson = null;
$dataKeyPos = strpos($rawBody, '"data"');
if ($dataKeyPos !== false) {
    $openBrace = strpos($rawBody, '{', $dataKeyPos + 6);
    if ($openBrace !== false) {
        $len   = strlen($rawBody);
        $depth = 0;
        $inStr = false;
        $end   = null;
        for ($i = $openBrace; $i < $len; $i++) {
            $c = $rawBody[$i];
            if ($inStr) {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end !== null) {
            $dataJson = substr($rawBody, $openBrace, $end - $openBrace + 1);
        }
    }
}
// Fallback: re-encode (risks float corruption but better than a hard fail)
if ($dataJson === null) {
    $dataJson = json_encode($payload["data"]);
}
$expected = hash_hmac("sha256", $dataJson, $secretKey);

if (!hash_equals($expected, $signature)) {
    logTransaction($gatewayModuleName, ["reason" => "signature mismatch"], "Webhook Rejected");
    http_response_code(401);
    die("Invalid signature");
}

// --- 3. Only handle charge.success ----------------------------------
$event = $payload["event"] ?? "";
if ($event !== "charge.success") {
    logTransaction($gatewayModuleName, ["event" => $event], "Webhook Ignored (non-success event)");
    http_response_code(200);
    die("OK");
}

$data = $payload["data"];
$reference  = $data["reference"]   ?? "";
$amountPaid = $data["amount"]      ?? 0;
$currency   = $data["currency"]    ?? "";
$status     = $data["status"]      ?? "";
$metadata   = $data["metadata"]    ?? [];
$invoiceIdFromMeta = $metadata["invoice_id"] ?? null;

// --- 4. Derive invoice id -------------------------------------------
// Prefer metadata.invoice_id. Fall back to parsing the DEC-<id>-* reference.
$invoiceId = null;
if (!empty($invoiceIdFromMeta) && ctype_digit((string) $invoiceIdFromMeta)) {
    $invoiceId = (int) $invoiceIdFromMeta;
} elseif (preg_match('/^DEC-(\\d+)-/', $reference, $m)) {
    $invoiceId = (int) $m[1];
}

if (!$invoiceId) {
    logTransaction($gatewayModuleName, ["reason" => "no invoice id", "ref" => $reference], "Webhook Rejected");
    http_response_code(400);
    die("Missing invoice id");
}

// --- 5. Server-side re-verify ---------------------------------------
$ch = curl_init("https://api.korapay.com/merchant/api/v1/charges/" . urlencode($reference));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_HTTPHEADER      => [
        "Authorization: Bearer " . $secretKey,
        "Accept: application/json",
    ],
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
]);
$verifyResp = curl_exec($ch);
$verifyHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$verify = json_decode($verifyResp, true);
if (!is_array($verify) || empty($verify["status"]) || empty($verify["data"])) {
    logTransaction($gatewayModuleName, ["reason" => "verify call failed", "http" => $verifyHttp], "Webhook Rejected");
    http_response_code(500);
    die("Verify failed");
}

$vStatus   = $verify["data"]["status"]   ?? "";
$vAmount   = $verify["data"]["amount"]   ?? 0;
$vCurrency = $verify["data"]["currency"] ?? "";

if ($vStatus !== "success") {
    logTransaction($gatewayModuleName, ["reason" => "server-verify not success", "status" => $vStatus, "ref" => $reference], "Webhook Rejected");
    http_response_code(200);
    die("Not a success charge");
}

// --- 6. WHMCS helpers: invoice id + duplicate protection ------------
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);
checkCbTransID($reference);

// --- 7. Amount + currency reconciliation ----------------------------
$invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
if (!$invoice) {
    logTransaction($gatewayModuleName, ["reason" => "invoice not found", "invoiceid" => $invoiceId], "Webhook Rejected");
    http_response_code(404);
    die("Invoice not found");
}

$invoiceTotal = (float) $invoice->total;

// WHMCS tblinvoices has no currency column. Currency lives on the
// client record (tblclients.currency -> tblcurrencies.id).
$client = Capsule::table("tblclients")->where("id", $invoice->userid)->first();
$invoiceCurrencyId = $client ? $client->currency : 0;
$invoiceCurrencyRow = Capsule::table("tblcurrencies")
    ->where("id", $invoiceCurrencyId)
    ->first();
$invoiceCurrencyCode = $invoiceCurrencyRow ? $invoiceCurrencyRow->code : "";

// Currency must match across all three sources: invoice, webhook, server-verify
if ($invoiceCurrencyCode !== $vCurrency || $vCurrency !== "NGN") {
    logTransaction($gatewayModuleName, [
        "reason"           => "currency mismatch",
        "invoice_currency" => $invoiceCurrencyCode,
        "verify_currency"  => $vCurrency,
        "ref"              => $reference,
    ], "Webhook Rejected");
    http_response_code(409);
    die("Currency mismatch");
}

// Exact-amount enforcement. No admin toggle.
if (abs($vAmount - $invoiceTotal) > 0.01) {
    logTransaction($gatewayModuleName, [
        "reason" => "amount mismatch",
        "paid"   => $vAmount,
        "total"  => $invoiceTotal,
        "ref"    => $reference,
    ], "Webhook Rejected");
    http_response_code(409);
    die("Amount mismatch");
}

// --- 8. Apply payment ------------------------------------------------
// Idempotency guard \u2014 belt-and-braces on top of checkCbTransID.
$alreadyApplied = Capsule::table("tblaccounts")
    ->where("transid", $reference)
    ->where("invoiceid", $invoiceId)
    ->exists();
if ($alreadyApplied) {
    logTransaction($gatewayModuleName, [
        "reason" => "duplicate payment (idempotency guard)",
        "ref"    => $reference,
        "invoiceid" => $invoiceId,
    ], "Webhook Ignored (already applied)");
    http_response_code(200);
    die("OK");
}

addInvoicePayment(
    $invoiceId,
    $reference,
    $vAmount,
    0,
    $gatewayModuleName
);

logTransaction($gatewayModuleName, [
    "invoiceid" => $invoiceId,
    "amount"    => $vAmount,
    "currency"  => $vCurrency,
    "ref"       => $reference,
    "result"    => "payment applied",
], "Successful");

http_response_code(200);
die("OK");
