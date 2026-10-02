<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craftcommercetests\unit\elements\order;

use Codeception\Test\Unit;
use Craft;
use craft\base\Event;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\Coupon;
use craft\commerce\models\Discount;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Discounts;
use craft\db\Query;
use craftcommercetests\fixtures\OrderStatusesFixture;
use craftcommercetests\fixtures\ProductFixture;
use RuntimeException;
use UnitTester;

class OrderCompletionConcurrencyTest extends Unit
{
    protected UnitTester $tester;

    private Plugin $_commerce;
    private Order $_order;
    private Discount $_discount;
    private Coupon $_coupon;

    public function _fixtures(): array
    {
        return [
            'products' => ProductFixture::class,
            'orderStatuses' => OrderStatusesFixture::class,
        ];
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_commerce = Plugin::getInstance();
        $this->_commerce->set('discounts', new Discounts());
        $order = new Order(['email' => 'checkout@example.test', 'suppressEmails' => true]);
        self::assertTrue(Craft::$app->getElements()->saveElement($order, false));
        $this->_order = $order;

        $variant = Variant::find()->sku('hct-white')->one();
        $lineItem = $this->_commerce->getLineItems()->create($order, ['purchasableId' => $variant->id, 'qty' => 1]);
        $order->setLineItems([$lineItem]);
        $this->_discount = new Discount([
            'storeId' => $order->storeId,
            'name' => 'Single-use free checkout',
            'enabled' => true,
            'requireCouponCode' => true,
            'baseDiscount' => -$order->getItemSubtotal(),
            'allPurchasables' => true,
            'allCategories' => true,
        ]);
        $this->_discount->setCoupons([new Coupon(['code' => 'single-use-checkout', 'maxUses' => 1])]);
        self::assertTrue($this->_commerce->getDiscounts()->saveDiscount($this->_discount));
        $this->_coupon = $this->_commerce->getCoupons()->getCouponByCode('single-use-checkout');
        $order->couponCode = $this->_coupon->code;
        $order->gatewayId = $this->_commerce->getGateways()->getGatewayByHandle('dummy')->id;
        self::assertTrue(Craft::$app->getElements()->saveElement($order, false));
        self::assertEquals(0, $order->getTotalPrice());
    }

    protected function _after(): void
    {
        if (isset($this->_order)) {
            Craft::$app->getElements()->deleteElementById($this->_order->id, Order::class, null, true);
        }
        if (isset($this->_discount) && $this->_discount->id) {
            $this->_commerce->getDiscounts()->deleteDiscountById($this->_discount->id);
        }

        parent::_after();
    }

    public function testOverlappingFreeCheckoutsOnlyCompleteOnce(): void
    {
        // Both requests load the cart before the first request completes it.
        $firstRequest = $this->_loadOrder();
        $secondRequest = $this->_loadOrder();
        self::assertNotSame($firstRequest, $secondRequest);
        self::assertFalse($secondRequest->isCompleted);
        self::assertEquals(0, $secondRequest->getTotalPrice());

        $completions = 0;
        $handler = function() use (&$completions) {
            $completions++;
        };
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, $handler);
        try {
            $this->_processFreePayment($firstRequest);
            $firstSaved = $this->_loadOrder();
            $this->_processFreePayment($secondRequest);
        } finally {
            Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, $handler);
        }

        $saved = $this->_loadOrder();
        self::assertTrue($secondRequest->isCompleted);
        self::assertTrue($saved->getIsPaid());
        self::assertEquals(0, $saved->getTotalPrice());
        self::assertEquals(1, $completions);
        self::assertEquals(1, (new Query())->select('uses')->from('{{%commerce_coupons}}')->where(['id' => $this->_coupon->id])->scalar());
        self::assertEquals(1, (new Query())->select('totalDiscountUses')->from('{{%commerce_discounts}}')->where(['id' => $this->_discount->id])->scalar());
        self::assertEquals(1, (new Query())->select('uses')->from('{{%commerce_email_discountuses}}')->where(['discountId' => $this->_discount->id, 'email' => $saved->email])->scalar());
        self::assertCount(1, $this->_commerce->getOrderHistories()->getAllOrderHistoriesByOrderId($saved->id));
        self::assertCount(0, $saved->getTransactions());
        self::assertEquals($firstSaved->dateOrdered, $secondRequest->dateOrdered);
        self::assertEquals($firstSaved->datePaid, $secondRequest->datePaid);
        self::assertEquals(Order::RECALCULATION_MODE_NONE, $secondRequest->getRecalculationMode());
    }

    public function testStalePaymentUpdatePreservesCompletedOrderChanges(): void
    {
        $firstRequest = $this->_loadOrder();
        $secondRequest = $this->_loadOrder();
        self::assertEquals(0, $secondRequest->getTotalPrice());
        $this->_processFreePayment($firstRequest);

        // Fulfillment and an adjustment are saved before the stale request resumes.
        $firstRequest->orderStatusId = $this->_commerce->getOrderStatuses()->getOrderStatusByHandle('shipped')->id;
        $adjustment = new OrderAdjustment(['type' => 'shipping', 'name' => 'Additional shipping', 'amount' => 5]);
        $adjustment->setOrder($firstRequest);
        $firstRequest->setAdjustments([...$firstRequest->getAdjustments(), $adjustment]);
        self::assertTrue(Craft::$app->getElements()->saveElement($firstRequest, false));
        $transaction = $this->_commerce->getTransactions()->createTransaction($firstRequest, typeOverride: TransactionRecord::TYPE_PURCHASE);
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        self::assertTrue($this->_commerce->getTransactions()->saveTransaction($transaction));

        $this->_processFreePayment($secondRequest);

        $saved = $this->_loadOrder();
        self::assertTrue($secondRequest->isCompleted);
        self::assertEquals('shipped', $saved->getOrderStatus()->handle);
        self::assertEquals(5, $saved->getTotalPrice());
        self::assertEquals(5, $saved->getTotalPaid());
        self::assertEquals(5, $saved->getTotalShippingCost());
        self::assertTrue($saved->getIsPaid());
        self::assertEquals($saved->getTotalPrice(), $secondRequest->getTotalPrice());
        self::assertEquals($saved->dateOrdered, $secondRequest->dateOrdered);
        self::assertEquals(1, (new Query())->select('uses')->from('{{%commerce_coupons}}')->where(['id' => $this->_coupon->id])->scalar());
    }

    public function testPaymentUpdateReleasesCompletionLockOnException(): void
    {
        $order = $this->_loadOrder();
        $handler = function() {
            throw new RuntimeException('Payment event failed');
        };
        Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID, $handler);
        try {
            $order->updateOrderPaidInformation();
            self::fail('Expected the payment event to fail.');
        } catch (RuntimeException $exception) {
            self::assertEquals('Payment event failed', $exception->getMessage());
        } finally {
            Event::off(Order::class, Order::EVENT_AFTER_ORDER_PAID, $handler);
        }

        self::assertFalse(Craft::$app->getMutex()->isAcquired('orderComplete:' . $order->id));
    }

    private function _loadOrder(): Order
    {
        $this->_commerce->set('discounts', new Discounts());
        $order = $this->_commerce->getOrders()->getOrderById($this->_order->id);
        $order->suppressEmails = true;
        return $order;
    }

    private function _processFreePayment(Order $order): void
    {
        $redirect = null;
        $transaction = null;
        $redirectData = [];
        $this->_commerce->getPayments()->processPayment($order, $order->getGateway()->getPaymentFormModel(), $redirect, $transaction, $redirectData);
        self::assertNull($transaction);
    }
}
