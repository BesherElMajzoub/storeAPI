<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    /**
     * GET /admin/users
     * List all users with basic info.
     */
    #[OA\Get(
        path: "/api/v1/admin/users",
        summary: "Admin List Users",
        description: "List all users with their orders and reviews counts",
        security: [["bearerAuth" => []]],
        tags: ["Admin Users"]
    )]
    #[OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer", default: 20))]
    #[OA\Response(
        response: 200,
        description: "Users fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(ref: "#/components/schemas/User")
                        )
                    ]
                )
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $users = User::withCount(['orders', 'reviews'])
            ->latest()
            ->paginate($perPage);

        return $this->success($users, 'Users fetched.');
    }

    /**
     * GET /admin/users/{id}
     * Get full user details including summary info.
     */
    #[OA\Get(
        path: "/api/v1/admin/users/{id}",
        summary: "Admin Show User",
        description: "Get full user details",
        security: [["bearerAuth" => []]],
        tags: ["Admin Users"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "User fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/User")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function show(int $id): JsonResponse
    {
        $user = User::withCount(['orders', 'reviews'])
            ->findOrFail($id);

        return $this->success($user, 'User fetched.');
    }

    /**
     * GET /admin/users/{id}/wishlist
     * View a specific user's wishlist — shown as a Tab in Admin > Users > User Details.
     * Returns: product image, name, price, date added.
     */
    #[OA\Get(
        path: "/api/v1/admin/users/{id}/wishlist",
        summary: "Admin User Wishlist",
        description: "View a specific user's wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Admin Users"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "User wishlist fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function wishlist(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $wishlist = $user->wishlistProducts()
            ->with('images')
            ->get()
            ->map(fn ($product) => [
                'id'        => $product->id,
                'name'      => $product->name,
                'slug'      => $product->slug,
                'price'     => (float) $product->price,
                'final_price' => (float) $product->final_price,
                'image'     => $product->images->first()?->url,
                'added_at'  => $product->pivot->created_at?->toISOString(),
            ]);

        return $this->success([
            'user' => [
                'id'   => $user->id,
                'name' => $user->name,
            ],
            'wishlist'       => $wishlist,
            'wishlist_count' => $wishlist->count(),
        ], "User #{$id} wishlist fetched.");
    }

    /**
     * GET /admin/users/{id}/addresses
     * View all addresses of a specific user.
     */
    #[OA\Get(
        path: "/api/v1/admin/users/{id}/addresses",
        summary: "Admin User Addresses",
        description: "View all addresses of a specific user for admin",
        security: [["bearerAuth" => []]],
        tags: ["Admin Users"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "User addresses fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function addresses(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $addresses = $user->addresses()
            ->orderBy('is_default', 'desc')
            ->get();

        return $this->success([
            'user'      => ['id' => $user->id, 'name' => $user->name],
            'addresses' => $addresses,
        ], "User #{$id} addresses fetched.");
    }

    private function success(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
