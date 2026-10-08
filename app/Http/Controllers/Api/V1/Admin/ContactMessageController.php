<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreContactMessageReplyRequest;
use App\Http\Requests\Api\V1\Admin\UpdateContactMessageStatusRequest;
use App\Http\Resources\ContactMessageReplyResource;
use App\Mail\ContactMessageReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ContactMessageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/contact-messages',
        summary: 'Admin List Contact Messages',
        description: 'List all contact messages for admin',
        security: [['bearerAuth' => []]],
        tags: ['Admin Contact Messages']
    )]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 15))]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['new', 'read', 'replied', 'archived']))]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Search name, email, phone, subject, or message', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Messages fetched',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Contact messages retrieved.'),
                new OA\Property(property: 'data', type: 'object'),
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['new', 'read', 'replied', 'archived'])],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);
        $limit = min(max((int) $request->query('limit', 15), 1), 100);
        $messages = ContactMessage::query()
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($limit)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Contact messages retrieved.',
            'data' => $messages,
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/admin/contact-messages/{id}',
        summary: 'Admin Show Contact Message',
        description: "Show a single contact message. This action automatically marks a 'new' status message as 'read'.",
        security: [['bearerAuth' => []]],
        tags: ['Admin Contact Messages']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Message fetched',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Message retrieved.'),
                new OA\Property(property: 'data', type: 'object'),
            ]
        )
    )]
    public function show($id): JsonResponse
    {
        $message = ContactMessage::with('replies.admin')->findOrFail($id);

        // Optionally mark as read if it's new when an admin views it
        if ($message->status === 'new') {
            $message->update(['status' => 'read']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message retrieved.',
            'data' => $message,
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/contact-messages/{id}/replies',
        summary: 'Admin Reply To Contact Message',
        description: 'Saves the reply, emails it to the customer, and marks the message as replied.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Contact Messages']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['body'], properties: [
        new OA\Property(property: 'body', type: 'string', maxLength: 5000),
    ]))]
    #[OA\Response(response: 201, description: 'Reply created: {id, admin_name, body, created_at}')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function reply(StoreContactMessageReplyRequest $request, $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);

        /** @var ContactMessageReply $reply */
        $reply = $message->replies()->create([
            'admin_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);
        $message->update(['status' => 'replied']);

        Mail::to($message->email)->queue(new ContactMessageReplyMail($reply));

        return response()->json([
            'success' => true,
            'message' => 'Reply sent.',
            'data' => new ContactMessageReplyResource($reply->load('admin')),
            'errors' => null,
        ], 201);
    }

    #[OA\Patch(
        path: '/api/v1/admin/contact-messages/{id}/status',
        summary: 'Admin Update Contact Message Status',
        description: 'Update the status and optionally internal notes of a contact message.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Contact Messages']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['new', 'read', 'replied', 'archived'], example: 'replied'),
                new OA\Property(property: 'notes', type: 'string', example: 'User contacted back via email.', nullable: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Message updated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Message status updated.'),
                new OA\Property(property: 'data', type: 'object'),
            ]
        )
    )]
    public function updateStatus(UpdateContactMessageStatusRequest $request, $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $message->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Message status updated.',
            'data' => $message,
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/admin/contact-messages/{id}',
        summary: 'Admin Delete Contact Message',
        description: 'Delete a contact message permanently.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Contact Messages']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Message deleted',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Message deleted successfully.'),
            ]
        )
    )]
    public function destroy($id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
