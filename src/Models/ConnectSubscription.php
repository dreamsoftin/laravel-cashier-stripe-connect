<?php

namespace Lanos\CashierConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Lanos\CashierConnect\StripeEntity;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription;

class ConnectSubscription extends Model
{

    use StripeEntity;

    protected $guarded = [];
    protected $table = 'subscriptions';

    public function items(){
        return $this->hasMany(config('cashierconnect.subscription_item_model'), 'connected_subscription_id', 'id');
    }

    /**
     * Gets the stripe subscription for the model
     * @return Subscription
     * @throws ApiErrorException
     */
    public function asStripeSubscription(){
        return Subscription::retrieve($this->stripe_id, $this->stripeAccountOptions([], $this->stripe_account_Id));
    }

}
