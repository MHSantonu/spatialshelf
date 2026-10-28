<?php

namespace App\Models;

use App\Enums\ConversationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'state', 'pending_order_id', 'context', 'last_inbound_at'])]
class Conversation extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'context' => 'array',
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function pendingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'pending_order_id');
    }
}
