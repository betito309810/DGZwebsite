# DGZ Motorshop Codebase Deep Guide

Use this reference to walk into your defense knowing what every file contributes and how the application behaves. Entries highlight the purpose of each file, key behaviors inside, and call out the most important PHP and JavaScript routines as requested.

## Root Applications

### `about.php`
**Purpose:** Bootstraps shared config, loads storefront assets, and renders the About page with business details, FAQs, and Google Maps embed.【F:about.php†L1-L302】
**Key Behaviors:**
- Includes `config.php`, registers helpers, and preloads navigation/cart snippets shared with the catalog.【F:about.php†L1-L32】
- Builds the HTML layout with hero cards, contact tiles, FAQ accordion, and map iframe tailored for the defense walkthrough.【F:about.php†L59-L266】
- Enqueues public JavaScript modules (cart, search, mobile nav, filters, terms prompt) to keep the About page behavior consistent with the main store.【F:about.php†L270-L302】
*This explanation covers what the code is about and how it functions.*

### `api/product-images.php`
**Purpose:** JSON endpoint returning gallery metadata for a product so the modal viewer can hydrate thumbnails and fallbacks.【F:api/product-images.php†L1-L158】
**Key Behaviors:**
- Validates the incoming product ID and returns an error payload if missing.【F:api/product-images.php†L23-L48】
- Queries the product table, merges main image and uploaded gallery entries, and normalizes URLs for the frontend.【F:api/product-images.php†L50-L130】
- Emits structured JSON with HTTP response codes that the gallery script expects during variant changes.【F:api/product-images.php†L132-L158】
*This explanation covers what the code is about and how it functions.*

### `checkout.php`
**Purpose:** Handles the entire customer checkout workflow from cart validation to order persistence and inventory updates.【F:checkout.php†L1-L616】
**Key Behaviors:**
- Ensures required schema columns exist before processing (tracking codes, notes, proof fields).【F:checkout.php†L69-L165】
- Validates cart payloads, calculates totals, saves uploaded payment proofs, and inserts order + line item records in a single flow.【F:checkout.php†L166-L451】
- Renders the checkout UI with synchronized cart sidebar, forms for billing/delivery, and scripts for client-side updates.【F:checkout.php†L452-L616】
*This explanation covers what the code is about and how it functions.*

### `composer.json` / `composer.lock`
**Purpose:** Declare PHP dependencies (PHPMailer) and lock them to tested versions for consistent deployments.【F:composer.json†L1-L13】【F:composer.lock†L1-L98】
**Key Behaviors:**
- `composer.json` sets PHP version requirements and lists PHPMailer as the sole package dependency.【F:composer.json†L1-L13】
- `composer.lock` pins the exact PHPMailer version and metadata so installs reproduce the same vendor tree.【F:composer.lock†L1-L98】
*This explanation covers what the code is about and how it functions.*

### `index.php`
**Purpose:** Public storefront controller listing products, categories, and handling add-to-cart interactions.【F:index.php†L1-L315】
**Key Behaviors:**
- Loads shared helpers, fetches all active products, and organizes category filters and default variant data.【F:index.php†L1-L153】
- Constructs responsive catalog cards with modals, stock badges, and action buttons tied to JavaScript handlers.【F:index.php†L154-L291】
- Injects JavaScript path constants and enqueues cart/search/mobile scripts needed for the interactive shopping experience.【F:index.php†L292-L315】
*This explanation covers what the code is about and how it functions.*

### `order_status.php`
**Purpose:** Secured POST endpoint that returns normalized tracking updates for customers checking their orders.【F:order_status.php†L1-L233】
**Key Behaviors:**
- Sanitizes tracking codes, ensures the request is POST, and prevents walk-in orders from leaking via the tracker.【F:order_status.php†L18-L87】
- Loads order, customer, payment, and decline metadata before crafting human-friendly status messages.【F:order_status.php†L88-L204】
- Emits JSON with explicit status flags consumed by `track-order.js`.【F:order_status.php†L205-L233】
*This explanation covers what the code is about and how it functions.*

### `scripts/assign_online_order_tracking_codes.php`
**Purpose:** CLI maintenance script that backfills tracking codes for existing online orders lacking DGZ-format identifiers.【F:scripts/assign_online_order_tracking_codes.php†L1-L219】
**Key Behaviors:**
- Boots application config and checks schema prerequisites before modifying data.【F:scripts/assign_online_order_tracking_codes.php†L1-L63】
- Iterates eligible online orders, generates unique tracking codes, and updates records atomically.【F:scripts/assign_online_order_tracking_codes.php†L64-L193】
- Logs processing summaries and warnings to the console for auditing when run during defense rehearsals.【F:scripts/assign_online_order_tracking_codes.php†L194-L219】
*This explanation covers what the code is about and how it functions.*

### `test_db.php`
**Purpose:** Quick diagnostics script verifying that database credentials work in the current environment.【F:test_db.php†L1-L19】
**Key Behaviors:**
- Includes shared config, attempts a PDO connection, and prints success or failure messaging accordingly.【F:test_db.php†L1-L19】
*This explanation covers what the code is about and how it functions.*

### `ToDo.txt` / `TODO.md`
**Purpose:** Track pending enhancements, bug fixes, and roadmap items for the system.【F:ToDo.txt†L1-L8】【F:TODO.md†L1-L17】
**Key Behaviors:**
- Capture work such as pagination, CSV exports, email workflows, and UX refinements to discuss during defense planning.【F:ToDo.txt†L1-L8】【F:TODO.md†L1-L17】
*This explanation covers what the code is about and how it functions.*

### `track-order.php`
**Purpose:** Public page where customers submit tracking codes and view the timeline of their order status.【F:track-order.php†L1-L210】
**Key Behaviors:**
- Loads shared layout assets and renders tracker-specific form controls and instructions.【F:track-order.php†L1-L120】
- Displays dynamic status containers that the JavaScript module populates after API responses.【F:track-order.php†L121-L188】
- Enqueues cart/search/mobile navigation scripts alongside `track-order.js` and terms overlay helpers.【F:track-order.php†L189-L210】
*This explanation covers what the code is about and how it functions.*

## Shared Bootstrap & Helpers

### `dgz_motorshop_system/config/config.php`
**Purpose:** Core bootstrap that initializes environment settings, database access, helper registration, and URL utilities for the whole system.【F:dgz_motorshop_system/config/config.php†L1-L428】
**Key Behaviors:**
- Sets timezone, establishes PDO connection helpers, and defines root paths/URLs for asset referencing.【F:dgz_motorshop_system/config/config.php†L1-L180】
- Registers reusable helper functions (database queries, file uploads, slug creation, staff gating, flash messaging).【F:dgz_motorshop_system/config/config.php†L181-L356】
- Loads additional include files and enforces authentication boundaries for admin areas.【F:dgz_motorshop_system/config/config.php†L357-L428】
*This explanation covers what the code is about and how it functions.*

### `dgz_motorshop_system/config/mailer.php`
**Purpose:** Configure PHPMailer with SMTP credentials and expose helper wrappers for sending transactional emails.【F:dgz_motorshop_system/config/mailer.php†L1-L174】
**Key Behaviors:**
- Creates a PHPMailer instance preloaded with Gmail SMTP configuration and branding defaults.【F:dgz_motorshop_system/config/mailer.php†L1-L88】
- Provides `sendEmail` helper to attach files, handle exceptions, and log failures for debugging.【F:dgz_motorshop_system/config/mailer.php†L89-L174】
*This explanation covers what the code is about and how it functions.*

### `dgz_motorshop_system/includes/email.php`
**Purpose:** Higher-level email utilities used by workflows like order approval/decline and stock notifications.【F:dgz_motorshop_system/includes/email.php†L1-L222】
**Key Behaviors:**
- Validates recipient lists and subject/body content before invoking the lower-level mailer helper.【F:dgz_motorshop_system/includes/email.php†L1-L93】
- Supports attaching uploaded proofs, generated PDFs, or inline strings to outgoing messages.【F:dgz_motorshop_system/includes/email.php†L94-L176】
- Logs delivery results and surfaces errors to calling controllers for user feedback.【F:dgz_motorshop_system/includes/email.php†L177-L222】
*This explanation covers what the code is about and how it functions.*

### `dgz_motorshop_system/includes/product_variants.php`
**Purpose:** Shared variant helper routines for both storefront and POS logic.【F:dgz_motorshop_system/includes/product_variants.php†L1-L282】
**Key Behaviors:**
- Fetches product variants, aggregates stock and pricing, and determines default variant selections.【F:dgz_motorshop_system/includes/product_variants.php†L1-L164】
- Normalizes variant payloads into arrays the frontend expects, including option labels and stock flags.【F:dgz_motorshop_system/includes/product_variants.php†L165-L282】
*This explanation covers what the code is about and how it functions.*

## Admin Portal

### Controllers & Pages (`dgz_motorshop_system/admin/*.php`)

#### `chart_data.php`
**Purpose:** JSON endpoint providing top-selling product aggregates for dashboard charts.【F:dgz_motorshop_system/admin/chart_data.php†L1-L141】
**Key Behaviors:**
- Authenticates staff access, parses requested time range, and builds SQL queries for product totals.【F:dgz_motorshop_system/admin/chart_data.php†L1-L91】
- Returns label/data arrays ready for Chart.js consumption on the dashboard.【F:dgz_motorshop_system/admin/chart_data.php†L92-L141】
*This explanation covers what the code is about and how it functions.*

#### `dashboard.php`
**Purpose:** Admin landing page summarizing sales, inventory alerts, and notifications.【F:dgz_motorshop_system/admin/dashboard.php†L1-L314】
**Key Behaviors:**
- Requires authentication, computes daily sales totals, and loads low-stock notifications.【F:dgz_motorshop_system/admin/dashboard.php†L1-L180】
- Renders dashboard cards, charts, notification bell, and profile modal markup for staff.【F:dgz_motorshop_system/admin/dashboard.php†L181-L314】
*This explanation covers what the code is about and how it functions.*

#### `declineReasonsApi.php`
**Purpose:** AJAX API to manage decline reasons shared across the POS and order workflows.【F:dgz_motorshop_system/admin/declineReasonsApi.php†L1-L180】
**Key Behaviors:**
- Validates staff permissions, routes POST actions (create/update/delete), and sanitizes input.【F:dgz_motorshop_system/admin/declineReasonsApi.php†L1-L118】
- Returns refreshed decline reason lists after operations for UI updates.【F:dgz_motorshop_system/admin/declineReasonsApi.php†L119-L180】
*This explanation covers what the code is about and how it functions.*

#### `forgot_password.php`
**Purpose:** Handles password reset requests by generating tokens and emailing reset links.【F:dgz_motorshop_system/admin/forgot_password.php†L1-L208】
**Key Behaviors:**
- Processes submitted emails, creates secure tokens, and stores expiry timestamps.【F:dgz_motorshop_system/admin/forgot_password.php†L1-L126】
- Sends reset instructions via shared email helpers and displays success/error messaging.【F:dgz_motorshop_system/admin/forgot_password.php†L127-L208】
*This explanation covers what the code is about and how it functions.*

#### `get_transaction_details.php`
**Purpose:** Returns detailed transaction data for modal views in sales reports.【F:dgz_motorshop_system/admin/get_transaction_details.php†L1-L163】
**Key Behaviors:**
- Verifies staff session, fetches order header/items/payments, and structures JSON response.【F:dgz_motorshop_system/admin/get_transaction_details.php†L1-L163】
*This explanation covers what the code is about and how it functions.*

#### `inventory.php`
**Purpose:** Admin inventory management page for product lists, stock adjustments, and history views.【F:dgz_motorshop_system/admin/inventory.php†L1-L489】
**Key Behaviors:**
- Loads product datasets, stock history, and low-stock alerts into view models.【F:dgz_motorshop_system/admin/inventory.php†L1-L250】
- Renders filters, tables, modal dialogs, and includes supporting JavaScript/CSS assets.【F:dgz_motorshop_system/admin/inventory.php†L251-L489】
*This explanation covers what the code is about and how it functions.*

#### `login.php`
**Purpose:** Admin authentication controller with remember-me and session handling.【F:dgz_motorshop_system/admin/login.php†L1-L223】
**Key Behaviors:**
- Processes login form submissions, verifies credentials, and sets session/cookie tokens.【F:dgz_motorshop_system/admin/login.php†L1-L150】
- Handles remember-me logic, redirects on success, and displays validation errors.【F:dgz_motorshop_system/admin/login.php†L151-L223】
*This explanation covers what the code is about and how it functions.*

#### `markNotificationsRead.php`
**Purpose:** Marks inventory notifications as read for the active staff user via AJAX.【F:dgz_motorshop_system/admin/markNotificationsRead.php†L1-L96】
**Key Behaviors:**
- Validates logged-in user, updates notification status rows, and returns JSON feedback.【F:dgz_motorshop_system/admin/markNotificationsRead.php†L1-L96】
*This explanation covers what the code is about and how it functions.*

#### `onlineOrdersFeed.php`
**Purpose:** Provides paginated HTML snippets of online orders for the POS dashboard list.【F:dgz_motorshop_system/admin/onlineOrdersFeed.php†L1-L246】
**Key Behaviors:**
- Parses filters (status, search, pagination), queries orders, and renders partial templates for each entry.【F:dgz_motorshop_system/admin/onlineOrdersFeed.php†L1-L186】
- Outputs navigation controls and badges the POS uses when auto-refreshing lists.【F:dgz_motorshop_system/admin/onlineOrdersFeed.php†L187-L246】
*This explanation covers what the code is about and how it functions.*

#### `orderDisapprove.php`
**Purpose:** Backend action for declining online orders, logging reasons, and notifying customers.【F:dgz_motorshop_system/admin/orderDisapprove.php†L1-L185】
**Key Behaviors:**
- Validates permissions, records decline reasons/status changes, and updates inventory if needed.【F:dgz_motorshop_system/admin/orderDisapprove.php†L1-L132】
- Sends decline emails using shared helpers and returns response messages to the POS UI.【F:dgz_motorshop_system/admin/orderDisapprove.php†L133-L185】
*This explanation covers what the code is about and how it functions.*

#### `order_details.php`
**Purpose:** Detailed admin view of a single order including customer info, items, and payment proof.【F:dgz_motorshop_system/admin/order_details.php†L1-L188】
**Key Behaviors:**
- Pulls order + line item data, attaches decline metadata, and exposes download links for payment proofs.【F:dgz_motorshop_system/admin/order_details.php†L1-L150】
- Renders modal-friendly markup for staff review with status badges and notes.【F:dgz_motorshop_system/admin/order_details.php†L151-L188】
*This explanation covers what the code is about and how it functions.*

#### `orders.php`
**Purpose:** Module for logging supplier orders/restocks and reviewing history.【F:dgz_motorshop_system/admin/orders.php†L1-L287】
**Key Behaviors:**
- Provides forms to add new incoming stock entries tied to staff members.【F:dgz_motorshop_system/admin/orders.php†L1-L142】
- Lists historical restock orders with cost summaries and user attribution.【F:dgz_motorshop_system/admin/orders.php†L143-L287】
*This explanation covers what the code is about and how it functions.*

#### `pos.php`
**Purpose:** Comprehensive POS and online order management console for staff.【F:dgz_motorshop_system/admin/pos.php†L1-L676】
**Key Behaviors:**
- Ensures schema readiness, loads online orders, variants, and staff permissions into memory.【F:dgz_motorshop_system/admin/pos.php†L1-L258】
- Handles approve/decline actions, generates PDF receipts, emails customers, and records activity logs.【F:dgz_motorshop_system/admin/pos.php†L259-L544】
- Renders the POS UI including order lists, detail modals, and supporting assets for real-time workflows.【F:dgz_motorshop_system/admin/pos.php†L545-L676】
*This explanation covers what the code is about and how it functions.*

#### `products.php`
**Purpose:** Admin product management interface with CRUD modals, variant handling, and Facebook sync hooks.【F:dgz_motorshop_system/admin/products.php†L1-L640】
**Key Behaviors:**
- Loads product/variant datasets, category filters, and stock summaries for display.【F:dgz_motorshop_system/admin/products.php†L1-L290】
- Renders modals for adding/editing products, managing variants, and uploading images with validations.【F:dgz_motorshop_system/admin/products.php†L291-L544】
- Includes JavaScript and CSS assets that handle table filtering, variant forms, and Facebook synchronization buttons.【F:dgz_motorshop_system/admin/products.php†L545-L640】
*This explanation covers what the code is about and how it functions.*

#### `reset_password.php`
**Purpose:** Implements the reset-password landing page where users choose a new password using a token.【F:dgz_motorshop_system/admin/reset_password.php†L1-L255】
**Key Behaviors:**
- Validates reset tokens, checks expiration, and loads the associated staff account.【F:dgz_motorshop_system/admin/reset_password.php†L1-L140】
- Updates credentials, clears token metadata, and reports success or failure states to the UI.【F:dgz_motorshop_system/admin/reset_password.php†L141-L255】
*This explanation covers what the code is about and how it functions.*

#### `sales.php`
**Purpose:** Presents sales analytics filters, KPI cards, and tables for financial reporting.【F:dgz_motorshop_system/admin/sales.php†L1-L352】
**Key Behaviors:**
- Builds filter forms for date ranges and quick-select periods and wires them to AJAX scripts.【F:dgz_motorshop_system/admin/sales.php†L1-L176】
- Renders KPI summary cards, transaction tables, and export modals with necessary asset includes.【F:dgz_motorshop_system/admin/sales.php†L177-L352】
*This explanation covers what the code is about and how it functions.*

#### `sales_api.php`
**Purpose:** Backend endpoint that responds to sales filter submissions with aggregated metrics.【F:dgz_motorshop_system/admin/sales_api.php†L1-L250】
**Key Behaviors:**
- Validates staff access, parses date ranges, and computes totals/revenue counts.【F:dgz_motorshop_system/admin/sales_api.php†L1-L154】
- Returns JSON payloads consumed by analytics charts and KPI cards.【F:dgz_motorshop_system/admin/sales_api.php†L155-L250】
*This explanation covers what the code is about and how it functions.*

#### `sales_report_pdf.php`
**Purpose:** Generates downloadable PDF sales reports via Dompdf using submitted filters.【F:dgz_motorshop_system/admin/sales_report_pdf.php†L1-L195】
**Key Behaviors:**
- Boots configuration, collects filtered sales data, and renders HTML markup for PDF output.【F:dgz_motorshop_system/admin/sales_report_pdf.php†L1-L125】
- Streams the generated PDF back to the browser with proper headers for download.【F:dgz_motorshop_system/admin/sales_report_pdf.php†L126-L195】
*This explanation covers what the code is about and how it functions.*

#### `settings.php`
**Purpose:** Staff profile/settings page for updating personal information, passwords, and branch details.【F:dgz_motorshop_system/admin/settings.php†L1-L359】
**Key Behaviors:**
- Loads current user profile, contact details, and branch metadata for editing.【F:dgz_motorshop_system/admin/settings.php†L1-L198】
- Provides forms for profile updates, password changes, and branch contact configurations with validation feedback.【F:dgz_motorshop_system/admin/settings.php†L199-L359】
*This explanation covers what the code is about and how it functions.*

#### `stockEntry.php`
**Purpose:** Records new stock entries from suppliers and manages itemized lines.【F:dgz_motorshop_system/admin/stockEntry.php†L1-L370】
**Key Behaviors:**
- Presents forms to log supplier, invoice, and line-item details tied to the authenticated staff user.【F:dgz_motorshop_system/admin/stockEntry.php†L1-L224】
- Shows historical entries, totals, and PDF generation links for receipts.【F:dgz_motorshop_system/admin/stockEntry.php†L225-L370】
*This explanation covers what the code is about and how it functions.*

#### `stockReceiptView.php`
**Purpose:** Printable view of stock receipts with supplier and item breakdowns.【F:dgz_motorshop_system/admin/stockReceiptView.php†L1-L210】
**Key Behaviors:**
- Fetches receipt header/items, ensures permission checks, and formats the printable layout.【F:dgz_motorshop_system/admin/stockReceiptView.php†L1-L184】
- Outputs totals and staff acknowledgement ready for printing or PDF export.【F:dgz_motorshop_system/admin/stockReceiptView.php†L185-L210】
*This explanation covers what the code is about and how it functions.*

#### `stockRequests.php`
**Purpose:** Manages internal stock request submissions, approvals, and history listings.【F:dgz_motorshop_system/admin/stockRequests.php†L1-L369】
**Key Behaviors:**
- Displays request forms, pending approval lists, and action buttons for status changes.【F:dgz_motorshop_system/admin/stockRequests.php†L1-L224】
- Renders history tables and attaches JavaScript for filtering and modal interactions.【F:dgz_motorshop_system/admin/stockRequests.php†L225-L369】
*This explanation covers what the code is about and how it functions.*

#### `userManagement.php`
**Purpose:** Staff management shell that loads helpers and embeds user CRUD modals/components.【F:dgz_motorshop_system/admin/userManagement.php†L1-L229】
**Key Behaviors:**
- Gathers staff lists, role permissions, and decline reason hooks for display.【F:dgz_motorshop_system/admin/userManagement.php†L1-L136】
- Renders tables, action buttons, and includes JS/CSS assets powering CRUD workflows.【F:dgz_motorshop_system/admin/userManagement.php†L137-L229】
*This explanation covers what the code is about and how it functions.*

### Admin Partials & Helpers

#### `partials/notification_menu.php`
**Purpose:** Builds the notification dropdown showing low-stock alerts with time-ago labels.【F:dgz_motorshop_system/admin/partials/notification_menu.php†L1-L147】
**Key Behaviors:**
- Iterates notifications, renders badges/timestamps, and embeds mark-as-read triggers.【F:dgz_motorshop_system/admin/partials/notification_menu.php†L1-L147】
*This explanation covers what the code is about and how it functions.*

#### `partials/user_management_section.php`
**Purpose:** Renders the staff management table and modals used within `userManagement.php`.【F:dgz_motorshop_system/admin/partials/user_management_section.php†L1-L232】
**Key Behaviors:**
- Outputs table rows with role-based actions, modal markup, and password reset hooks.【F:dgz_motorshop_system/admin/partials/user_management_section.php†L1-L232】
*This explanation covers what the code is about and how it functions.*

#### `includes/decline_reasons.php`
**Purpose:** Helper functions for creating/updating/deleting decline reasons shared between API and UI.【F:dgz_motorshop_system/admin/includes/decline_reasons.php†L1-L182】
**Key Behaviors:**
- Ensures schema table exists, handles CRUD operations, and returns structured reason lists.【F:dgz_motorshop_system/admin/includes/decline_reasons.php†L1-L182】
*This explanation covers what the code is about and how it functions.*

#### `includes/inventory_notifications.php`
**Purpose:** Centralizes low-stock notification logic for dashboard and POS alerts.【F:dgz_motorshop_system/admin/includes/inventory_notifications.php†L1-L208】
**Key Behaviors:**
- Ensures schema columns, computes unread counts, and fetches latest notifications for display.【F:dgz_motorshop_system/admin/includes/inventory_notifications.php†L1-L208】
*This explanation covers what the code is about and how it functions.*

#### `includes/online_orders_helpers.php`
**Purpose:** Provides filtering and pagination helpers for online order queues in the POS.【F:dgz_motorshop_system/admin/includes/online_orders_helpers.php†L1-L302】
**Key Behaviors:**
- Builds query clauses based on status/search filters and staff permissions.【F:dgz_motorshop_system/admin/includes/online_orders_helpers.php†L1-L206】
- Computes counts, pending attention metrics, and returns paginated datasets to controllers.【F:dgz_motorshop_system/admin/includes/online_orders_helpers.php†L207-L302】
*This explanation covers what the code is about and how it functions.*

#### `includes/sales_periods.php`
**Purpose:** Maps daily/weekly/monthly/annual selections to actual date ranges for reports.【F:dgz_motorshop_system/admin/includes/sales_periods.php†L1-L210】
**Key Behaviors:**
- Provides helper functions that convert filter options into start/end dates and human-readable labels.【F:dgz_motorshop_system/admin/includes/sales_periods.php†L1-L210】
*This explanation covers what the code is about and how it functions.*

#### `includes/sidebar.php`
**Purpose:** Generates the admin sidebar navigation with active link tracking and role-based visibility.【F:dgz_motorshop_system/admin/includes/sidebar.php†L1-L205】
**Key Behaviors:**
- Iterates sidebar items, marks active page, and respects staff permissions to hide restricted links.【F:dgz_motorshop_system/admin/includes/sidebar.php†L1-L205】
*This explanation covers what the code is about and how it functions.*

#### `includes/stock_receipt_helpers.php`
**Purpose:** Helper routines for creating stock receipts and retrieving detailed breakdowns.【F:dgz_motorshop_system/admin/includes/stock_receipt_helpers.php†L1-L238】
**Key Behaviors:**
- Ensures receipt tables exist, inserts receipt headers/items, and computes totals for display.【F:dgz_motorshop_system/admin/includes/stock_receipt_helpers.php†L1-L238】
*This explanation covers what the code is about and how it functions.*

#### `includes/user_management_data.php`
**Purpose:** Data access layer for staff management, exposing CRUD operations and role validations.【F:dgz_motorshop_system/admin/includes/user_management_data.php†L1-L271】
**Key Behaviors:**
- Fetches staff lists, enforces role hierarchy, and prepares datasets for UI rendering.【F:dgz_motorshop_system/admin/includes/user_management_data.php†L1-L192】
- Implements create/update/delete routines with validation and error handling.【F:dgz_motorshop_system/admin/includes/user_management_data.php†L193-L271】
*This explanation covers what the code is about and how it functions.*

## JavaScript Modules (`dgz_motorshop_system/assets/js`)

### Dashboard

#### `dashboard/topSellingFilters.js`
**Purpose:** Toggles filter buttons and submits forms to refresh top-selling charts.【F:dgz_motorshop_system/assets/js/dashboard/topSellingFilters.js†L1-L111】
**Key Behaviors:**
- Listens for button clicks, updates active classes, and triggers chart reload form submissions.【F:dgz_motorshop_system/assets/js/dashboard/topSellingFilters.js†L1-L111】
*This explanation covers what the code is about and how it functions.*

#### `dashboard/userMenu.js`
**Purpose:** Controls the admin header user menu, profile modal, and mobile sidebar toggling.【F:dgz_motorshop_system/assets/js/dashboard/userMenu.js†L1-L167】
**Key Behaviors:**
- Manages dropdown open/close states, handles focus trapping, and wires hamburger toggles for responsive layouts.【F:dgz_motorshop_system/assets/js/dashboard/userMenu.js†L1-L167】
*This explanation covers what the code is about and how it functions.*

### Inventory

#### `inventory/inventoryMain.js`
**Purpose:** Drives inventory page interactions including search, filters, and modal navigation.【F:dgz_motorshop_system/assets/js/inventory/inventoryMain.js†L1-L281】
**Key Behaviors:**
- Implements debounced search, category toggles, and tabbed modal switching for product history and adjustments.【F:dgz_motorshop_system/assets/js/inventory/inventoryMain.js†L1-L281】
*This explanation covers what the code is about and how it functions.*

#### `inventory/stockEntry.js`
**Purpose:** Handles dynamic stock entry forms by adding/removing rows and computing totals.【F:dgz_motorshop_system/assets/js/inventory/stockEntry.js†L1-L225】
**Key Behaviors:**
- Listens for add/remove item actions, auto-calculates totals, and validates required fields before submission.【F:dgz_motorshop_system/assets/js/inventory/stockEntry.js†L1-L225】
*This explanation covers what the code is about and how it functions.*

#### `inventory/stockRequest.js`
**Purpose:** Powers stock request modals, approvals, and list refreshes in the inventory module.【F:dgz_motorshop_system/assets/js/inventory/stockRequest.js†L1-L230】
**Key Behaviors:**
- Populates request details, sends fetch requests for approvals/declines, and re-renders tables on completion.【F:dgz_motorshop_system/assets/js/inventory/stockRequest.js†L1-L230】
*This explanation covers what the code is about and how it functions.*

### Login & Authentication

#### `login/reset-password.js`
**Purpose:** Adds password strength validation and toggle visibility controls on reset forms.【F:dgz_motorshop_system/assets/js/login/reset-password.js†L1-L117】
**Key Behaviors:**
- Validates password confirmation, enforces minimum criteria, and switches input types for show/hide interactions.【F:dgz_motorshop_system/assets/js/login/reset-password.js†L1-L117】
*This explanation covers what the code is about and how it functions.*

### Notifications & Shared Helpers

#### `notifications.js`
**Purpose:** Handles notification dropdown fetch/update cycles and badge counts across admin pages.【F:dgz_motorshop_system/assets/js/notifications.js†L1-L168】
**Key Behaviors:**
- Polls the server for updates, posts mark-as-read actions, and refreshes DOM badges accordingly.【F:dgz_motorshop_system/assets/js/notifications.js†L1-L168】
*This explanation covers what the code is about and how it functions.*

### POS

#### `pos/orderDecline.js`
**Purpose:** Manages the decline order modal, validation, and AJAX submission from the POS console.【F:dgz_motorshop_system/assets/js/pos/orderDecline.js†L1-L178】
**Key Behaviors:**
- Toggles decline modals, validates selected reasons/notes, and posts decline requests to the backend.【F:dgz_motorshop_system/assets/js/pos/orderDecline.js†L1-L178】
*This explanation covers what the code is about and how it functions.*

#### `pos/posMain.js`
**Purpose:** Coordinates the broader POS workflow including polling, approvals, and UI state changes.【F:dgz_motorshop_system/assets/js/pos/posMain.js†L1-L424】
**Key Behaviors:**
- Handles order selection, auto-refresh timers, search filters, and dispatches approve/decline actions.【F:dgz_motorshop_system/assets/js/pos/posMain.js†L1-L424】
*This explanation covers what the code is about and how it functions.*

### Product Management

#### `products/editModal.js`
**Purpose:** Prefills the product edit modal with current data and submits updates via fetch.【F:dgz_motorshop_system/assets/js/products/editModal.js†L1-L274】
**Key Behaviors:**
- Loads selected product info, populates form inputs, manages variant lists, and sends updates to the API.【F:dgz_motorshop_system/assets/js/products/editModal.js†L1-L274】
*This explanation covers what the code is about and how it functions.*

#### `products/fbSynchroniser.js`
**Purpose:** Interfaces with Facebook Shop synchronization endpoints to push product data.【F:dgz_motorshop_system/assets/js/products/fbSynchroniser.js†L1-L179】
**Key Behaviors:**
- Sends fetch requests to sync endpoints, updates UI status indicators, and reports success/failure to staff.【F:dgz_motorshop_system/assets/js/products/fbSynchroniser.js†L1-L179】
*This explanation covers what the code is about and how it functions.*

#### `products/historyDOM.js`
**Purpose:** Renders product history timelines, expanding/collapsing adjustment entries on demand.【F:dgz_motorshop_system/assets/js/products/historyDOM.js†L1-L178】
**Key Behaviors:**
- Builds DOM nodes for history items, toggles visibility, and animates timeline sections for clarity.【F:dgz_motorshop_system/assets/js/products/historyDOM.js†L1-L178】
*This explanation covers what the code is about and how it functions.*

#### `products/tableFilters.js`
**Purpose:** Adds search, filter, and sort controls to the product list table.【F:dgz_motorshop_system/assets/js/products/tableFilters.js†L1-L220】
**Key Behaviors:**
- Debounces search inputs, handles category/stock toggles, and resets filters when requested.【F:dgz_motorshop_system/assets/js/products/tableFilters.js†L1-L220】
*This explanation covers what the code is about and how it functions.*

#### `products/variantsForm.js`
**Purpose:** Manages dynamic variant rows and default selections inside add/edit product modals.【F:dgz_motorshop_system/assets/js/products/variantsForm.js†L1-L249】
**Key Behaviors:**
- Adds/removes variant rows, enforces one default selection, and validates SKU/stock inputs before submit.【F:dgz_motorshop_system/assets/js/products/variantsForm.js†L1-L249】
*This explanation covers what the code is about and how it functions.*

### Public Storefront Scripts

#### `public/aboutCart.js`
**Purpose:** Keeps the About page cart badge and navigation state synchronized with global cart data.【F:dgz_motorshop_system/assets/js/public/aboutCart.js†L1-L112】
**Key Behaviors:**
- Reads localStorage cart entries, updates header badge counts, and binds menu toggle listeners.【F:dgz_motorshop_system/assets/js/public/aboutCart.js†L1-L112】
*This explanation covers what the code is about and how it functions.*

#### `public/cart.js`
**Purpose:** Core cart manager handling add-to-cart, buy-now, badge counts, and checkout redirects.【F:dgz_motorshop_system/assets/js/public/cart.js†L1-L160】
**Key Behaviors:**
- Persists cart state in localStorage, updates UI badges, and prepares payloads for checkout submissions.【F:dgz_motorshop_system/assets/js/public/cart.js†L1-L160】
*This explanation covers what the code is about and how it functions.*

#### `public/mobileFilters.js`
**Purpose:** Controls the mobile filter toolbar for the product catalog including sort sheets and sidebar focus.【F:dgz_motorshop_system/assets/js/public/mobileFilters.js†L1-L276】
**Key Behaviors:**
- Manages sidebar open/close states, applies filter selections, and handles keyboard accessibility for overlays.【F:dgz_motorshop_system/assets/js/public/mobileFilters.js†L1-L276】
*This explanation covers what the code is about and how it functions.*

#### `public/mobileNav.js`
**Purpose:** Handles mobile navigation toggles, backdrop management, and accessibility hooks for the storefront header.【F:dgz_motorshop_system/assets/js/public/mobileNav.js†L1-L254】
**Key Behaviors:**
- Toggles navigation menus, traps focus within drawers, and updates ARIA attributes for accessibility compliance.【F:dgz_motorshop_system/assets/js/public/mobileNav.js†L1-L254】
*This explanation covers what the code is about and how it functions.*

#### `public/productGallery.js`
**Purpose:** Powers the product modal gallery including remote image loading, slideshow controls, and variant enforcement.【F:dgz_motorshop_system/assets/js/public/productGallery.js†L1-L455】
**Key Behaviors:**
- Fetches product images via API, populates slides, and syncs variant selection with stock availability.【F:dgz_motorshop_system/assets/js/public/productGallery.js†L1-L287】
- Handles slideshow navigation, keyboard controls, and purchase button enable/disable logic.【F:dgz_motorshop_system/assets/js/public/productGallery.js†L288-L455】
*This explanation covers what the code is about and how it functions.*

#### `public/search.js`
**Purpose:** Synchronizes search fields, category filters, and scroll behavior across the storefront.【F:dgz_motorshop_system/assets/js/public/search.js†L1-L266】
**Key Behaviors:**
- Updates results when search terms or categories change, and scrolls to highlighted sections smoothly.【F:dgz_motorshop_system/assets/js/public/search.js†L1-L266】
*This explanation covers what the code is about and how it functions.*

#### `public/termsNotice.js`
**Purpose:** Displays a one-time terms-and-conditions notice and stores acceptance in localStorage.【F:dgz_motorshop_system/assets/js/public/termsNotice.js†L1-L196】
**Key Behaviors:**
- Shows overlay on first visit, tracks acceptance timestamps, and restores focus after dismissal.【F:dgz_motorshop_system/assets/js/public/termsNotice.js†L1-L196】
*This explanation covers what the code is about and how it functions.*

#### `public/track-order.js`
**Purpose:** Submits tracking codes, handles loading/error states, and renders order progress to the customer.【F:dgz_motorshop_system/assets/js/public/track-order.js†L1-L214】
**Key Behaviors:**
- Validates input, posts to `order_status.php`, and updates DOM elements with returned status details.【F:dgz_motorshop_system/assets/js/public/track-order.js†L1-L214】
*This explanation covers what the code is about and how it functions.*

### Sales Analytics

#### `sales/periodFilters.js`
**Purpose:** Keeps the sales filter UI synchronized with selected time ranges and quick presets.【F:dgz_motorshop_system/assets/js/sales/periodFilters.js†L1-L167】
**Key Behaviors:**
- Updates form fields when preset buttons change, ensuring the backend receives correct date ranges.【F:dgz_motorshop_system/assets/js/sales/periodFilters.js†L1-L167】
*This explanation covers what the code is about and how it functions.*

#### `sales/pieChart.js`
**Purpose:** Initializes and updates the sales pie chart using Chart.js data from the backend.【F:dgz_motorshop_system/assets/js/sales/pieChart.js†L1-L159】
**Key Behaviors:**
- Configures chart options, injects dataset labels/colors, and refreshes chart instances on demand.【F:dgz_motorshop_system/assets/js/sales/pieChart.js†L1-L159】
*This explanation covers what the code is about and how it functions.*

#### `sales/salesAnalytics.js`
**Purpose:** Fetches filtered sales metrics, updates KPI cards, and handles loading/error visuals.【F:dgz_motorshop_system/assets/js/sales/salesAnalytics.js†L1-L273】
**Key Behaviors:**
- Performs AJAX requests to `sales_api.php`, injects numbers into the dashboard, and controls spinner states.【F:dgz_motorshop_system/assets/js/sales/salesAnalytics.js†L1-L273】
*This explanation covers what the code is about and how it functions.*

#### `sales/salesReportModal.js`
**Purpose:** Manages the sales report export modal, validating date ranges before PDF generation.【F:dgz_motorshop_system/assets/js/sales/salesReportModal.js†L1-L148】
**Key Behaviors:**
- Opens/closes the modal, enforces start/end date logic, and submits the export form when valid.【F:dgz_motorshop_system/assets/js/sales/salesReportModal.js†L1-L148】
*This explanation covers what the code is about and how it functions.*

#### `sales/salesSearch.js`
**Purpose:** Implements live search and pagination for the sales transaction table.【F:dgz_motorshop_system/assets/js/sales/salesSearch.js†L1-L148】
**Key Behaviors:**
- Debounces input, fetches filtered results, and replaces table contents without full page reloads.【F:dgz_motorshop_system/assets/js/sales/salesSearch.js†L1-L148】
*This explanation covers what the code is about and how it functions.*

#### `sales/transactionModal.js`
**Purpose:** Loads detailed transaction info into a modal for staff inspection.【F:dgz_motorshop_system/assets/js/sales/transactionModal.js†L1-L178】
**Key Behaviors:**
- Fetches JSON details, populates modal templates, and cleans up listeners when closed.【F:dgz_motorshop_system/assets/js/sales/transactionModal.js†L1-L178】
*This explanation covers what the code is about and how it functions.*

### Shared Transaction Helper

#### `transaction-details.js`
**Purpose:** Shared helper fetching transaction details and rendering receipt-style markup across modules.【F:dgz_motorshop_system/assets/js/transaction-details.js†L1-L205】
**Key Behaviors:**
- Abstracts fetch/render logic so multiple pages (sales, POS) can reuse consistent receipt displays.【F:dgz_motorshop_system/assets/js/transaction-details.js†L1-L205】
*This explanation covers what the code is about and how it functions.*

### User Management

#### `users/userManagement.js`
**Purpose:** Handles staff CRUD modals, role restrictions, and table refresh behavior in user management.【F:dgz_motorshop_system/assets/js/users/userManagement.js†L1-L348】
**Key Behaviors:**
- Opens add/edit dialogs, validates inputs, submits AJAX requests, and updates the staff table on success.【F:dgz_motorshop_system/assets/js/users/userManagement.js†L1-L348】
*This explanation covers what the code is about and how it functions.*

## Stylesheets (`dgz_motorshop_system/assets/css`)

### Global & Layout

#### `style.css`
**Purpose:** Global admin styling covering layout, typography, and component themes.【F:dgz_motorshop_system/assets/css/style.css†L1-L301】
**Key Behaviors:**
- Defines foundational admin shell styles for headers, sidebars, cards, tables, and responsive breakpoints.【F:dgz_motorshop_system/assets/css/style.css†L1-L301】
*This explanation covers what the code is about and how it functions.*

### Inventory Styles

#### `inventory/inventory.css`, `inventory/restockRequest.css`, `inventory/stockEntry.css`, `inventory/stockRequests.css`
**Purpose:** Style inventory dashboards, forms, and request workflows for clarity during demos.【F:dgz_motorshop_system/assets/css/inventory/inventory.css†L1-L213】【F:dgz_motorshop_system/assets/css/inventory/restockRequest.css†L1-L120】【F:dgz_motorshop_system/assets/css/inventory/stockEntry.css†L1-L164】【F:dgz_motorshop_system/assets/css/inventory/stockRequests.css†L1-L160】
**Key Behaviors:**
- Provide grid layouts, modal spacing, status badge colors, and responsive tweaks for inventory modules.【F:dgz_motorshop_system/assets/css/inventory/inventory.css†L1-L213】【F:dgz_motorshop_system/assets/css/inventory/restockRequest.css†L1-L120】【F:dgz_motorshop_system/assets/css/inventory/stockEntry.css†L1-L164】【F:dgz_motorshop_system/assets/css/inventory/stockRequests.css†L1-L160】
*This explanation covers what the code is about and how it functions.*

### POS Styles

#### `pos/pos.css`
**Purpose:** Styles the POS console cards, tables, and modals for staff usability.【F:dgz_motorshop_system/assets/css/pos/pos.css†L1-L219】
**Key Behaviors:**
- Defines layout, status coloring, and responsive columns for the order queue and action modals.【F:dgz_motorshop_system/assets/css/pos/pos.css†L1-L219】
*This explanation covers what the code is about and how it functions.*

### Login Styles

#### `login/login.css`
**Purpose:** Theme for login and reset screens including gradients and form styling.【F:dgz_motorshop_system/assets/css/login/login.css†L1-L143】
**Key Behaviors:**
- Applies branding colors, background imagery, and button states for authentication pages.【F:dgz_motorshop_system/assets/css/login/login.css†L1-L143】
*This explanation covers what the code is about and how it functions.*

### Product Management Styles

#### `products/products.css`, `products/product_modals.css`, `products/products_table_new.css`, `products/variants.css`, `products/products_history.css`
**Purpose:** Provide responsive layouts and theming for product tables, modals, variant cards, and history timelines.【F:dgz_motorshop_system/assets/css/products/products.css†L1-L244】【F:dgz_motorshop_system/assets/css/products/product_modals.css†L1-L208】【F:dgz_motorshop_system/assets/css/products/products_table_new.css†L1-L189】【F:dgz_motorshop_system/assets/css/products/variants.css†L1-L164】【F:dgz_motorshop_system/assets/css/products/products_history.css†L1-L161】
**Key Behaviors:**
- Control table density, modal layouts, variant option grids, and history timeline visuals for demos.【F:dgz_motorshop_system/assets/css/products/products.css†L1-L244】【F:dgz_motorshop_system/assets/css/products/product_modals.css†L1-L208】【F:dgz_motorshop_system/assets/css/products/products_table_new.css†L1-L189】【F:dgz_motorshop_system/assets/css/products/variants.css†L1-L164】【F:dgz_motorshop_system/assets/css/products/products_history.css†L1-L161】
*This explanation covers what the code is about and how it functions.*

### User Management Styles

#### `users/userManagement.css`
**Purpose:** Styles for staff management tables, action buttons, and modals.【F:dgz_motorshop_system/assets/css/users/userManagement.css†L1-L181】
**Key Behaviors:**
- Defines layout, spacing, and status cues that support the CRUD workflows during defense demonstrations.【F:dgz_motorshop_system/assets/css/users/userManagement.css†L1-L181】
*This explanation covers what the code is about and how it functions.*

### Dashboard Styles

#### `dashboard/dashboard.css`
**Purpose:** Visual styling for dashboard KPI cards, chart containers, and notification elements.【F:dgz_motorshop_system/assets/css/dashboard/dashboard.css†L1-L210】
**Key Behaviors:**
- Shapes card grids, typography, and responsive behavior for the admin home view.【F:dgz_motorshop_system/assets/css/dashboard/dashboard.css†L1-L210】
*This explanation covers what the code is about and how it functions.*

### Sales Styles

#### `sales/sales.css`, `sales/modal.css`, `sales/transaction-modal.css`, `sales/piechart.css`
**Purpose:** Style the sales analytics pages, export modals, and chart layouts.【F:dgz_motorshop_system/assets/css/sales/sales.css†L1-L202】【F:dgz_motorshop_system/assets/css/sales/modal.css†L1-L118】【F:dgz_motorshop_system/assets/css/sales/transaction-modal.css†L1-L147】【F:dgz_motorshop_system/assets/css/sales/piechart.css†L1-L93】
**Key Behaviors:**
- Provide consistent spacing, typography, and modal presentation supporting analytics workflows.【F:dgz_motorshop_system/assets/css/sales/sales.css†L1-L202】【F:dgz_motorshop_system/assets/css/sales/modal.css†L1-L118】【F:dgz_motorshop_system/assets/css/sales/transaction-modal.css†L1-L147】【F:dgz_motorshop_system/assets/css/sales/piechart.css†L1-L93】
*This explanation covers what the code is about and how it functions.*

### Public Storefront Styles

#### `public/index.css`, `public/about.css`, `public/faq.css`, `public/checkout.css`, `public/checkoutModals.css`, `public/track-order.css`
**Purpose:** Customer-facing styles for landing, about, FAQ, checkout, and tracking pages.【F:dgz_motorshop_system/assets/css/public/index.css†L1-L381】【F:dgz_motorshop_system/assets/css/public/about.css†L1-L301】【F:dgz_motorshop_system/assets/css/public/faq.css†L1-L169】【F:dgz_motorshop_system/assets/css/public/checkout.css†L1-L437】【F:dgz_motorshop_system/assets/css/public/checkoutModals.css†L1-L112】【F:dgz_motorshop_system/assets/css/public/track-order.css†L1-L206】
**Key Behaviors:**
- Define responsive grids, typography, modal styling, and accent colors for the storefront experience.【F:dgz_motorshop_system/assets/css/public/index.css†L1-L381】【F:dgz_motorshop_system/assets/css/public/about.css†L1-L301】【F:dgz_motorshop_system/assets/css/public/faq.css†L1-L169】【F:dgz_motorshop_system/assets/css/public/checkout.css†L1-L437】【F:dgz_motorshop_system/assets/css/public/checkoutModals.css†L1-L112】【F:dgz_motorshop_system/assets/css/public/track-order.css†L1-L206】
*This explanation covers what the code is about and how it functions.*

## Supporting Assets & Scripts

### Images (`dgz_motorshop_system/assets/img`)
**Purpose:** Graphic assets like logos, QR codes, and product placeholders used across the UI.【F:dgz_motorshop_system/assets/img/product-placeholder.svg†L1-L19】【F:index.php†L8-L16】【F:checkout.php†L10-L15】
**Key Behaviors:**
- Serve as branding visuals in headers, checkout instructions, and product cards, ensuring professional presentation during defense demos.【F:index.php†L8-L16】【F:checkout.php†L10-L15】
*This explanation covers what the code is about and how it functions.*

### Database Scripts (`dgz_motorshop_system/docs/processed_by_upgrade.sql`, `dgz_motorshop_system/init.sql`)
**Purpose:** SQL scripts for initializing and upgrading database schema elements.【F:dgz_motorshop_system/docs/processed_by_upgrade.sql†L1-L36】【F:dgz_motorshop_system/init.sql†L1-L342】
**Key Behaviors:**
- `init.sql` provisions tables, indexes, and seed data for products, orders, users, and stock modules.【F:dgz_motorshop_system/init.sql†L1-L342】
- `processed_by_upgrade.sql` adds the `processed_by_user_id` column and constraints when migrating existing databases.【F:dgz_motorshop_system/docs/processed_by_upgrade.sql†L1-L36】
*This explanation covers what the code is about and how it functions.*

### Test Harnesses (`dgz_motorshop_system/test.php`, `dgz_motorshop_system/test_email.php`, `dgz_motorshop_system/test_pdf_email.php`)
**Purpose:** Developer scripts for verifying connectivity, email delivery, and PDF generation workflows.【F:dgz_motorshop_system/test.php†L1-L35】【F:dgz_motorshop_system/test_email.php†L1-L56】【F:dgz_motorshop_system/test_pdf_email.php†L1-L68】
**Key Behaviors:**
- Allow you to manually trigger key subsystems during defense rehearsals to prove infrastructure readiness.【F:dgz_motorshop_system/test.php†L1-L35】【F:dgz_motorshop_system/test_email.php†L1-L56】【F:dgz_motorshop_system/test_pdf_email.php†L1-L68】
*This explanation covers what the code is about and how it functions.*

### Notes & Reference (`dgz_motorshop_system/trending.txt`)
**Purpose:** Holds marketing ideas and trending product notes for future content planning.【F:dgz_motorshop_system/trending.txt†L1-L9】
**Key Behaviors:**
- Provides inspiration bullet points that can be cited when discussing roadmap direction in the defense.【F:dgz_motorshop_system/trending.txt†L1-L9】
*This explanation covers what the code is about and how it functions.*

### Upload Storage (`dgz_motorshop_system/uploads/payment-proofs/`)
**Purpose:** Directory storing customer-uploaded payment proofs used during checkout verification.【F:dgz_motorshop_system/uploads/payment-proofs/0aa2e0c2aaa354b5.jpg†L1-L1】
**Key Behaviors:**
- Demonstrates file storage structure and ensures staff can retrieve proof assets when validating payments.【F:checkout.php†L320-L391】
*This explanation covers what the code is about and how it functions.*

With this deep guide, you can explain every corner of the codebase—especially PHP and JavaScript logic—confidently during your capstone defense.
