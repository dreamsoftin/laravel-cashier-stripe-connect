<?php

namespace Lanos\CashierConnect\Concerns;

use Exception;
use Illuminate\Support\Facades\Date;
use Lanos\CashierConnect\Exceptions\AccountNotFoundException;
use Illuminate\Support\Str;
use Lanos\CashierConnect\Models\ConnectSubscription;
use Lanos\CashierConnect\Models\ConnectSubscriptionItem;
use Stripe\Balance;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Stripe\Transfer;

/**
 * Manages Customers that belong to a connected account (not the platform account)
 *
 * @package Lanos\CashierConnect\Concerns
 */
trait ManagesConnectSubscriptions
{

    /**
     * Creates a subscription between this account model and a customer model
     * It will also return the first payment intent which should be used to collect payment details and do 3DS on frontend
     * @param mixed $customer // Any model with ConnectCustomer trait
     * @param string $paymentMethod // Payment method ID from stripe, this can be set up on frontend with setup intent
     * @return Subscription
     */

    public function createDirectSubscription($customer, $price, $quantity = 1, $data = [], $name = 'default')
    {

        // APPLY PLATFORM FEE COMMISSION - SET THIS AGAINST THE MODEL
        if (isset($this->commission_type) && isset($this->commission_rate)) {
            if ($this->commission_type === 'percentage') {
                $data['application_fee_percent'] = $this->commission_rate;
            } else {
                $data['application_fee_amount'] = $this->commission_rate;
            }
        }

        $customerID = $this->determineCustomerInput($customer);

        $subscription = Subscription::create(
            $data + [
                "customer" => $this->determineCustomerInput($customer),
                "items" => [
                    ['price' => $price, "quantity" => $quantity]
                ],
                "payment_behavior" => "default_incomplete",
                "expand" => ["latest_invoice.payment_intent"]
            ],
            $this->stripeAccountOptions([], true)
        );

        // TODO REWRITE TO USE RELATIONAL CREATION
        // GENERATE DATABASE RECORD FOR SUBSCRIPTION
        $ConnectSubscriptionRecord = config('cashierconnect.subscription_model')::create([
            "name" => $name,
            'is_connected_subscription' => true,
            "stripe_id" => $subscription->id,
            "stripe_status" => $subscription->status,
            'purchased_at' => now(),
            "connected_price_id" => $price,
            "ends_at" => Date::parse($subscription->current_period_end),
            "stripe_customer_id" => $customerID,
            "stripe_account_id" => $this->stripeAccountId()
        ]);

        // TODO REWRITE TO USE RELATIONAL CREATION
        $ConnectSubscriptionItemRecord = config('cashierconnect.subscription_item_model')::create([
            'type' => 'connect',
            "subscription_id" => $ConnectSubscriptionRecord->id,
            "stripe_id" => $subscription->items->data[0]->id,
            "stripe_product" => $subscription->items->data[0]->price->product,
            "stripe_price" => $subscription->items->data[0]->price->id,
            "quantity" => $quantity
        ]);

        return $subscription;
    }


    public function cancelSubscription($subscription)
    {
        // $stripeSubscription =  Subscription::retrieve($subscription->id);
        Subscription::update($subscription->stripe_id, [
            'cancel_at_period_end' => true,
        ], $this->stripeAccountOptions([], true));
    }

    public function cancelSubscriptionNow($subscription)
    {
        $stripeSubscription = $this->retrieveSubscriptionFromStripe($subscription->stripe_id);
        $stripeSubscription->cancel([
            'prorate' => true,
        ], $this->stripeAccountOptions([], true));
    }

    public function getSubscriptions()
    {
        return $this->stripeAccountMapping->subscriptions;
    }

    /**
     * Retrieves a subscription object by its stripe subscription ID
     * @param $id
     * @return Subscription
     * @throws ApiErrorException
     */
    public function retrieveSubscriptionFromStripe($id): Subscription
    {
        return Subscription::retrieve($id, $this->stripeAccountOptions([], true));
    }

    private function determineCustomerInput($customer)
    {
        if (gettype($customer) === 'string') {
            return $customer;
        } else {
            return $this->handleConnectedCustomer($customer);
        }
    }

    private function handleConnectedCustomer($customer)
    {
        // IT IS A CUSTOMER TRAIT MODEL
        $traits = class_uses($customer);

        if (!in_array('Lanos\CashierConnect\ConnectCustomer', $traits)) {
            throw new Exception('This model does not have a connect ConnectCustomer trait on.');
        }

        $customer->assetCustomerExists();

        return $customer->stripeCustomerIdByAccount($this->stripeAccountId());
    }
}
