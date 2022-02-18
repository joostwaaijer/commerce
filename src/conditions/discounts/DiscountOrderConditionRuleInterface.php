<?php

namespace craft\commerce\conditions\discounts;

use craft\base\conditions\ConditionRuleInterface;
use craft\commerce\elements\Order;


/**
 * Discount Order Condition Rule Interface
 *
 * @since 4.0.0
 */
interface DiscountOrderConditionRuleInterface extends ConditionRuleInterface
{
    /**
     * Does the condition rule match the order.
     *
     * @param Order $order
     * @return bool
     */
    public function matchOrder(Order $order): bool;
}