<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\ConversationTurnRequest;
use App\Http\Requests\Conversation\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ConversationController extends Controller
{
    public function __construct(protected ConversationService $conversations) {}

    public function store(StartConversationRequest $request): JsonResponse
    {
        try {
            $conversation = $this->conversations->startOrResume($request->validated());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $conversation): ConversationResource
    {
        return new ConversationResource(
            $this->conversations->findConversation($conversation)
        );
    }

    public function turn(ConversationTurnRequest $request, string $conversation): ConversationResource|JsonResponse
    {
        try {
            $model = $this->conversations->findConversation($conversation);
            $result = $this->conversations->processTurn($model, $request->validated('message'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new ConversationResource(
            $result['conversation'],
            aiOutput: $result['output']->toArray(),
            cartResult: $result['cart_result'],
        );
    }
}
