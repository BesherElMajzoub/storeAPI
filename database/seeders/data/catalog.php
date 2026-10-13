<?php

/**
 * Otantik Queen showcase catalogue (women's modest fashion).
 * Images live in catalog-images/ (git-ignored) and are matched by slug:
 * products/{slug}-N.png and categories/{image}. Prices are USD; measurements are
 * the shipped parcel (oz / inches) as [length, width, height].
 */

$abayaSizes = ['52', '54', '56', '58', '60'];
$dressSizes = ['S', 'M', 'L', 'XL'];
$knitSizes = ['S/M', 'L/XL'];

return [
    'categories' => [
        ['name' => 'Abayas', 'slug' => 'abayas', 'image' => 'abayas.png', 'description' => 'Closed, open-front and occasion abayas in breathable, premium fabrics.', 'children' => [
            ['name' => 'Everyday Abayas', 'slug' => 'everyday-abayas', 'description' => 'Comfortable closed abayas for daily wear.'],
            ['name' => 'Open Abayas', 'slug' => 'open-abayas', 'description' => 'Open-front abayas to layer over your outfit.'],
            ['name' => 'Occasion Abayas', 'slug' => 'occasion-abayas', 'description' => 'Embroidered and embellished abayas for Eid and special days.'],
        ]],
        ['name' => 'Modest Dresses', 'slug' => 'modest-dresses', 'image' => 'modest-dresses.png', 'description' => 'Long-sleeved, full-length dresses with relaxed, modest cuts.', 'children' => [
            ['name' => 'Maxi Dresses', 'slug' => 'maxi-dresses', 'description' => 'Easy everyday maxi dresses.'],
            ['name' => 'Evening Dresses', 'slug' => 'evening-dresses', 'description' => 'Satin and lace gowns for weddings and evenings out.'],
        ]],
        ['name' => 'Hijabs', 'slug' => 'hijabs', 'image' => 'hijabs.png', 'description' => 'Chiffon, jersey, modal and satin hijabs in a soft palette.', 'children' => [
            ['name' => 'Chiffon Hijabs', 'slug' => 'chiffon-hijabs', 'description' => 'Light, airy chiffon hijabs.'],
            ['name' => 'Jersey & Modal', 'slug' => 'jersey-modal-hijabs', 'description' => 'Soft, stretchy everyday hijabs.'],
            ['name' => 'Satin Hijabs', 'slug' => 'satin-hijabs', 'description' => 'Silky and matte satin hijabs for dressy looks.'],
        ]],
        ['name' => 'Prayer Wear', 'slug' => 'prayer-wear', 'image' => 'prayer-wear.png', 'description' => 'Prayer dresses, sets and mats.', 'children' => [
            ['name' => 'Prayer Dresses', 'slug' => 'prayer-dresses', 'description' => 'Full-coverage prayer dresses and sets.'],
            ['name' => 'Prayer Mats', 'slug' => 'prayer-mats', 'description' => 'Padded prayer mats.'],
        ]],
        ['name' => 'Kimonos & Cardigans', 'slug' => 'kimonos-cardigans', 'image' => 'kimonos-cardigans.png', 'description' => 'Layering pieces for every season.', 'children' => [
            ['name' => 'Kimonos', 'slug' => 'kimonos', 'description' => 'Lightweight open kimonos.'],
            ['name' => 'Long Cardigans', 'slug' => 'long-cardigans', 'description' => 'Cosy ankle-length knit cardigans.'],
        ]],
        ['name' => 'Accessories', 'slug' => 'accessories', 'image' => 'accessories.png', 'description' => 'Underscarves, pins and hijab essentials.', 'children' => [
            ['name' => 'Underscarves', 'slug' => 'underscarves', 'description' => 'Cotton underscarf caps.'],
            ['name' => 'Hijab Pins', 'slug' => 'hijab-pins', 'description' => 'Magnetic pins and clips.'],
        ]],
    ],

    'products' => [
        // Abayas
        ['code' => 'ABY-001', 'slug' => 'noor-classic-closed-abaya', 'name' => 'Noor Classic Closed Abaya', 'category' => 'everyday-abayas', 'price' => 89.00, 'discount' => null, 'featured' => true,
            'description' => 'A timeless closed abaya in matte Nida fabric with a straight relaxed cut, wide sleeves and a round neckline. Lightweight, wrinkle-resistant and easy to wear all day.',
            'colors' => ['Black', 'Navy', 'Mocha'], 'sizes' => $abayaSizes, 'oz' => 22, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-002', 'slug' => 'layla-crepe-pocket-abaya', 'name' => 'Layla Crepe Pocket Abaya', 'category' => 'everyday-abayas', 'price' => 95.00, 'discount' => 79.00, 'featured' => true,
            'description' => 'A relaxed A-line crepe abaya with hidden side pockets and neat cuffed sleeves. Soft drape, opaque fabric and a clean finish.',
            'colors' => ['Navy', 'Black', 'Taupe'], 'sizes' => $abayaSizes, 'oz' => 24, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-003', 'slug' => 'safa-open-front-abaya', 'name' => 'Safa Open-Front Linen Abaya', 'category' => 'open-abayas', 'price' => 99.00, 'discount' => null, 'featured' => false,
            'description' => 'An open-front abaya in a breathable linen blend with a matching tie belt. Wear it open as a long coat or belted at the waist.',
            'colors' => ['Sand', 'Olive', 'Black'], 'sizes' => $abayaSizes, 'oz' => 26, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-004', 'slug' => 'reem-pleated-sleeve-abaya', 'name' => 'Reem Pleated Sleeve Abaya', 'category' => 'open-abayas', 'price' => 109.00, 'discount' => null, 'featured' => false,
            'description' => 'An open abaya with softly pleated bell sleeves that widen from the elbow. Light, flowing and elegant over any inner dress.',
            'colors' => ['Olive', 'Black', 'Dusty Blue'], 'sizes' => $abayaSizes, 'oz' => 24, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-005', 'slug' => 'yasmin-embroidered-eid-abaya', 'name' => 'Yasmin Embroidered Eid Abaya', 'category' => 'occasion-abayas', 'price' => 149.00, 'discount' => 129.00, 'featured' => true,
            'description' => 'A closed black abaya finished with fine gold-thread embroidery down the front and around the cuffs. Refined, never flashy; made for Eid and special gatherings.',
            'colors' => ['Black', 'Navy'], 'sizes' => $abayaSizes, 'oz' => 28, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-006', 'slug' => 'amira-pearl-trim-abaya', 'name' => 'Amira Pearl Trim Abaya', 'category' => 'occasion-abayas', 'price' => 139.00, 'discount' => null, 'featured' => false,
            'description' => 'A closed crepe abaya with tiny pearl beads along the neckline and sleeve hems. Soft, graceful and perfect for weddings and engagements.',
            'colors' => ['Ivory', 'Black', 'Blush'], 'sizes' => $abayaSizes, 'oz' => 26, 'dim' => [14, 11, 3]],
        ['code' => 'ABY-007', 'slug' => 'dana-butterfly-abaya', 'name' => 'Dana Butterfly Chiffon Abaya', 'category' => 'occasion-abayas', 'price' => 119.00, 'discount' => null, 'featured' => false,
            'description' => 'A flowing butterfly (farasha) abaya in layered chiffon with wide kaftan-style sleeves. One size fits most.',
            'colors' => ['Dusty Rose', 'Black', 'Lilac'], 'sizes' => ['One Size'], 'oz' => 20, 'dim' => [13, 10, 3]],

        // Modest dresses
        ['code' => 'DRS-001', 'slug' => 'hana-tiered-maxi-dress', 'name' => 'Hana Tiered Maxi Dress', 'category' => 'maxi-dresses', 'price' => 79.00, 'discount' => 64.00, 'featured' => true,
            'description' => 'A loose three-tier cotton maxi dress with long sleeves, elastic cuffs and a high round neckline. Ankle length, light and breathable.',
            'colors' => ['Sage', 'Terracotta', 'Navy'], 'sizes' => $dressSizes, 'oz' => 18, 'dim' => [13, 10, 3]],
        ['code' => 'DRS-002', 'slug' => 'mariam-linen-shirt-dress', 'name' => 'Mariam Linen Shirt Dress', 'category' => 'maxi-dresses', 'price' => 85.00, 'discount' => null, 'featured' => false,
            'description' => 'A buttoned-through linen maxi shirt dress with a collar, long sleeves and a tie belt. Relaxed fit that stays cool in warm weather.',
            'colors' => ['Sand', 'White', 'Olive'], 'sizes' => $dressSizes, 'oz' => 19, 'dim' => [13, 10, 3]],
        ['code' => 'DRS-003', 'slug' => 'lina-satin-evening-gown', 'name' => 'Lina Satin Evening Gown', 'category' => 'evening-dresses', 'price' => 159.00, 'discount' => null, 'featured' => true,
            'description' => 'A fluid satin gown with a high neckline, buttoned cuffs and a softly gathered waist. Floor length and fully modest for weddings and formal events.',
            'colors' => ['Emerald', 'Burgundy', 'Navy'], 'sizes' => $dressSizes, 'oz' => 28, 'dim' => [14, 11, 4]],
        ['code' => 'DRS-004', 'slug' => 'sara-lace-overlay-dress', 'name' => 'Sara Lace Overlay Dress', 'category' => 'evening-dresses', 'price' => 169.00, 'discount' => 139.00, 'featured' => false,
            'description' => 'A maxi dress with fine floral lace over an opaque satin lining. Lined lace sleeves to the wrist, a high neckline and a loose A-line skirt.',
            'colors' => ['Champagne', 'Black', 'Dusty Blue'], 'sizes' => $dressSizes, 'oz' => 30, 'dim' => [14, 11, 4]],

        // Hijabs
        ['code' => 'HJB-001', 'slug' => 'premium-chiffon-hijab', 'name' => 'Premium Chiffon Hijab', 'category' => 'chiffon-hijabs', 'price' => 18.00, 'discount' => null, 'featured' => true,
            'description' => 'A light, semi-sheer premium chiffon hijab with a delicate finished edge. Drapes beautifully and stays in place.',
            'colors' => ['Dusty Pink', 'Black', 'Nude', 'Navy', 'Sage'], 'sizes' => [], 'oz' => 4, 'dim' => [9, 7, 1]],
        ['code' => 'HJB-002', 'slug' => 'crinkle-chiffon-hijab', 'name' => 'Crinkle Chiffon Hijab', 'category' => 'chiffon-hijabs', 'price' => 19.00, 'discount' => null, 'featured' => false,
            'description' => 'A naturally crinkled chiffon hijab that needs no ironing and grips easily. Soft texture with an effortless look.',
            'colors' => ['Mocha', 'Black', 'Ivory', 'Olive'], 'sizes' => [], 'oz' => 4, 'dim' => [9, 7, 1]],
        ['code' => 'HJB-003', 'slug' => 'everyday-jersey-hijab', 'name' => 'Everyday Jersey Hijab', 'category' => 'jersey-modal-hijabs', 'price' => 14.00, 'discount' => 11.00, 'featured' => false,
            'description' => 'A soft, stretchy jersey hijab that wraps smoothly with no pins needed. Breathable and comfortable from morning to night.',
            'colors' => ['Black', 'Grey', 'Nude', 'Navy', 'White'], 'sizes' => [], 'oz' => 5, 'dim' => [9, 7, 1]],
        ['code' => 'HJB-004', 'slug' => 'modal-cotton-hijab', 'name' => 'Modal Cotton Hijab', 'category' => 'jersey-modal-hijabs', 'price' => 16.00, 'discount' => null, 'featured' => false,
            'description' => 'A matte modal-cotton hijab with a soft drape and a raw fringed edge. Gentle on skin and easy to style.',
            'colors' => ['Oatmeal', 'Black', 'Sage', 'Mauve'], 'sizes' => [], 'oz' => 5, 'dim' => [9, 7, 1]],
        ['code' => 'HJB-005', 'slug' => 'silky-satin-hijab', 'name' => 'Silky Satin Hijab', 'category' => 'satin-hijabs', 'price' => 22.00, 'discount' => null, 'featured' => false,
            'description' => 'A luminous silky satin hijab with a rolled edge, ideal for dressy occasions.',
            'colors' => ['Champagne', 'Black', 'Emerald', 'Navy'], 'sizes' => [], 'oz' => 4, 'dim' => [9, 7, 1]],
        ['code' => 'HJB-006', 'slug' => 'matte-satin-hijab', 'name' => 'Matte Satin Hijab', 'category' => 'satin-hijabs', 'price' => 22.00, 'discount' => null, 'featured' => false,
            'description' => 'A subtle low-sheen matte satin hijab that drapes neatly and looks polished without the shine.',
            'colors' => ['Burgundy', 'Black', 'Nude', 'Dusty Blue'], 'sizes' => [], 'oz' => 4, 'dim' => [9, 7, 1]],

        // Prayer wear
        ['code' => 'PRY-001', 'slug' => 'sakina-prayer-dress', 'name' => 'Sakina One-Piece Prayer Dress', 'category' => 'prayer-dresses', 'price' => 59.00, 'discount' => null, 'featured' => true,
            'description' => 'A one-piece prayer dress with an attached head covering that flows over the arms, with a lace-trimmed face opening and a long loose skirt. Breathable and fully covering.',
            'colors' => ['Lavender', 'Grey', 'Navy', 'White'], 'sizes' => ['One Size'], 'oz' => 16, 'dim' => [12, 9, 3]],
        ['code' => 'PRY-002', 'slug' => 'rahma-two-piece-prayer-set', 'name' => 'Rahma Two-Piece Prayer Set', 'category' => 'prayer-dresses', 'price' => 55.00, 'discount' => 45.00, 'featured' => false,
            'description' => 'A soft jersey khimar top and matching maxi skirt with an elastic waistband. Quick to put on and comfortable for long prayers.',
            'colors' => ['Cream', 'Grey', 'Navy'], 'sizes' => ['One Size'], 'oz' => 18, 'dim' => [12, 9, 3]],
        ['code' => 'PRY-003', 'slug' => 'velvet-prayer-mat', 'name' => 'Padded Velvet Prayer Mat', 'category' => 'prayer-mats', 'price' => 34.00, 'discount' => null, 'featured' => false,
            'description' => 'A thick padded velvet prayer mat with an embossed mihrab pattern and tassel ends. Soft underfoot and easy to roll away.',
            'colors' => ['Emerald', 'Burgundy', 'Grey'], 'sizes' => [], 'oz' => 28, 'dim' => [30, 4, 4]],

        // Kimonos & cardigans
        ['code' => 'KMN-001', 'slug' => 'zahra-floral-kimono', 'name' => 'Zahra Floral Kimono', 'category' => 'kimonos', 'price' => 69.00, 'discount' => null, 'featured' => false,
            'description' => 'A lightweight open kimono with a small scattered floral print and wide sleeves. Ankle length; layer it over any plain maxi dress.',
            'colors' => ['Navy Floral'], 'sizes' => $knitSizes, 'oz' => 14, 'dim' => [12, 9, 2]],
        ['code' => 'KMN-002', 'slug' => 'duha-long-knit-cardigan', 'name' => 'Duha Long Knit Cardigan', 'category' => 'long-cardigans', 'price' => 89.00, 'discount' => 74.00, 'featured' => true,
            'description' => 'An ankle-length chunky knit cardigan with patch pockets and ribbed edges. Warm, soft and easy to layer over a black maxi dress.',
            'colors' => ['Oatmeal', 'Charcoal', 'Camel'], 'sizes' => $knitSizes, 'oz' => 34, 'dim' => [14, 11, 4]],

        // Accessories
        ['code' => 'ACC-001', 'slug' => 'cotton-underscarf-cap', 'name' => 'Cotton Underscarf Cap', 'category' => 'underscarves', 'price' => 8.00, 'discount' => null, 'featured' => false,
            'description' => 'A soft stretchy cotton tube cap that keeps your hijab in place and covers the hairline.',
            'colors' => ['Black', 'White', 'Nude'], 'sizes' => [], 'oz' => 2, 'dim' => [7, 5, 1]],
        ['code' => 'ACC-002', 'slug' => 'magnetic-hijab-pins-set', 'name' => 'Magnetic Hijab Pins Set (6 pcs)', 'category' => 'hijab-pins', 'price' => 15.00, 'discount' => null, 'featured' => false,
            'description' => 'Six strong magnetic pins with ivory pearl heads and gold-tone bases. Secure your hijab without piercing the fabric.',
            'colors' => [], 'sizes' => [], 'oz' => 3, 'dim' => [5, 4, 1]],
    ],
];
