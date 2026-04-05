<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreContactMessageRequest;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ContactMessageController extends Controller
{
    #[OA\Post(
        path: "/api/v1/contact-messages",
        summary: "Submit Contact Us Form",
        description: "Saves a new contact us message.",
        tags: ["Public"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "email", "message"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "John Doe"),
                new OA\Property(property: "email", type: "string", format: "email", example: "john@example.com"),
                new OA\Property(property: "phone", type: "string", example: "+1234567890", nullable: true),
                new OA\Property(property: "subject", type: "string", example: "Inquiry about product", nullable: true),
                new OA\Property(property: "message", type: "string", example: "I have a question about my order.")
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Message sent successfully",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Your message has been sent successfully."),
                new OA\Property(property: "data", nullable: true),
                new OA\Property(property: "errors", nullable: true)
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ErrorResponse")]
    public function store(StoreContactMessageRequest $request): JsonResponse
    {
        ContactMessage::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Your message has been sent successfully.',
            'data' => null,
            'errors' => null,
        ], 201);
    }
}
