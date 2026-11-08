# Export Module - Developer Guide

This module implements the Report Export functionality for MonsterInsights Pro, allowing users to export Google Analytics report data to CSV or Excel (XLSX) formats.

## Architecture Overview

### Module Structure
```
pro/includes/admin/export/
├── loader.php                    # Module initialization and file loading
├── install.php                   # Directory setup (no DB table)
├── class-export-queue-store.php  # Options-backed queue storage
├── class-export-manager.php      # Core business logic and data access
├── ajax.php                      # AJAX endpoints for UI interaction
├── class-export-processor.php    # Background job processing (Phase 2)
├── class-export-formatter.php    # CSV/XLSX generation (Phase 2)
├── cleanup.php                   # WP Cron jobs (Phase 2)
├── class-export-notification.php # Notification integration (Phase 4) ✓
└── AGENTS.md                     # Developer documentation
```

## Queue Storage

Exports are stored in a single WordPress option key:

- Option key: `monsterinsights_export_queue` (serialized; autoload off)

High-level shape:

```php
array(
  'version' => 1,
  'next_id' => 123,
  'items'   => array(
    '123' => array(
      'id'                 => 123,
      'user_id'            => 1,
      'report_type'        => 'engagement_pages',
      'export_date'        => '2025-11-07 10:30:00',
      'start_date'         => '2025-10-01',
      'end_date'           => '2025-10-31',
      'date_range_display' => 'Oct 1 - Oct 31, 2025',
      'export_format'      => 'csv', // or 'excel'
      'status'             => 'pending',
      'file_name'          => null,
      'file_size'          => null,
      'started_at'         => null,
      'completed_at'       => null,
      'retry_count'        => 0,
      'retry_at'           => null,
      'error_message'      => null,
      'expires_at'         => '2025-11-14 10:30:00',
      'filters'            => '',  // JSON string; empty by default (relay API filter shape)
    ),
  ),
)
```

**Status Flow:** pending → in_progress → complete/failed

The queue stores `start_date`, `end_date`, and `date_range_display` as **top-level** fields per export (no nested config blob).

## Core Classes

### MonsterInsights_Export_Manager

**Purpose:** Central coordinator for all export operations

**Key Methods:**
- `create_export($args)` - Validates and creates new export job
- `get_export($export_id)` - Retrieves single export record
- `get_user_exports($user_id, $limit)` - Gets user's export history
- `update_export_status($export_id, $status, $data)` - Updates export state
- `delete_export($export_id)` - Deletes export with file cleanup

**Usage:**
```php
$manager = monsterinsights_export_manager();

// Create export
$result = $manager->create_export(array(
    'report_type' => 'overview',
    'start_date'  => '2025-10-01',
    'end_date'    => '2025-10-31',
    'format'      => 'csv',
));

if ($result['success']) {
    $export_id = $result['export_id'];
}

// Update status
$manager->update_export_status($export_id, 'complete', array(
    'file_name' => 'monsterinsights-export-20251107-143022.zip',
));
```

## Relay API Export Class

The `MonsterInsights_API_Export_Report` class provides a generic API interface for Vue 3 report types that use the relay API (multiple queries per request). Report types without a registered query builder fall back to the legacy single-query path.

### Class Location and Design

- **File:** `includes/api/class-monsterinsights-api-export-report.php`
- **Pattern:** Registry — report types register query builders that define which API queries to run
- **Routing:** The export processor calls `has_query_builder($report_type)` to decide which path to use:
  - **Relay path:** Report type has a registered builder → build queries, call relay API, format response
  - **Legacy path:** No builder → use existing single-query API flow

### Registry Pattern Usage

```php
// Register a query builder for a report type
MonsterInsights_API_Export_Report::instance()->register_query_builder('overview', function ($report_type, $start_date, $end_date, $filters) {
    return monsterinsights_build_overview_export_queries($report_type, $start_date, $end_date, $filters);
});

// Check if a report type uses the relay API
if (MonsterInsights_API_Export_Report::instance()->has_query_builder('overview')) {
    // Use relay API path
}
```

The query builder callable receives:
- `$report_type` — report type string
- `$start_date` — start date (YYYY-MM-DD)
- `$end_date` — end date (YYYY-MM-DD)
- `$filters` — decoded filter object or empty array (from queue `filters` field)

It must return an array of query definitions compatible with the relay API.

### Filters Field in Queue Schema

The queue store `normalize_row()` includes a `filters` field (empty string default). When an export is created with the optional `filters` AJAX parameter (JSON string), it is stored in the queue and passed to the API when processing. Filter shape:

```javascript
{ operator: 'and', conditions: [{ field, type, match, value, caseSensitive }] }
```

### How the Processor Routes

1. Processor loads the export from the queue (including `filters`).
2. Processor calls `MonsterInsights_API_Export_Report::instance()->has_query_builder($report_type)`.
3. **If true:** Use relay API path — build queries via the registered builder (with filters), call relay endpoint, format with `prepare_v2_*` callbacks.
4. **If false:** Use legacy path — existing single-query API call.

### Adding a New Report Type (Step by Step)

1. **Create the query builder function** (e.g. in `loader.php` or a dedicated file):
   ```php
   function monsterinsights_build_my_report_export_queries($report_type, $start_date, $end_date, $filters) {
       return [
           ['endpoint' => '...', 'params' => [...]],
           // ... more queries
       ];
   }
   ```

2. **Register it in `loader.php`**:
   ```php
   add_action('monsterinsights_export_module_loaded', function () {
       MonsterInsights_API_Export_Report::instance()->register_query_builder(
           'my_report',
           'monsterinsights_build_my_report_export_queries'
       );
   });
   ```

3. **Add formatter config** in `class-export-formatter.php`:
   - Add multi-CSV/XLSX config for the new report type.
   - Use `prepare_v2_*` data callbacks to map relay API response format to rows.

4. **Ensure validation** — Confirm the processor's report type validation includes `my_report` (or allow any type if validation is permissive).

5. **Handle filters** — Ensure your query builder merges `$filters` into each query's params so the API receives them.

## AJAX Endpoints

### 1. Create Export
**Action:** `monsterinsights_export_report`
**Capability:** `monsterinsights_save_settings`
**Nonce:** `mi-admin-nonce`

**Request:**
```javascript
{
  action: 'monsterinsights_export_report',
  nonce: mi_admin_nonce,
  report_type: 'overview',
  start_date: '2025-10-01',
  end_date: '2025-10-31',
  format: 'csv',
  triggered_from: 'report_screen',
  filters: '{"operator":"and","conditions":[...]}'  // Optional; JSON string (relay API filter shape)
}
```

**Response (Success):**
```json
{
  "success": true,
  "data": {
    "export_id": 123,
    "message": "Export started. You will receive a notification when it is ready.",
    "status": "pending"
  }
}
```

### 2. Get Export Status
**Action:** `monsterinsights_get_export_status`
**Capability:** `monsterinsights_save_settings`
**Nonce:** `mi-admin-nonce`

**Request:**
```javascript
{
  action: 'monsterinsights_get_export_status',
  nonce: mi_admin_nonce,
  export_id: 123  // Optional - omit to get all user's exports
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "exports": [
      {
        "id": 123,
        "report_type": "overview",
        "report_name": "Overview",
        "date_range": "Oct 1 - Oct 31, 2025",
        "format": "csv",
        "status": "complete",
        "created": "2025-11-07 10:30:00",
        "expires": "2025-11-14 10:30:00",
        "expires_in": 6,
        "download_url": "https://..."
      }
    ]
  }
}
```

### 3. Delete Export
**Action:** `monsterinsights_delete_export`
**Capability:** `monsterinsights_save_settings`
**Nonce:** `mi-admin-nonce`

**Request:**
```javascript
{
  action: 'monsterinsights_delete_export',
  nonce: mi_admin_nonce,
  export_id: 123
}
```

## Security Implementation

### 1. Capability Checks
All operations require `monsterinsights_save_settings` capability.

### 2. Nonce Verification
All AJAX requests use `check_ajax_referer('mi-admin-nonce', 'nonce')`.

### 3. Input Sanitization
```php
$report_type = sanitize_text_field(wp_unslash($_POST['report_type']));
$start_date  = sanitize_text_field(wp_unslash($_POST['start_date']));
// ... etc
```

### 4. SQL Injection Prevention
Always use `$wpdb->prepare()` for queries:
```php
$wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $export_id)
);
```

### 5. User Ownership Validation
```php
if ($export->user_id != get_current_user_id() && !current_user_can('manage_options')) {
    return false; // Admins can access all exports
}
```

### 6. Rate Limiting
- Daily limit: 50 exports per user (filterable)
- Active limit: 10 concurrent exports (filterable)

## Validation Rules

### Date Validation
- Format: YYYY-MM-DD (validated via regex)
- Start date must be before end date
- No future dates allowed
- Maximum range: 365 days (filterable via `monsterinsights_export_max_days`)

### Format Validation
- Must be exactly 'csv' or 'excel'
- Case-sensitive
- No other formats accepted

### Report Type
- Any string accepted (validated against available reports during processing)
- Common types: overview, ecommerce, publisher, queries, dimensions, forms, etc.

## Hooks and Filters

### Actions

**monsterinsights_export_created**
```php
do_action('monsterinsights_export_created', $export_id, $args);
```
Fires after export is successfully created.

**monsterinsights_export_status_changed**
```php
do_action('monsterinsights_export_status_changed', $export_id, $status, $data);
```
Fires when export status is updated.

**monsterinsights_export_completed**
```php
do_action('monsterinsights_export_completed', $export_id, $result);
```
Fires when export completes successfully. Triggers success notification with download link.

**monsterinsights_export_failed**
```php
do_action('monsterinsights_export_failed', $export_id, $error_message);
```
Fires when export fails after all retries. Triggers failure notification with retry button.

**monsterinsights_export_deleted**
```php
do_action('monsterinsights_export_deleted', $export_id);
```
Fires after export is deleted.

**monsterinsights_export_module_loaded**
```php
do_action('monsterinsights_export_module_loaded');
```
Fires after export module initialization.

### Filters

**monsterinsights_export_expiration_days**
```php
$expiration_days = apply_filters('monsterinsights_export_expiration_days', 7);
```
Change export file expiration period (default: 7 days).

**monsterinsights_export_rate_limit**
```php
$limit = apply_filters('monsterinsights_export_rate_limit', 50);
```
Change daily export limit per user (default: 50).

**monsterinsights_export_max_active**
```php
$max_active = apply_filters('monsterinsights_export_max_active', 10);
```
Change maximum concurrent exports per user (default: 10).

**monsterinsights_export_max_days**
```php
$max_days = apply_filters('monsterinsights_export_max_days', 365);
```
Change maximum date range for exports (default: 365 days).

## Notification System

The export module integrates with the MonsterInsights notification system to provide real-time updates to users when exports complete or fail.

### Notification Classes

**MonsterInsights_Export_Notification_Complete**
Displays when an export completes successfully.

**Features:**
- Shows report name and date range
- Displays file size and format
- Includes direct download link
- Link to view all exports
- Priority: 1 (high)
- Category: alert
- Icon: checkmark

**Example Notification:**
```
Title: "Your Overview Export is Ready!"
Content: "Your Overview report (Oct 1 - Oct 31, 2025) has been
          successfully exported to CSV format (1.2 MB). Click below
          to download your file. This export will be available for 7 days."
Buttons: [Download Export] [View All Exports]
```

**MonsterInsights_Export_Notification_Failed**
Displays when an export fails after all retry attempts.

**Features:**
- Shows report name and date range
- User-friendly error messages (not technical jargon)
- Contextual help text for common errors
- Retry button to start a new export
- Support link for critical errors
- Priority: 1 (high)
- Category: alert
- Icon: warning

**Example Notification:**
```
Title: "Export Failed: Overview"
Content: "Your Overview report export (Oct 1 - Oct 31, 2025) failed
          to complete. No data was found for the selected date range.
          Try selecting a different date range with available data."
Buttons: [Try Again] [Contact Support]
```

### Error Message Mapping

The notification system converts technical errors into user-friendly messages:

| Technical Error | User-Friendly Message | Help Text | Support Link |
|----------------|----------------------|-----------|-------------|
| "No data available" | "No data was found for the selected date range." | "Try selecting a different date range with available data." | No |
| API errors | "There was a problem connecting to Google Analytics." | "Please check your connection and try again." | No |
| Directory/file errors | "There was a problem creating the export file." | "This may be a file permissions issue. Please contact your hosting provider." | Yes |
| ZIP errors | "There was a problem compressing the export file." | "Your server may not have the ZIP extension enabled." | Yes |
| Memory errors | "The export exceeded available memory." | "Try exporting a smaller date range or contact your hosting provider." | No |
| Timeout errors | "The export took too long to complete." | "Try exporting a smaller date range." | No |
| Invalid config | "The export configuration was invalid." | "Please check your settings and try again." | No |
| Unknown errors | "The export could not be completed." | "Error details: [technical message]" | Yes |

### Notification Lifecycle

1. **Export Created**: User initiates export via UI
2. **Processing**: Export processor runs in background via WP Cron
3. **Completion**:
   - Success: `do_action('monsterinsights_export_completed', $export_id, $result)`
   - Failure: `do_action('monsterinsights_export_failed', $export_id, $error_message)`
4. **Notification Generated**: Appropriate notification class is instantiated
5. **Notification Displayed**: Appears in MI notification center
6. **User Action**: User clicks Download/Retry button

### Testing Notifications

To test notifications manually:

```php
// Test success notification
do_action('monsterinsights_export_completed', 123, array(
    'file_name' => 'test-export.zip',
    'file_size' => 1048576,
));

// Test failure notification
do_action('monsterinsights_export_failed', 124, 'No data available for the selected date range.');
```

### Notification Dismissal

Notifications follow standard MI notification dismissal patterns:
- Users can dismiss individual notifications
- Dismissed notifications are stored in the notification system
- Each export generates a unique notification ID (includes export_id)
- Same export will not trigger duplicate notifications

## File Storage

### Directory Structure
```
wp-content/uploads/monsterinsights/exports/
├── .htaccess                     # Deny direct access
├── index.php                     # Security file
└── monsterinsights-reports-export-YYYYMMDD-HHMMSS.zip
```

### Filename Format
```
monsterinsights-reports-export-20251107-143022.zip
```
Pattern: `monsterinsights-reports-export-YYYYMMDD-HHMMSS.zip`

### Security Files

**.htaccess:**
```apache
# MonsterInsights Export Security
<Files *>
Order Deny,Allow
Deny from all
</Files>
```

**index.php:**
```php
<?php // Silence is golden
```

## Testing Strategies

### Unit Tests
Test Export Manager methods in isolation:
```php
public function test_create_export_validates_dates() {
    $result = monsterinsights_export_manager()->create_export(array(
        'report_type' => 'overview',
        'start_date'  => '2025-11-01',
        'end_date'    => '2025-10-01', // Invalid: end before start
        'format'      => 'csv',
    ));

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('before', $result['message']);
}
```

### Integration Tests
Test full workflow from AJAX to database:
```php
public function test_complete_export_creation_flow() {
    $_POST = array(
        'nonce'       => wp_create_nonce('mi-admin-nonce'),
        'report_type' => 'overview',
        'start_date'  => '2025-10-01',
        'end_date'    => '2025-10-31',
        'format'      => 'csv',
    );

    ob_start();
    monsterinsights_ajax_export_report();
    $response = json_decode(ob_get_clean(), true);

    $this->assertTrue($response['success']);
    $this->assertArrayHasKey('export_id', $response['data']);
}
```

## Common Issues and Debugging

### Issue 1: Queue Not Persisting
**Symptom:** Exports don’t appear in the queue or disappear unexpectedly
**Debug:**
```php
error_log( print_r( get_option( 'monsterinsights_export_queue', array() ), true ) );
```
**Solution:** Confirm options table is writable and that nothing is clearing the `monsterinsights_export_queue` option.

### Issue 2: AJAX Returns 0 or -1
**Symptom:** AJAX calls fail silently
**Debug:**
1. Check nonce: `wp_verify_nonce($_POST['nonce'], 'mi-admin-nonce')`
2. Check capability: `current_user_can('monsterinsights_save_settings')`
3. Enable WP_DEBUG and check error logs
**Solution:** Ensure user is logged in and has proper capabilities.

### Issue 3: Rate Limit Errors
**Symptom:** "Maximum exports reached" messages
**Debug:**
```php
$count = monsterinsights_export_queue_store()->count_user_exports_last_24h( get_current_user_id() );
error_log( 'Exports in last 24h: ' . $count );
```
**Solution:** Wait 24 hours or increase limit via filter.

## Performance Considerations

### Queue Storage Size
- The queue is stored in a single `wp_options` row (`monsterinsights_export_queue`)
- Cleanup prunes expired exports; consider keeping history small to avoid option bloat

### Memory Management
- Exports processed in background (Phase 2)
- File streaming for large downloads (Phase 2)
- Set appropriate memory limits: `ini_set('memory_limit', '512M')`

### Caching
- Transient cache for export status (30-300 seconds based on status)
- Cache key: `mi_export_status_{export_id}`

## Future Enhancements (Roadmap)

### Phase 2 (Current)
- Export processing and file generation
- WP Cron integration
- CSV/XLSX formatting

### Phase 3
- Vue.js UI components
- Tools page integration
- Real-time status updates

### Phase 4
- Notification system integration
- Download handler
- Cleanup routines

### Post-MVP
- Scheduled recurring exports
- Email delivery
- Multi-report exports
- Custom field selection
- XLSX format support

## Related Documentation

- **PRD:** `_PRD/report-export-module/PRD.md`
- **Tasks:** `_PRD/report-export-module/TASKS.md`
- **Phase 1 Summary:** `_PRD/report-export-module/PHASE-1-SUMMARY.md`

## Support and Troubleshooting

For issues or questions:
1. Check WordPress error logs (enable WP_DEBUG)
2. Check database table schema
3. Verify file permissions on uploads directory
4. Test AJAX endpoints in browser console
5. Review security checks (nonce, capability)

## Code Review Checklist

Before committing changes:
- [ ] WordPress coding standards followed
- [ ] All functions have PHPDoc blocks
- [ ] Security checks in place (nonce, capability, sanitization)
- [ ] Database queries use prepared statements
- [ ] Text domain used for all strings
- [ ] Proper error handling
- [ ] No PHP warnings or notices
- [ ] Code follows DRY principle
- [ ] Filters and actions documented
