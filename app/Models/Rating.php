<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    protected $fillable = ['account_id', 'product_id', 'rating', 'comment'];

    // A rating belongs to an account
    public function account()
    {
        return $this->belongsTo(Account::class); // Ensure this relationship is correct
    }

    // A rating belongs to a product
    public function product()
    {
        return $this->belongsTo(Product::class); // Ensure this relationship is correct
    }
}
