// Knowledge the agent is grounded on: how the classic PayPal NVP / checkout.js / IPN world maps to the modern stack.
// confidence: "documented" = in PayPal's official upgrade guide; "inferred" = standard semantics, to be PROVEN by the sandbox run.

export const NVP_METHODS = {
  SetExpressCheckout:        { modern: "POST /v2/checkout/orders",                         confidence: "documented", note: "Creates the order; the buyer approves via the payer-action link / JS SDK v6." },
  GetExpressCheckoutDetails: { modern: "GET /v2/checkout/orders/{order_id}",               confidence: "documented", note: "Read the order (payer, status, amounts)." },
  DoExpressCheckoutPayment:  { modern: "POST /v2/checkout/orders/{order_id}/capture",      confidence: "documented", note: "Captures the approved order. No PAYERID needed. A repeat capture is refused (ORDER_ALREADY_CAPTURED) where NVP was idempotent." },
  RefundTransaction:         { modern: "POST /v2/payments/captures/{capture_id}/refund",   confidence: "inferred",   note: "Refund by CAPTURE id (the NVP transaction id). Full refund = empty body." },
  GetTransactionDetails:     { modern: "GET /v2/payments/captures/{capture_id}",           confidence: "inferred",   note: "Capture details." },
  DoAuthorization:           { modern: "POST /v2/checkout/orders/{order_id}/authorize",    confidence: "inferred",   note: "Authorize-only flows." },
  DoCapture:                 { modern: "POST /v2/payments/authorizations/{id}/capture",    confidence: "inferred",   note: "Capture an authorization." },
  DoVoid:                    { modern: "POST /v2/payments/authorizations/{id}/void",       confidence: "inferred",   note: "Void an authorization." },
  DoDirectPayment:           { modern: null,                                               confidence: "unmapped",   note: "Card payments need PayPal Advanced Checkout (card fields); not an automatic one-to-one move. Human decision." },
  CreateBillingAgreement:    { modern: null,                                               confidence: "unmapped",   note: "Reference transactions / billing agreements map to Vault + Subscriptions: human decision." },
};

// request parameters (NVP -> Orders v2 JSON path)
export const NVP_PARAMS = {
  PAYMENTREQUEST_0_AMT:          { modern: "purchase_units[0].amount.value",                           confidence: "documented" },
  PAYMENTREQUEST_0_CURRENCYCODE: { modern: "purchase_units[0].amount.currency_code",                   confidence: "documented" },
  PAYMENTREQUEST_0_ITEMAMT:      { modern: "purchase_units[0].amount.breakdown.item_total.value",      confidence: "inferred" },
  PAYMENTREQUEST_0_PAYMENTACTION:{ modern: "intent (Sale -> CAPTURE, Authorization -> AUTHORIZE)",     confidence: "inferred" },
  RETURNURL:                     { modern: "payment_source.paypal.experience_context.return_url",      confidence: "inferred" },
  CANCELURL:                     { modern: "payment_source.paypal.experience_context.cancel_url",      confidence: "inferred" },
  NOSHIPPING:                    { modern: "payment_source.paypal.experience_context.shipping_preference = NO_SHIPPING", confidence: "inferred" },
  LOCALECODE:                    { modern: "payment_source.paypal.experience_context.locale",          confidence: "inferred" },
  TOKEN:                         { modern: "order id",                                                 confidence: "documented" },
  PAYERID:                       { modern: "(not needed: the buyer approval is bound to the order)",   confidence: "inferred" },
  TRANSACTIONID:                 { modern: "capture id",                                               confidence: "inferred" },
  REFUNDTYPE:                    { modern: "refund request body (Full = empty body)",                  confidence: "inferred" },
  // credentials / protocol fields: replaced by OAuth2 client credentials
  USER:                          { modern: "OAuth2 client id (client credentials)",                    confidence: "documented" },
  PWD:                           { modern: "OAuth2 client secret",                                     confidence: "documented" },
  SIGNATURE:                     { modern: "(removed: OAuth2 bearer token replaces it)",               confidence: "documented" },
  VERSION:                       { modern: "(removed: REST is versioned in the path)",                 confidence: "documented" },
  METHOD:                        { modern: "(removed: the URL and HTTP verb select the call)",         confidence: "documented" },
};
// repeated line-item params: L_PAYMENTREQUEST_0_NAME<n>, _AMT<n>, _QTY<n>
export const NVP_LINE_ITEM = {
  NAME: { modern: "purchase_units[0].items[n].name",              confidence: "inferred" },
  AMT:  { modern: "purchase_units[0].items[n].unit_amount.value", confidence: "inferred" },
  QTY:  { modern: "purchase_units[0].items[n].quantity",          confidence: "inferred" },
};

// response fields read by the legacy code
export const NVP_RESPONSE = {
  ACK:                           { modern: "HTTP status + body.status / body.name", confidence: "inferred" },
  TOKEN:                         { modern: "body.id (order id)", confidence: "documented" },
  EMAIL:                         { modern: "body.payer.email_address", confidence: "inferred" },
  PAYMENTINFO_0_TRANSACTIONID:   { modern: "body.purchase_units[0].payments.captures[0].id", confidence: "inferred" },
  REFUNDTRANSACTIONID:           { modern: "refund body.id", confidence: "inferred" },
  L_LONGMESSAGE0:                { modern: "body.details[0].description / body.message", confidence: "inferred" },
};

export const IPN_TO_WEBHOOK = {
  Completed: { modern: "PAYMENT.CAPTURE.COMPLETED", confidence: "documented" },
  Refunded:  { modern: "PAYMENT.CAPTURE.REFUNDED",  confidence: "inferred" },
  Reversed:  { modern: "PAYMENT.CAPTURE.REVERSED",  confidence: "inferred" },
};
