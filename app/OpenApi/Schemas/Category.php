<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Category',
    title: 'Category',
    description: 'Product category (detail view)',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Electronics'),
        new OA\Property(property: 'slug', type: 'string', example: 'electronics'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'meta_title', type: 'string', nullable: true),
        new OA\Property(property: 'meta_description', type: 'string', nullable: true),
        new OA\Property(
            property: 'image',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'thumb', type: 'string', format: 'uri', nullable: true),
                new OA\Property(property: 'card', type: 'string', format: 'uri', nullable: true),
                new OA\Property(property: 'banner', type: 'string', format: 'uri', nullable: true, description: 'Only in category detail endpoint'),
            ]
        ),
    ]
)]
class Category {}
