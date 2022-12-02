<?php

namespace Lanos\CashierConnect\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectSubscriptionItem extends Model
{

    protected $guarded = [];
    protected $table = 'connected_subscription_items';

    public function subscription(){
        return $this->belongsTo(config('cashierconnect.subscription_model'), 'connected_subscription_id', 'id');
    }

}
