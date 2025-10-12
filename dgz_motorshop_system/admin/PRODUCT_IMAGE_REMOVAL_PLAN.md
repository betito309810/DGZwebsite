# Product Image Deletion Plan

## Current State Overview
- The main product image is stored in the `products.image` column and uploaded through `moveUploadedProductImage()`, which saves the file under `/uploads/products/{productId}/main.{ext}` and cleans up older main images. 【F:dgz_motorshop_system/admin/products.php†L33-L89】【F:dgz_motorshop_system/init.sql†L17-L27】
- Additional gallery images are uploaded through `persistGalleryUploads()`, saved in the same product directory with generated filenames, and referenced from the `product_images` table with a `sort_order`. 【F:dgz_motorshop_system/admin/products.php†L115-L181】【F:dgz_motorshop_system/init.sql†L22-L27】
- The product edit modal currently presents file inputs for the main image and gallery uploads but lacks controls to remove existing files. 【F:dgz_motorshop_system/admin/products.php†L1003-L1055】

## Goals
1. Allow administrators to remove the primary product image so the product reverts to the placeholder image.
2. Allow administrators to remove individual gallery images associated with a product.
3. Ensure the database and filesystem stay in sync when files are removed.
4. Preserve existing upload and sorting behavior for any images that remain.

## Proposed Backend Changes
1. **Route and Authorization Checks**
   - Reuse the existing admin gate (`$isStaff` / `enforceStaffAccess()`) so only admin-level users can trigger deletions. 【F:dgz_motorshop_system/admin/products.php†L5-L9】
   - Add dedicated POST actions for image removal (e.g., `$_POST['remove_main_image']`, `$_POST['remove_gallery_ids']`) to avoid overloading the delete-product flow.

2. **Main Image Removal Handler**
   - Look up the current `products.image` value for the targeted product ID.
   - Delete the stored file from disk (glob the `/main.*` file) using the existing `ensureProductImageDirectory()` helper for path safety. 【F:dgz_motorshop_system/admin/products.php†L10-L32】
   - Set the `products.image` column to `NULL` and log the update via any existing history mechanism, keeping parity with other product edits.

3. **Gallery Image Removal Handler**
   - Accept an array of gallery IDs (or file paths) selected in the UI.
   - Query the `product_images` table for matching rows tied to the product; delete the corresponding files on disk and remove the rows.
   - Re-pack `sort_order` if needed by re-numbering remaining rows to keep ordering sequential (optional but recommended for predictable rendering).

4. **Shared Considerations**
   - Wrap file deletions in error handling so missing files do not block database cleanup.
   - After deletion, redirect back to `products.php` with a success flash message consistent with other actions.

## Proposed Frontend/UI Changes
1. **Main Image Controls**
   - When editing a product, if a main image exists (`data-image` attribute), render a thumbnail with a "Remove" button or checkbox near the upload control.
   - On click, set a hidden input (e.g., `name="remove_main_image" value="1"`) and update the preview to show the placeholder image.
   - Disable the file input preview logic if removal is requested to avoid confusion.

2. **Gallery Image Controls**
   - Fetch and display the current gallery thumbnails in the edit modal (can reuse API `api/product-images.php` or embed data attributes on the row payload).
   - Provide remove icons or checkboxes per thumbnail; collect selected IDs in a hidden field (e.g., `remove_gallery_ids[]`).
   - Update the preview list in JavaScript to reflect pending deletions before form submission.

3. **User Feedback**
   - After submission, surface a toast or inline message indicating which images were removed.
   - Keep the upload inputs available so admins can remove and upload in the same edit action.

## Data and Filesystem Integrity
- Ensure the upload directory structure under `/uploads/products` remains intact by calling `ensureProductImageDirectory()` before any filesystem operations. 【F:dgz_motorshop_system/admin/products.php†L10-L32】
- Consider a maintenance script to prune orphaned files if any legacy data predates this logic.

## Testing Strategy
1. **Unit/Integration Tests** (if harness exists)
   - Simulate removing only the main image and verify the database column resets and the file is deleted.
   - Simulate removing multiple gallery images and confirm DB rows and files are removed, and sort order reflows.
2. **Manual QA**
   - Upload a main image and gallery set, then remove each via the new controls to ensure UI state updates correctly.
   - Attempt to remove an already-missing file to confirm graceful handling.
   - Verify staff users (non-admin) cannot see or trigger removal controls.

## Deployment Considerations
- No schema migrations required because existing tables already support null image references and cascade deletes.
- Communicate to administrators that deletion is permanent to avoid accidental removals.
