<?php

namespace console\controllers;

use common\models\mall\Order;
use common\models\mall\OrderProduct;
use common\models\mall\PaymentAttempt;
use common\models\mall\Product;
use Yii;
use yii\console\ExitCode;

class MallPaymentTestController extends BaseController
{
    public $storeId = 5;
    public $baseUrl = 'http://127.0.0.1:8089';
    public $userId = 71;
    public $productIds = '90,102';
    public $amount = '1.00';
    public $qpayCallbackHmacSecret = '';
    public $lianlianCallbackHmacSecret = '';
    public $qpayCallbackMaxAgeSeconds = 0;
    public $lianlianCallbackMaxAgeSeconds = 0;

    private $failures = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'userId',
            'productIds',
            'amount',
            'qpayCallbackHmacSecret',
            'lianlianCallbackHmacSecret',
            'qpayCallbackMaxAgeSeconds',
            'lianlianCallbackMaxAgeSeconds',
        ]);
    }

    public function actionRun()
    {
        $products = $this->loadProducts();
        if (count($products) < 2) {
            $this->stderr("Need at least two active products for regression.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Payment callback regression against {$this->baseUrl}\n");

        $this->runSuccessfulCallback($products);
        $this->runAmountMismatch($products);
        $this->runRefundCannotRevive($products);
        $this->runMissingMerchantTransaction($products);
        $this->runWrongMerchantTransaction($products);
        $this->runQpayHmacProtection($products);
        $this->runQpayTimestampProtection($products);
        $this->runLianlianSuccessfulCallback($products);
        $this->runLianlianAmountMismatch($products);
        $this->runLianlianHmacProtection($products);
        $this->runLianlianTimestampProtection($products);
        $this->runRefundStockRegression($products);
        $this->runInvalidRefundRegression($products);
        $this->runShipmentReceiveRegression($products);
        $this->runInvalidShipmentRegression($products);

        if ($this->failures) {
            $this->stderr("\nFailed checks:\n");
            foreach ($this->failures as $failure) {
                $this->stderr("- {$failure}\n");
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nAll payment callback regression checks passed.\n");
        return ExitCode::OK;
    }

    private function runSuccessfulCallback(array $products)
    {
        [$parent, $children] = $this->createOrderSet($products, 'REGPAY-SUCCESS');
        $stockBefore = $this->productStocks($products);

        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => 'REG-GW-SUCCESS-' . $parent->id,
        ]);
        $first = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($first, 200, 'successful callback returns 200');
        $this->assertBody($first, 'SUCCESS', 'successful callback returns SUCCESS');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $parent->payment_status, "parent order {$parent->id} is paid");
        $this->assertGreaterThan(0, $parent->stock_deducted_at, "parent order {$parent->id} stock timestamp set");
        foreach ($children as $child) {
            $child->refresh();
            $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $child->payment_status, "child order {$child->id} is paid");
            $this->assertGreaterThan(0, $child->stock_deducted_at, "child order {$child->id} stock timestamp set");
        }

        $stockAfterFirst = $this->productStocks($products);
        $this->assertStockDelta($stockBefore, $stockAfterFirst, -1, 'successful callback deducts each product once');

        $second = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($second, 200, 'duplicate successful callback returns 200');
        $this->assertBody($second, 'SUCCESS', 'duplicate successful callback returns SUCCESS');
        $stockAfterSecond = $this->productStocks($products);
        $this->assertStockDelta($stockAfterFirst, $stockAfterSecond, 0, 'duplicate callback does not deduct stock again');

        $ignoredAttempts = PaymentAttempt::find()->where([
            'order_id' => $parent->id,
            'provider' => 'qpay',
            'event' => 'callback',
            'merchant_transaction_id' => '1234567' . $parent->id,
            'result' => PaymentAttempt::RESULT_IGNORED,
        ])->count();
        $this->assertGreaterThan(0, $ignoredAttempts, "duplicate callback is audited as ignored for order {$parent->id}");

        $badDuplicatePayload = $this->qpayPayload($parent, [
            'amount' => number_format(max(0.01, (float)$this->amount - 0.10), 2, '.', ''),
            'gateway_transaction_id' => 'REG-GW-SUCCESS-BAD-DUP-' . $parent->id,
        ]);
        $badDuplicate = $this->postQpayCallback($parent->id, $badDuplicatePayload);
        $this->assertHttp($badDuplicate, 400, 'paid duplicate with amount mismatch returns 400');
        $this->assertBody($badDuplicate, 'FAIL', 'paid duplicate with amount mismatch returns FAIL');
        $this->assertStockDelta($stockAfterSecond, $this->productStocks($products), 0, 'paid duplicate amount mismatch does not change stock');
        $this->assertFailedAttempt($parent->id, 'Payment amount mismatch', 'paid duplicate amount mismatch is audited');

        $this->stdout("PASS success+duplicate callback: parent={$parent->id}\n");
    }

    private function runAmountMismatch(array $products)
    {
        [$parent] = $this->createOrderSet($products, 'REGPAY-AMOUNT');
        $stockBefore = $this->productStocks($products);

        $payload = $this->qpayPayload($parent, [
            'amount' => number_format(max(0.01, (float)$this->amount - 0.10), 2, '.', ''),
            'gateway_transaction_id' => 'REG-GW-AMOUNT-' . $parent->id,
        ]);
        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 400, 'amount mismatch callback returns 400');
        $this->assertBody($response, 'FAIL', 'amount mismatch callback returns FAIL');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "amount mismatch keeps parent {$parent->id} unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'amount mismatch does not deduct stock');
        $this->assertFailedAttempt($parent->id, 'Payment amount mismatch', 'amount mismatch is audited');

        $this->stdout("PASS amount mismatch rejected: parent={$parent->id}\n");
    }

    private function runRefundCannotRevive(array $products)
    {
        [$parent, $children] = $this->createOrderSet($products, 'REGPAY-REFUND');
        $now = time();
        Order::updateAll([
            'payment_status' => Order::PAYMENT_STATUS_REFUND,
            'status' => Order::PAYMENT_STATUS_REFUND,
            'paid_at' => $now,
            'stock_deducted_at' => $now,
            'stock_refunded_at' => $now,
        ], ['id' => array_merge([$parent->id], array_map(function ($child) {
            return $child->id;
        }, $children))]);

        $stockBefore = $this->productStocks($products);
        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => 'REG-GW-REFUND-' . $parent->id,
        ]);
        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 400, 'refund callback returns 400');
        $this->assertBody($response, 'FAIL', 'refund callback returns FAIL');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_REFUND, $parent->payment_status, "refund order {$parent->id} is not revived");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'refund callback does not deduct stock');
        $this->assertFailedAttempt($parent->id, 'Order payment status cannot be marked paid', 'refund callback is audited');

        $this->stdout("PASS refund callback blocked: parent={$parent->id}\n");
    }

    private function runMissingMerchantTransaction(array $products)
    {
        [$parent] = $this->createOrderSet($products, 'REGPAY-MISSING-MID');
        $payload = $this->qpayPayload($parent, [
            'merchant_transaction_id' => '',
            'gateway_transaction_id' => 'REG-GW-MISSING-' . $parent->id,
        ]);
        unset($payload['merchant_transaction_id']);

        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 400, 'missing merchant transaction callback returns 400');
        $this->assertBody($response, 'FAIL', 'missing merchant transaction callback returns FAIL');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "missing merchant id keeps parent {$parent->id} unpaid");
        $this->assertFailedAttempt($parent->id, 'Merchant transaction id is required', 'missing merchant id is audited');

        $this->stdout("PASS missing merchant id rejected: parent={$parent->id}\n");
    }

    private function runWrongMerchantTransaction(array $products)
    {
        [$parent] = $this->createOrderSet($products, 'REGPAY-WRONG-MID');
        $payload = $this->qpayPayload($parent, [
            'merchant_transaction_id' => 'WRONG-' . $parent->id,
            'gateway_transaction_id' => 'REG-GW-WRONG-' . $parent->id,
        ]);

        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 400, 'wrong merchant transaction callback returns 400');
        $this->assertBody($response, 'FAIL', 'wrong merchant transaction callback returns FAIL');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "wrong merchant id keeps parent {$parent->id} unpaid");
        $this->assertFailedAttempt($parent->id, 'Merchant transaction id mismatch', 'wrong merchant id is audited');

        $this->stdout("PASS wrong merchant id rejected: parent={$parent->id}\n");
    }

    private function runQpayHmacProtection(array $products)
    {
        if ($this->callbackHmacSecret('qpay') === '') {
            $this->stdout("WARN QPay HMAC protection checks skipped; QPAY_CALLBACK_HMAC_SECRET is empty.\n");
            return;
        }

        [$parent] = $this->createOrderSet($products, 'REGPAY-HMAC-QPAY');
        $stockBefore = $this->productStocks($products);
        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => 'REG-GW-HMAC-QPAY-' . $parent->id,
        ]);

        $missing = $this->postQpayCallback($parent->id, $payload, false);
        $this->assertHttp($missing, 400, 'QPay missing HMAC callback returns 400');
        $this->assertBody($missing, 'FAIL', 'QPay missing HMAC callback returns FAIL');
        $this->assertFailedAttempt($parent->id, 'Payment callback signature is required', 'QPay missing HMAC is audited');

        $invalid = $this->postQpayCallback($parent->id, $payload, true, 'sha256=bad-signature');
        $this->assertHttp($invalid, 400, 'QPay invalid HMAC callback returns 400');
        $this->assertBody($invalid, 'FAIL', 'QPay invalid HMAC callback returns FAIL');
        $this->assertFailedAttempt($parent->id, 'Invalid payment callback signature', 'QPay invalid HMAC is audited');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "QPay HMAC-protected parent {$parent->id} stays unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'QPay HMAC rejection does not deduct stock');

        $this->stdout("PASS QPay HMAC callback protection: parent={$parent->id}\n");
    }

    private function runQpayTimestampProtection(array $products)
    {
        $maxAge = $this->callbackMaxAgeSeconds('qpay');
        if ($maxAge <= 0) {
            $this->stdout("WARN QPay timestamp protection checks skipped; QPAY_CALLBACK_MAX_AGE_SECONDS is disabled.\n");
            return;
        }

        [$parent] = $this->createOrderSet($products, 'REGPAY-TIME-QPAY');
        $stockBefore = $this->productStocks($products);
        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => 'REG-GW-TIME-QPAY-' . $parent->id,
        ]);
        $expiredTimestamp = time() - $maxAge - 10;

        $response = $this->postQpayCallback($parent->id, $payload, true, null, $expiredTimestamp);
        $this->assertHttp($response, 400, 'QPay expired timestamp callback returns 400');
        $this->assertBody($response, 'FAIL', 'QPay expired timestamp callback returns FAIL');
        $this->assertFailedAttempt($parent->id, 'Payment callback timestamp expired', 'QPay expired timestamp is audited');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "QPay expired timestamp parent {$parent->id} stays unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'QPay expired timestamp rejection does not deduct stock');

        $this->stdout("PASS QPay timestamp callback protection: parent={$parent->id}\n");
    }

    private function runLianlianSuccessfulCallback(array $products)
    {
        [$parent, $children] = $this->createOrderSet($products, 'REGPAY-LL-SUCCESS');
        $stockBefore = $this->productStocks($products);

        $payload = $this->lianlianPayload($parent, [
            'gateway_transaction_id' => 'REG-LL-GW-SUCCESS-' . $parent->id,
        ]);
        $first = $this->postLianlianCallback($parent->id, $payload);
        $this->assertHttp($first, 200, 'LianLian successful callback returns 200');
        $this->assertBody($first, 'success', 'LianLian successful callback returns success');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $parent->payment_status, "LianLian parent order {$parent->id} is paid");
        foreach ($children as $child) {
            $child->refresh();
            $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $child->payment_status, "LianLian child order {$child->id} is paid");
        }

        $stockAfterFirst = $this->productStocks($products);
        $this->assertStockDelta($stockBefore, $stockAfterFirst, -1, 'LianLian callback deducts each product once');

        $second = $this->postLianlianCallback($parent->id, $payload);
        $this->assertHttp($second, 200, 'LianLian duplicate callback returns 200');
        $this->assertBody($second, 'success', 'LianLian duplicate callback returns success');
        $this->assertStockDelta($stockAfterFirst, $this->productStocks($products), 0, 'LianLian duplicate callback does not deduct stock again');

        $ignoredAttempts = PaymentAttempt::find()->where([
            'order_id' => $parent->id,
            'provider' => 'lianlian',
            'event' => 'callback',
            'merchant_transaction_id' => 'Test-pay' . $parent->id,
            'result' => PaymentAttempt::RESULT_IGNORED,
        ])->count();
        $this->assertGreaterThan(0, $ignoredAttempts, "LianLian duplicate callback is audited as ignored for order {$parent->id}");

        $badDuplicatePayload = $this->lianlianPayload($parent, [
            'amount' => number_format(max(0.01, (float)$this->amount - 0.10), 2, '.', ''),
            'gateway_transaction_id' => 'REG-LL-GW-SUCCESS-BAD-DUP-' . $parent->id,
        ]);
        $badDuplicate = $this->postLianlianCallback($parent->id, $badDuplicatePayload);
        $this->assertHttp($badDuplicate, 400, 'LianLian paid duplicate with amount mismatch returns 400');
        $this->assertBody($badDuplicate, 'fail', 'LianLian paid duplicate with amount mismatch returns fail');
        $this->assertStockDelta($stockAfterFirst, $this->productStocks($products), 0, 'LianLian paid duplicate amount mismatch does not change stock');
        $this->assertFailedAttempt($parent->id, 'Payment amount mismatch', 'LianLian paid duplicate amount mismatch is audited', 'lianlian');

        $this->stdout("PASS LianLian success+duplicate callback: parent={$parent->id}\n");
    }

    private function runLianlianAmountMismatch(array $products)
    {
        [$parent] = $this->createOrderSet($products, 'REGPAY-LL-AMOUNT');
        $stockBefore = $this->productStocks($products);

        $payload = $this->lianlianPayload($parent, [
            'amount' => number_format(max(0.01, (float)$this->amount - 0.10), 2, '.', ''),
            'gateway_transaction_id' => 'REG-LL-GW-AMOUNT-' . $parent->id,
        ]);
        $response = $this->postLianlianCallback($parent->id, $payload);
        $this->assertHttp($response, 400, 'LianLian amount mismatch callback returns 400');
        $this->assertBody($response, 'fail', 'LianLian amount mismatch callback returns fail');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "LianLian amount mismatch keeps parent {$parent->id} unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'LianLian amount mismatch does not deduct stock');
        $this->assertFailedAttempt($parent->id, 'Payment amount mismatch', 'LianLian amount mismatch is audited', 'lianlian');

        $this->stdout("PASS LianLian amount mismatch rejected: parent={$parent->id}\n");
    }

    private function runLianlianHmacProtection(array $products)
    {
        if ($this->callbackHmacSecret('lianlian') === '') {
            $this->stdout("WARN LianLian HMAC protection checks skipped; LIANLIAN_CALLBACK_HMAC_SECRET is empty.\n");
            return;
        }

        [$parent] = $this->createOrderSet($products, 'REGPAY-HMAC-LL');
        $stockBefore = $this->productStocks($products);
        $payload = $this->lianlianPayload($parent, [
            'gateway_transaction_id' => 'REG-LL-GW-HMAC-' . $parent->id,
        ]);

        $missing = $this->postLianlianCallback($parent->id, $payload, false);
        $this->assertHttp($missing, 400, 'LianLian missing HMAC callback returns 400');
        $this->assertBody($missing, 'fail', 'LianLian missing HMAC callback returns fail');
        $this->assertFailedAttempt($parent->id, 'Payment callback signature is required', 'LianLian missing HMAC is audited', 'lianlian');

        $invalid = $this->postLianlianCallback($parent->id, $payload, true, 'sha256=bad-signature');
        $this->assertHttp($invalid, 400, 'LianLian invalid HMAC callback returns 400');
        $this->assertBody($invalid, 'fail', 'LianLian invalid HMAC callback returns fail');
        $this->assertFailedAttempt($parent->id, 'Invalid payment callback signature', 'LianLian invalid HMAC is audited', 'lianlian');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "LianLian HMAC-protected parent {$parent->id} stays unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'LianLian HMAC rejection does not deduct stock');

        $this->stdout("PASS LianLian HMAC callback protection: parent={$parent->id}\n");
    }

    private function runLianlianTimestampProtection(array $products)
    {
        $maxAge = $this->callbackMaxAgeSeconds('lianlian');
        if ($maxAge <= 0) {
            $this->stdout("WARN LianLian timestamp protection checks skipped; LIANLIAN_CALLBACK_MAX_AGE_SECONDS is disabled.\n");
            return;
        }

        [$parent] = $this->createOrderSet($products, 'REGPAY-TIME-LL');
        $stockBefore = $this->productStocks($products);
        $payload = $this->lianlianPayload($parent, [
            'gateway_transaction_id' => 'REG-LL-GW-TIME-' . $parent->id,
        ]);
        $expiredTimestamp = time() - $maxAge - 10;

        $response = $this->postLianlianCallback($parent->id, $payload, true, null, $expiredTimestamp);
        $this->assertHttp($response, 400, 'LianLian expired timestamp callback returns 400');
        $this->assertBody($response, 'fail', 'LianLian expired timestamp callback returns fail');
        $this->assertFailedAttempt($parent->id, 'Payment callback timestamp expired', 'LianLian expired timestamp is audited', 'lianlian');

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $parent->payment_status, "LianLian expired timestamp parent {$parent->id} stays unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'LianLian expired timestamp rejection does not deduct stock');

        $this->stdout("PASS LianLian timestamp callback protection: parent={$parent->id}\n");
    }

    private function runRefundStockRegression(array $products)
    {
        [$parent, $children] = $this->createOrderSet($products, 'REGPAY-REFUND-STOCK');
        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => 'REG-GW-REFUND-STOCK-' . $parent->id,
        ]);
        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 200, 'refund stock setup payment returns 200');

        $stockAfterPaid = $this->productStocks($products);
        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $parent->payment_status, "refund stock setup parent {$parent->id} is paid");

        try {
            $parent->markRefunded();
        } catch (\Throwable $e) {
            $this->fail("paid parent {$parent->id} can be refunded: {$e->getMessage()}");
        }

        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_REFUND, $parent->payment_status, "refunded parent {$parent->id} status set");
        $this->assertGreaterThan(0, $parent->stock_refunded_at, "refunded parent {$parent->id} stock refund timestamp set");
        foreach ($children as $child) {
            $child->refresh();
            $this->assertSameInt(Order::PAYMENT_STATUS_REFUND, $child->payment_status, "refunded child {$child->id} status set");
            $this->assertGreaterThan(0, $child->stock_refunded_at, "refunded child {$child->id} stock refund timestamp set");
        }
        $stockAfterRefund = $this->productStocks($products);
        $this->assertStockDelta($stockAfterPaid, $stockAfterRefund, 1, 'refund returns each product stock once');

        try {
            $parent->markRefunded();
        } catch (\Throwable $e) {
            $this->fail("refunded parent {$parent->id} can be safely refunded again: {$e->getMessage()}");
        }
        $this->assertStockDelta($stockAfterRefund, $this->productStocks($products), 0, 'duplicate refund does not return stock again');

        $this->stdout("PASS refund returns stock once: parent={$parent->id}\n");
    }

    private function runInvalidRefundRegression(array $products)
    {
        [$unpaidParent, $unpaidChildren] = $this->createOrderSet($products, 'REGPAY-REFUND-UNPAID');
        $stockBefore = $this->productStocks($products);

        try {
            $unpaidParent->markRefunded();
            $this->fail("unpaid parent {$unpaidParent->id} refund should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Only paid orders can be refunded', $e->getMessage(), "unpaid parent {$unpaidParent->id} refund rejected");
        }
        $unpaidParent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $unpaidParent->payment_status, "unpaid parent {$unpaidParent->id} stays unpaid");
        $this->assertStockDelta($stockBefore, $this->productStocks($products), 0, 'unpaid refund does not change stock');

        $child = $unpaidChildren[0];
        try {
            $child->markRefunded();
            $this->fail("child order {$child->id} refund should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Please refund the parent order', $e->getMessage(), "child order {$child->id} refund rejected");
        }
        $child->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_UNPAID, $child->payment_status, "child order {$child->id} stays unpaid");

        $this->stdout("PASS invalid refund paths rejected: parent={$unpaidParent->id}, child={$child->id}\n");
    }

    private function runShipmentReceiveRegression(array $products)
    {
        [$parent, $children] = $this->createOrderSet($products, 'REGPAY-SHIP-RECEIVE');
        $this->payOrderByQpay($parent, 'REG-GW-SHIP-RECEIVE-' . $parent->id);

        try {
            $parent->markShipped(9001, 'Codex Express');
            $parent->markShipped(9001, 'Codex Express');
        } catch (\Throwable $e) {
            $this->fail("paid parent {$parent->id} can be shipped idempotently: {$e->getMessage()}");
        }

        $parent->refresh();
        $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, $parent->shipment_status, "shipped parent {$parent->id} status set");
        $this->assertGreaterThan(0, $parent->shipped_at, "shipped parent {$parent->id} shipped_at set");
        foreach ($children as $child) {
            $child->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, $child->shipment_status, "shipped child {$child->id} status set");
            $this->assertSameInt(9001, $child->shipment_id, "shipped child {$child->id} shipment id set");
        }

        try {
            $children[0]->markReceived();
        } catch (\Throwable $e) {
            $this->fail("first child {$children[0]->id} can be received: {$e->getMessage()}");
        }
        $children[0]->refresh();
        $children[1]->refresh();
        $parent->refresh();
        $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, $children[0]->shipment_status, "first child {$children[0]->id} received");
        $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, $children[1]->shipment_status, "second child {$children[1]->id} still shipping");
        $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, $parent->shipment_status, "parent {$parent->id} stays shipping until all children received");

        try {
            $children[1]->markReceived();
            $children[1]->markReceived();
        } catch (\Throwable $e) {
            $this->fail("second child {$children[1]->id} can be received idempotently: {$e->getMessage()}");
        }
        $children[1]->refresh();
        $parent->refresh();
        $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, $children[1]->shipment_status, "second child {$children[1]->id} received");
        $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, $parent->shipment_status, "parent {$parent->id} received after all children");

        try {
            $parent->markReceived();
        } catch (\Throwable $e) {
            $this->fail("received parent {$parent->id} can be confirmed again: {$e->getMessage()}");
        }

        $this->stdout("PASS shipment and receive lifecycle: parent={$parent->id}\n");
    }

    private function runInvalidShipmentRegression(array $products)
    {
        [$unpaidParent] = $this->createOrderSet($products, 'REGPAY-SHIP-UNPAID');
        try {
            $unpaidParent->markShipped();
            $this->fail("unpaid parent {$unpaidParent->id} shipping should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Only paid orders can be shipped', $e->getMessage(), "unpaid parent {$unpaidParent->id} shipping rejected");
        }
        try {
            $unpaidParent->markReceived();
            $this->fail("unshipped parent {$unpaidParent->id} receive should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Only shipped orders can be received', $e->getMessage(), "unshipped parent {$unpaidParent->id} receive rejected");
        }

        [$refundedParent] = $this->createOrderSet($products, 'REGPAY-SHIP-REFUND');
        $this->payOrderByQpay($refundedParent, 'REG-GW-SHIP-REFUND-' . $refundedParent->id);
        try {
            $refundedParent->markRefunded();
        } catch (\Throwable $e) {
            $this->fail("paid parent {$refundedParent->id} can be refunded before invalid shipment checks: {$e->getMessage()}");
        }
        try {
            $refundedParent->markShipped();
            $this->fail("refunded parent {$refundedParent->id} shipping should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Refunded orders cannot be shipped', $e->getMessage(), "refunded parent {$refundedParent->id} shipping rejected");
        }
        try {
            $refundedParent->markReceived();
            $this->fail("refunded parent {$refundedParent->id} receive should be rejected");
        } catch (\Throwable $e) {
            $this->assertContains('Invalid id', $e->getMessage(), "refunded parent {$refundedParent->id} receive rejected");
        }

        $this->stdout("PASS invalid shipment paths rejected: unpaid={$unpaidParent->id}, refunded={$refundedParent->id}\n");
    }

    private function createOrderSet(array $products, $prefix)
    {
        $sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $parent = $this->createOrder(0, (int)$this->storeId, $sn, (float)$this->amount, count($products));
            $children = [];
            $childAmount = round((float)$this->amount / count($products), 2);
            $remaining = (float)$this->amount;

            foreach (array_values($products) as $index => $product) {
                $amount = $index === count($products) - 1 ? $remaining : $childAmount;
                $remaining = round($remaining - $amount, 2);

                $child = $this->createOrder($parent->id, (int)$product->store_id, $sn, $amount, 1);
                $this->createOrderProduct($child, $product, $amount);
                $children[] = $child;
            }

            $transaction->commit();
            return [$parent, $children];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function payOrderByQpay(Order $parent, $gatewayTransactionId)
    {
        $payload = $this->qpayPayload($parent, [
            'gateway_transaction_id' => $gatewayTransactionId,
        ]);
        $response = $this->postQpayCallback($parent->id, $payload);
        $this->assertHttp($response, 200, "setup payment for parent {$parent->id} returns 200");
        $this->assertBody($response, 'SUCCESS', "setup payment for parent {$parent->id} returns SUCCESS");
        $parent->refresh();
        $this->assertSameInt(Order::PAYMENT_STATUS_PAID, $parent->payment_status, "setup parent {$parent->id} is paid");
    }

    private function createOrder($parentId, $storeId, $sn, $amount, $number)
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = $parentId;
        $order->user_id = (int)$this->userId;
        $order->address_id = 0;
        $order->name = 'Mongoyia payment regression';
        $order->sn = $sn;
        $order->first_name = 'Codex';
        $order->last_name = 'Regression';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local payment regression';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_order_test@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mall-payment-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_UNPAID;
        $order->paid_at = 0;
        $order->stock_deducted_at = 0;
        $order->stock_refunded_at = 0;
        $order->shipment_id = 0;
        $order->shipment_name = '';
        $order->shipment_fee = 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_UNSHIPPED;
        $order->shipped_at = 0;
        $order->product_amount = $amount;
        $order->amount = $amount;
        $order->number = $number;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = Order::PAYMENT_STATUS_UNPAID;

        if (!$order->save()) {
            throw new \RuntimeException('Create order failed: ' . json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function createOrderProduct(Order $order, Product $product, $price)
    {
        $orderProduct = new OrderProduct();
        $orderProduct->store_id = (int)$product->store_id;
        $orderProduct->parent_id = 0;
        $orderProduct->user_id = (int)$this->userId;
        $orderProduct->order_id = $order->id;
        $orderProduct->product_id = $product->id;
        $orderProduct->product_attribute_value = '';
        $orderProduct->name = $product->name;
        $orderProduct->sku = $product->sku;
        $orderProduct->number = 1;
        $orderProduct->price = $price;
        $orderProduct->market_price = $price;
        $orderProduct->cost_price = 0;
        $orderProduct->wholesale_price = 0;
        $orderProduct->thumb = $product->thumb;
        $orderProduct->cart_id = 0;
        $orderProduct->type = OrderProduct::TYPE_DEFAULT;
        $orderProduct->sort = OrderProduct::SORT_DEFAULT;
        $orderProduct->status = OrderProduct::STATUS_ACTIVE;

        if (!$orderProduct->save()) {
            throw new \RuntimeException('Create order product failed: ' . json_encode($orderProduct->errors, JSON_UNESCAPED_UNICODE));
        }

        return $orderProduct;
    }

    private function qpayPayload(Order $order, array $overrides = [])
    {
        return array_merge([
            'payment_status' => 'PS',
            'amount' => number_format((float)$order->amount, 2, '.', ''),
            'merchant_transaction_id' => '1234567' . $order->id,
            'gateway_transaction_id' => 'REG-GW-' . $order->id,
        ], $overrides);
    }

    private function lianlianPayload(Order $order, array $overrides = [])
    {
        $amount = $overrides['amount'] ?? number_format((float)$order->amount, 2, '.', '');
        $merchantTransactionId = $overrides['merchant_transaction_id'] ?? ('Test-pay' . $order->id);
        $gatewayTransactionId = $overrides['gateway_transaction_id'] ?? ('REG-LL-GW-' . $order->id);
        $status = $overrides['payment_status'] ?? 'PS';

        return [
            'payment' => [
                'status' => $status,
                'transaction_id' => $gatewayTransactionId,
            ],
            'merchant_order' => [
                'merchant_transaction_id' => $merchantTransactionId,
                'order_amount' => $amount,
            ],
        ];
    }

    private function postQpayCallback($orderId, array $payload, $sign = true, $signatureOverride = null, $timestampOverride = null)
    {
        $url = rtrim($this->baseUrl, '/') . '/mall/payment/qpayres?id=' . urlencode($orderId);
        $body = http_build_query($payload);
        $headers = $this->callbackHeaders('qpay', $orderId, $payload, null, $sign, $signatureOverride, $timestampOverride, 'application/x-www-form-urlencoded');
        return $this->postCallback($url, $body, $headers);
    }

    private function postLianlianCallback($orderId, array $payload, $sign = true, $signatureOverride = null, $timestampOverride = null)
    {
        $url = rtrim($this->baseUrl, '/') . '/mall/payment/succeeded?id=' . urlencode($orderId);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = $this->callbackHeaders('lianlian', $orderId, [], $payload, $sign, $signatureOverride, $timestampOverride, 'application/json');
        return $this->postCallback($url, $body, $headers);
    }

    private function callbackHeaders($provider, $orderId, array $post, ?array $raw, $sign, $signatureOverride, $timestampOverride, $contentType)
    {
        $headers = "Content-Type: {$contentType}\r\n";
        $secret = $this->callbackHmacSecret($provider);
        $maxAge = $this->callbackMaxAgeSeconds($provider);
        if ($secret === '' && $maxAge <= 0) {
            return $headers;
        }

        $timestamp = (string)($timestampOverride ?: time());
        $headers .= "X-Mongoyia-Payment-Timestamp: {$timestamp}\r\n";
        if ($secret !== '' && $sign) {
            $signature = $signatureOverride ?: ('sha256=' . $this->callbackSignature($secret, ['id' => (string)$orderId], $post, $raw, $timestamp));
            $headers .= "X-Mongoyia-Payment-Signature: {$signature}\r\n";
        }

        return $headers;
    }

    private function callbackHmacSecret($provider)
    {
        if ($provider === 'qpay') {
            return (string)($this->qpayCallbackHmacSecret ?: env('QPAY_CALLBACK_HMAC_SECRET', ''));
        }

        return (string)($this->lianlianCallbackHmacSecret ?: env('LIANLIAN_CALLBACK_HMAC_SECRET', ''));
    }

    private function callbackMaxAgeSeconds($provider)
    {
        if ($provider === 'qpay') {
            return (int)($this->qpayCallbackMaxAgeSeconds ?: env('QPAY_CALLBACK_MAX_AGE_SECONDS', 0));
        }

        return (int)($this->lianlianCallbackMaxAgeSeconds ?: env('LIANLIAN_CALLBACK_MAX_AGE_SECONDS', 0));
    }

    private function callbackSignature($secret, array $get, array $post, ?array $raw, $timestamp)
    {
        $payload = [
            'get' => $get,
            'post' => $post,
            'headers' => ['timestamp' => (string)$timestamp],
        ];
        if (is_array($raw)) {
            $payload['raw'] = $raw;
        }

        $this->removePaymentSignatureFields($payload);
        $this->ksortRecursive($payload);

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
    }

    private function removePaymentSignatureFields(array &$payload)
    {
        foreach (['signature', 'sign', 'hmac', 'x_signature', 'callback_secret', 'token'] as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->removePaymentSignatureFields($value);
            }
        }
        unset($value);
    }

    private function ksortRecursive(array &$payload)
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
    }

    private function postCallback($url, $body, $headers)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        if ($content === false) {
            $content = '';
        }

        return [
            'status' => $status,
            'body' => trim($content),
            'url' => $url,
        ];
    }

    private function loadProducts()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        $products = Product::find()
            ->where(['id' => $ids, 'status' => Product::STATUS_ACTIVE])
            ->andWhere(['>', 'stock', 10])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        if (count($products) >= 2) {
            return $products;
        }

        $this->stdout("WARN configured payment products are unavailable or low-stock; selecting active high-stock fallback products.\n");
        $fallback = Product::find()
            ->where(['status' => Product::STATUS_ACTIVE])
            ->andWhere(['>', 'stock', 10])
            ->orderBy(['stock' => SORT_DESC, 'id' => SORT_ASC])
            ->all();

        $selected = [];
        $storeIds = [];
        foreach ($fallback as $product) {
            if (isset($storeIds[$product->store_id])) {
                continue;
            }
            $selected[] = $product;
            $storeIds[$product->store_id] = true;
            if (count($selected) >= 2) {
                return $selected;
            }
        }

        return array_slice($fallback, 0, 2);
    }

    private function productStocks(array $products)
    {
        $stocks = [];
        foreach ($products as $product) {
            $stocks[$product->id] = (int)Product::find()->select('stock')->where(['id' => $product->id])->scalar();
        }
        return $stocks;
    }

    private function assertHttp(array $response, $expected, $label)
    {
        if ((int)$response['status'] !== (int)$expected) {
            $this->fail($label . " expected HTTP {$expected}, got {$response['status']} from {$response['url']} body={$response['body']}");
        }
    }

    private function assertBody(array $response, $expected, $label)
    {
        if (strtoupper($response['body']) !== strtoupper($expected)) {
            $this->fail($label . " expected body {$expected}, got {$response['body']}");
        }
    }

    private function assertSameInt($expected, $actual, $label)
    {
        if ((int)$expected !== (int)$actual) {
            $this->fail($label . " expected {$expected}, got {$actual}");
        }
    }

    private function assertGreaterThan($threshold, $actual, $label)
    {
        if ((float)$actual <= (float)$threshold) {
            $this->fail($label . " expected greater than {$threshold}, got {$actual}");
        }
    }

    private function assertContains($needle, $haystack, $label)
    {
        if (strpos((string)$haystack, (string)$needle) === false) {
            $this->fail($label . " expected message containing '{$needle}', got '{$haystack}'");
        }
    }

    private function assertStockDelta(array $before, array $after, $expectedDelta, $label)
    {
        foreach ($before as $productId => $stock) {
            $delta = ((int)$after[$productId]) - ((int)$stock);
            if ($delta !== (int)$expectedDelta) {
                $this->fail("{$label} product={$productId} expected delta {$expectedDelta}, got {$delta}");
            }
        }
    }

    private function assertFailedAttempt($orderId, $errorMessage, $label, $provider = 'qpay')
    {
        $exists = PaymentAttempt::find()
            ->where([
                'order_id' => $orderId,
                'provider' => $provider,
                'event' => 'callback',
                'result' => PaymentAttempt::RESULT_FAILED,
            ])
            ->andWhere(['like', 'error_message', $errorMessage])
            ->exists();

        if (!$exists) {
            $this->fail($label . " expected failed audit containing '{$errorMessage}'");
        }
    }

    private function fail($message)
    {
        $this->failures[] = $message;
        $this->stderr("FAIL {$message}\n");
    }
}
