<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateContactMessageStatusRequest;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ContactMessageController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/contact-messages",
        summary: "Admin List Contact Messages",
        description: "List all contact messages for admin",
        security: [["bearerAuth" => []]],
        tags: ["Admin Contact Messages"]
    )]
    #[OA\Parameter(name: "limit", in: "query", schema: new OA\Schema(type: "integer", default: 15))]
    #[OA\Response(
        response: 200,
        description: "Messages fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Contact messages retrieved."),
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 15);
        $messages = ContactMessage::latest()->paginate($limit);

        return response()->json([
            'success' => true,
            'message' => 'Contact messages retrieved.',
            'data' => $messages,
            'errors' => null,
        ]);
    }

    #[OA\Get(
        path: "/api/v1/admin/contact-messages/{id}",
        summary: "Admin Show Contact Message",
        description: "Show a single contact message. This action automatically marks a 'new' status message as 'read'.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Contact Messages"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Message fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Message retrieved."),
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function show($id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        
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

    #[OA\Patch(
        path: "/api/v1/admin/contact-messages/{id}/status",
        summary: "Admin Update Contact Message Status",
        description: "Update the status and optionally internal notes of a contact message.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Contact Messages"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["status"],
            properties: [
                new OA\Property(property: "status", type: "string", enum: ["new", "read", "replied", "archived"], example: "replied"),
                new OA\Property(property: "notes", type: "string", example: "User contacted back via email.", nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Message updated",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Message status updated."),
                new OA\Property(property: "data", type: "object")
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
        path: "/api/v1/admin/contact-messages/{id}",
        summary: "Admin Delete Contact Message",
        description: "Delete a contact message permanently.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Contact Messages"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Message deleted",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Message deleted successfully.")
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
