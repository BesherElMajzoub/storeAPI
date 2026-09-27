# Product & Category Images — Frontend Guide

**Date:** 2026-09-27
**Audience:** frontend (storefront + admin dashboard).

This covers a fix to image URLs in API responses, which fields to use, and
which size fits which UI spot. No route was added or removed, and no field
was renamed. The only visible changes are fields that used to be `null` now
hold URLs, plus one error code (see §4).

---

## 1. What was broken and what changed

| Where | Before | After |
|---|---|---|
| `GET /api/v1/products`, `/products/{slug}`, `/categories`, `/categories/{slug}`, admin product & category endpoints | Every `image.*` / `gallery[].*` URL was `null`, including for products that do have images | Real URLs |
| Admin wishlist analytics (`/admin/wishlist-analytics`, `/summary`, `/trending`, `/conversions`) and `/admin/users/{id}/wishlist` | `image` was always `null` | URL of the product's cover image |
| `POST /admin/categories/{category}/media` sent without an `image` file | `500` | `422`, with `errors.image` |
| `PUT /admin/products/{id}` with `images[]` | A failed update could delete the old gallery | Old gallery is removed only after the new one is saved (same request/response contract) |

**Action for the frontend:** remove any workaround added because images were
always `null` (hard-coded placeholders, building `/storage/...` URLs by
hand, etc.). Use the URLs from the API as-is.

---

## 2. Rules

1. **Use the URL exactly as returned.** Don't build or change storage paths
   yourself. The API returns full absolute URLs.
2. **`image: null` means the item has no image.** Show your placeholder.
   When `image` is an object, every key in it is a usable URL.
3. **A size key may point to the original file.** If a resized version
   hasn't been generated yet, the key falls back to the original upload:
   jpg/png/webp, any size and aspect ratio. Always render with fixed box
   dimensions and `object-fit: cover`; never rely on the image's natural
   size.
4. **The first gallery item is the cover.** `image` is always the first
   item of `gallery` (lowest `order`). Reordering the gallery in admin
   changes the cover.

---

## 3. Response shapes

### 3.1 Product list: `GET /api/v1/products`

```json
"image": {
  "thumb": "https://…/storage/12/conversions/uuid-product_thumb.webp",
  "card":  "https://…/storage/12/conversions/uuid-product_card.webp"
}
```
`image` is `null` for a product with no images.

### 3.2 Product detail: `GET /api/v1/products/{slug}` (and admin product endpoints)

```json
"image": { "thumb": "…", "card": "…", "detail": "…", "zoom": "…" },
"gallery": [
  { "id": 12, "thumb": "…", "card": "…", "detail": "…", "zoom": "…", "order": 1 },
  { "id": 13, "thumb": "…", "card": "…", "detail": "…", "zoom": "…", "order": 2 }
]
```
`image` is `null` and `gallery` is `[]` when the product has no images.
The gallery `id` is the media ID used by the admin delete and reorder
endpoints.

### 3.3 Product inside wishlist / orders / reviews

`GET /wishlist` → `data[].product`, order items → `items[].product`,
reviews → `product`:

```json
"image": { "thumb": "…", "card": "…", "detail": "…", "zoom": "…" },
"gallery": []
```
Unlike §3.1 and §3.2, `image` here is **always an object**. When the
product has no images, all four keys are `null`, so check
`image.card` rather than `image`.

### 3.4 Categories

- `GET /api/v1/categories` (tree), and `category` inside product detail:
  `"image": { "thumb": "…", "card": "…" }` or `null`. `children[]` have
  the same shape.
- `GET /api/v1/categories/{slug}` and admin category endpoints:
  `"image": { "thumb": "…", "card": "…", "banner": "…" }` or `null`.

### 3.5 Admin wishlist analytics & user wishlist

`image` is a **single string** (the cover at `card` size), or `null`:
```json
{ "id": 5, "name": "…", "image": "https://…/product_card.webp", "wishlist_count": 7 }
```
In `/admin/wishlist-analytics/summary` it's at `top_product.image`.

---

## 4. Sizes: which key to use where

All generated versions are **WebP, center-cropped**.

**Products (square):**

| Key | Size | Use for |
|---|---|---|
| `thumb` | 120×120 | Cart rows, order history, gallery thumbnail strip, search dropdown, admin tables |
| `card` | 420×420 | Product grid/cards, wishlist, related products |
| `detail` | 1000×1000 | Main image on the product page |
| `zoom` | 1600×1600 | Zoom / lightbox / fullscreen only; load on demand |

**Categories:**

| Key | Size | Use for |
|---|---|---|
| `thumb` | 200×200 | Menus, category chips/icons |
| `card` | 400×250 | Category tiles on home/listing pages |
| `banner` | 1200×600 | Header of the category page (only in `/categories/{slug}` and admin) |

Don't use `zoom` or `banner` in lists; they're much heavier than needed.

---

## 5. Admin upload reference (unchanged contract, for completeness)

- **Add images:** `POST /admin/products/{id}/images`, multipart `images[]`.
  jpg/jpeg/png/webp, max 5 MB each, max **8 images per product in total**
  (otherwise `422` with `errors.images`). Appends to the gallery.
- **Replace the whole gallery:** `PUT /admin/products/{id}` with `images[]`.
  Omit `images[]` to keep the current gallery.
- **Reorder:** `POST /admin/products/{id}/images/order` with
  `{ "order": [13, 12] }` (gallery IDs); the first ID becomes the cover.
- **Delete one:** `DELETE /admin/products/{id}/images/{mediaId}`.
- **Category image:** `POST /admin/categories/{id}/media`, multipart
  `image` (single file; replaces the current one). Missing file → `422`.
  It can also be sent as `image` in the category create/update request.

After any upload, re-read `image`/`gallery` from the response or refetch
the product. Don't reuse the URLs you had before, because every uploaded
file gets a new random name.
