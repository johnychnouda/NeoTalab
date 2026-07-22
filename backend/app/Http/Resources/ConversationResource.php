<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|null  $aiOutput
     * @param  array<string, mixed>|null  $cartResult
     */
    public function __construct(
        $resource,
        protected ?array $aiOutput = null,
        protected ?array $cartResult = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'channel' => $this->channel,
            'state' => $this->state,
            'last_message_at' => $this->last_message_at,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'cart' => new CartResource($this->whenLoaded('cart')),
            'created_at' => $this->created_at,
        ];

        if ($this->aiOutput !== null) {
            $data['ai'] = $this->aiOutput;
        }

        if ($this->cartResult !== null) {
            $data['cart_result'] = $this->cartResult;
        }

        return $data;
    }
}
