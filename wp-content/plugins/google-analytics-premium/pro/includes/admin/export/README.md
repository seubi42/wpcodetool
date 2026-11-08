# MonsterInsights Pro - Report Export Module

## Overview

The Report Export Module enables MonsterInsights Pro users to export their Google Analytics report data in CSV and Excel (XLSX) formats. This module provides a complete background processing system for handling exports asynchronously via WP Cron.

**Version:** 9.0.0
**Status:** Phase 2 Complete - Backend Processing Implemented

---

## Architecture

### Components

1. **Export Manager** (`class-export-manager.php`)
   - Central coordinator for export operations
   - Handles export creation, validation, and queue management
   - Provides secure download handler
   - Manages queue storage in `wp_options`

2. **Export Processor** (`class-export-processor.php`)
   - Background job processing via WP Cron
   - Integrates with MonsterInsights reporting API
   - Implements job locking to prevent concurrent processing
   - Includes retry logic with exponential backoff
   - Handles timeout and memory management

3. **Export Formatter** (`class-export-formatter.php`)
   - Formats report data into CSV and Excel (XLSX)
   - CSV includes UTF-8 BOM for Excel compatibility
   - Handles nested data structures intelligently

4. **AJAX Handlers** (`ajax.php`)
   - `monsterinsights_export_report` - Create new export
   - `monsterinsights_get_export_status` - Check export status
   - `monsterinsights_delete_export` - Delete export
   - Helper functions for formatting responses

5. **WP Cron Setup** (`cleanup.php`)
   - Queue processing cron (every 5 minutes)
   - Expired export cleanup cron (daily)
   - Custom cron intervals registration

6. **Storage Setup** (`install.php`)
   - Queue is stored in `wp_options` (no custom DB table)
   - Creates secure exports directory
   - Hooks into plugin installation routine

---

## Filter Support

The `monsterinsights_export_report` AJAX action accepts an optional `filters` parameter to apply report-level filters during export. Filters match the Vue 3 frontend filter shape and are passed through to the API.

### Usage

- **Parameter:** `filters` (optional) — JSON string
- **Storage:** Filters are stored in the queue (`filters` field) and passed to the API when processing
- **Default:** If omitted, no filters are applied

### Relay API Filter Shape

Filters use the same structure as the Vue 3 reports relay API:

```javascript
{
  operator: 'and',
  conditions: [
    {
      field: 'pagePath',
      type: 'string',
      match: 'contains',
      value: '/blog',
      caseSensitive: false
    },
    {
      field: 'country',
      type: 'string',
      match: 'equals',
      value: 'United States',
      caseSensitive: false
    }
  ]
}
```

- **operator:** `'and'` or `'or'` — how conditions are combined
- **conditions:** Array of filter conditions
- **field:** Report field to filter on (e.g. `pagePath`, `country`, `deviceCategory`)
- **type:** `'string'` or `'number'`
- **match:** `'equals'`, `'contains'`, `'startsWith'`, `'endsWith'`, etc.
- **value:** Filter value
- **caseSensitive:** Boolean

---

## Relay API Export (Vue 3 Reports)

Vue 3 reports (e.g. Overview) use a relay API that fetches multiple queries in a single request. The `MonsterInsights_API_Export_Report` class provides a generic interface for these report types.

### MonsterInsights_API_Export_Report Class

**Location:** `includes/api/class-monsterinsights-api-export-report.php`

This class uses a **registry pattern** — report types register query builders that define which API queries to run. The processor checks `has_query_builder()` to decide between the relay API path and the legacy single-query path.

### Registry Pattern

- **Registration:** `register_query_builder($type, $callable)` — registers a callable that builds the query list for a report type
- **Check:** `has_query_builder($type)` — returns whether a report type has a registered builder
- **Build:** The callable receives `(report_type, start_date, end_date, filters)` and returns an array of query definitions
- **API:** Queries are sent to the relay API endpoint; each query gets user filters applied

### Overview Query Builder

The overview report type is registered in `loader.php` via `monsterinsights_build_overview_export_queries()`. It builds **14 queries** matching the Vue 3 frontend:

- **overview** — main overview data
- **key_metrics** — key metrics summary
- **marketing_campaigns** × 5 — campaign breakdowns
- **pages** × 2 — top pages and landing pages
- **demographics** × 2 — country and city
- **devices** × 3 — device categories, browsers, operating systems

Each query receives the user's filters from the queue. The formatter uses `prepare_v2_*` data callbacks to handle the relay API response format.

### Adding a New Report Type

To add a new relay API report type:

1. Create a query builder function that returns an array of query definitions
2. Register it via `MonsterInsights_API_Export_Report::instance()->register_query_builder('report_type', $callable)`
3. Add formatter config for the new report type (including `prepare_v2_*` callbacks if needed)
4. Ensure the processor's report type validation includes the new type

---

## Queue Storage

Exports are stored in a single WordPress option key:

- Option key: `monsterinsights_export_queue` (serialized; autoload off)

Shape (high-level):

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
      'filters'            => '',  // JSON string; empty by default
    ),
  ),
)
```

**Status Values:**
- `pending` - Waiting in queue
- `in_progress` - Currently being processed
- `complete` - Successfully completed
- `failed` - Failed after max retries

---

## File Structure

```
monsterinsights/pro/includes/admin/export/
├── class-export-manager.php      # Main orchestration class
├── class-export-processor.php    # Background job processor
├── class-export-formatter.php    # CSV/XLSX formatter
├── ajax.php                       # AJAX endpoint handlers
├── cleanup.php                    # WP Cron setup
├── install.php                    # Directory setup (no DB table)
├── loader.php                     # Module loader
├── index.php                      # Security file
└── README.md                      # This file
```

---

## How It Works

### Export Flow

1. **User Initiates Export**
   - User clicks export button (report screen or tools page)
   - AJAX request sent to `monsterinsights_export_report`
   - Export Manager validates input and creates export record in `wp_options`
   - Export added to queue with status `pending`

2. **Background Processing (WP Cron)**
   - Every 5 minutes, `monsterinsights_process_export_queue` fires
   - Export Processor picks next pending export
   - Acquires lock to prevent concurrent processing
   - Fetches report data from MonsterInsights API
   - Formats data as CSV or Excel (XLSX)
   - Creates export file (CSV ZIP or XLSX)
   - Updates status to `complete` with file info

3. **User Downloads Export**
   - User receives notification (Phase 4)
   - Clicks download link
   - Secure download handler verifies:
     - Nonce validity
     - User permissions
     - File ownership
     - Export status
   - File streamed to browser

4. **Cleanup (Daily)**
   - Daily cron deletes expired exports (7 days old by default)
   - Removes both queue records (in `wp_options`) and files

---

## Security Features

### Input Validation
- All user inputs sanitized and validated
- Date formats validated with regex
- Maximum date range enforced (365 days)
- Export format restricted to CSV/Excel only

### Permission Checks
- `monsterinsights_save_settings` capability required to create exports
- `monsterinsights_view_dashboard` capability required to download
- Ownership verification for downloads
- Admins can download any export

### File Security
- Exports stored in `wp-content/uploads/monsterinsights/exports/`
- `.htaccess` denies direct web access
- Directory traversal protection using `basename()` and `realpath()`
- Download handler requires valid nonce
- Files automatically deleted after 7 days

### Rate Limiting
- Max 50 exports per user per 24 hours
- Max 10 active (pending/in_progress) exports per user
- Configurable via filters

---

## API Reference

### Export Manager

```php
// Get singleton instance
$manager = monsterinsights_export_manager();

// Create new export
$result = $manager->create_export( array(
    'report_type' => 'overview',
    'start_date'  => '2025-01-01',
    'end_date'    => '2025-01-31',
    'format'      => 'csv',
) );

// Get export by ID
$export = $manager->get_export( $export_id );

// Get user's exports
$exports = $manager->get_user_exports( $user_id, $limit );

// Update export status
$manager->update_export_status( $export_id, 'complete', array(
    'file_name' => 'export.zip',
    'file_size' => 12345,
) );

// Delete export
$manager->delete_export( $export_id );

// Handle download (used internally)
$manager->handle_download( $export_id, $nonce );
```

### Export Processor

```php
// Get singleton instance
$processor = monsterinsights_export_processor();

// Process next export (called by cron)
$processor->process_next_export();
```

### Export Formatter

```php
// Get singleton instance
$formatter = monsterinsights_export_formatter();

// Format data
$formatted = $formatter->format( $data, 'csv', array(
    'report_type'  => 'overview',
    'report_title' => 'Overview Report',
    'date_range'   => 'Jan 1 - Jan 31, 2025',
    'export_date'  => '2025-02-01 10:30:00',
) );
```

---

## Filters & Actions

### Filters

```php
// Modify expiration period (default: 7 days)
add_filter( 'monsterinsights_export_expiration_days', function( $days ) {
    return 14; // 14 days instead of 7
} );

// Modify max date range (default: 365 days)
add_filter( 'monsterinsights_export_max_days', function( $days ) {
    return 730; // 2 years
} );

// Modify rate limit (default: 50 per day)
add_filter( 'monsterinsights_export_rate_limit', function( $limit ) {
    return 100;
} );

// Modify max active exports (default: 10)
add_filter( 'monsterinsights_export_max_active', function( $max ) {
    return 20;
} );

// Modify memory limit for processing (default: 256M)
add_filter( 'monsterinsights_export_memory_limit', function( $limit ) {
    return '512M';
} );
```

### Actions

```php
// After export is created
add_action( 'monsterinsights_export_created', function( $export_id, $args ) {
    // Do something
}, 10, 2 );

// After export status changes
add_action( 'monsterinsights_export_status_changed', function( $export_id, $status, $data ) {
    // Do something
}, 10, 3 );

// After export completes successfully
add_action( 'monsterinsights_export_completed', function( $export_id, $result ) {
    // Send notification
}, 10, 2 );

// After export fails
add_action( 'monsterinsights_export_failed', function( $export_id, $error_message ) {
    // Send notification
}, 10, 2 );

// After export is deleted
add_action( 'monsterinsights_export_deleted', function( $export_id ) {
    // Clean up related data
} );

// After cleanup completes
add_action( 'monsterinsights_export_cleanup_completed', function( $deleted, $failed, $total ) {
    // Log results
}, 10, 3 );

// After module loads
add_action( 'monsterinsights_export_module_loaded', function() {
    // Initialize extensions
} );
```

---

## AJAX Endpoints

### Create Export

**Action:** `monsterinsights_export_report`
**Method:** POST
**Nonce:** `mi-admin-nonce`
**Capability:** `monsterinsights_save_settings`

**Request:**
```javascript
{
    action: 'monsterinsights_export_report',
    nonce: ajax_nonce,
    report_type: 'overview',
    start_date: '2025-01-01',
    end_date: '2025-01-31',
    format: 'csv',
    triggered_from: 'report_screen',
    filters: '{"operator":"and","conditions":[...]}'  // Optional; JSON string
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

### Get Export Status

**Action:** `monsterinsights_get_export_status`
**Method:** POST
**Nonce:** `mi-admin-nonce`
**Capability:** `monsterinsights_save_settings`

**Request (single export):**
```javascript
{
    action: 'monsterinsights_get_export_status',
    nonce: ajax_nonce,
    export_id: 123
}
```

**Request (all exports):**
```javascript
{
    action: 'monsterinsights_get_export_status',
    nonce: ajax_nonce
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
                "date_range": "Jan 1 - Jan 31, 2025",
                "format": "csv",
                "status": "complete",
                "created": "2025-02-01 10:30:00",
                "download_url": "https://example.com/wp-admin/admin.php?page=...",
                "expires_in": 5
            }
        ]
    }
}
```

### Delete Export

**Action:** `monsterinsights_delete_export`
**Method:** POST
**Nonce:** `mi-admin-nonce`
**Capability:** `monsterinsights_save_settings`

**Request:**
```javascript
{
    action: 'monsterinsights_delete_export',
    nonce: ajax_nonce,
    export_id: 123
}
```

---

## WP Cron Jobs

### Queue Processing

**Hook:** `monsterinsights_process_export_queue`
**Interval:** Every 5 minutes (`monsterinsights_export_5min`)
**Function:** `monsterinsights_export_process_queue()`

Processes one export at a time from the queue.

### Cleanup

**Hook:** `monsterinsights_cleanup_expired_exports`
**Interval:** Daily
**Function:** `monsterinsights_export_cleanup_expired()`

Deletes expired exports (files and database records).

---

## Testing

### Manual Testing

1. **Create an Export**
   ```php
   // In wp-admin/admin-ajax.php context
   $result = monsterinsights_export_manager()->create_export( array(
       'report_type' => 'overview',
       'start_date'  => '2025-01-01',
       'end_date'    => '2025-01-31',
       'format'      => 'csv',
   ) );
   var_dump( $result );
   ```

2. **Trigger Cron Manually**
   ```bash
   wp cron event run monsterinsights_process_export_queue
   ```

3. **Check Export Status**
   ```php
   $export = monsterinsights_export_manager()->get_export( $export_id );
   var_dump( $export );
   ```

4. **Test Download**
   - Navigate to download URL
   - Verify file downloads correctly
   - Check file contents

### Automated Testing

Unit tests pending implementation (Phase 2 Testing & Validation section).

---

## Troubleshooting

### Exports Stuck in "Pending" Status

**Causes:**
- WP Cron not running
- Server blocking cron execution
- PHP time limit too low

**Solutions:**
```bash
# Test WP Cron
wp cron test

# Run cron manually
wp cron event run monsterinsights_process_export_queue

# Check next scheduled run
wp cron event list
```

### "Failed to Create ZIP File" Error

**Cause:** ZipArchive PHP extension not available

**Solution:** The system will fall back to uncompressed file if ZipArchive is not available. To enable ZIP:
```bash
# Check if extension is available
php -m | grep zip

# Install extension (Ubuntu/Debian)
sudo apt-get install php-zip

# Install extension (CentOS/RHEL)
sudo yum install php-zip
```

### Download Returns 404

**Causes:**
- File was deleted
- Export expired
- Incorrect permissions

**Check:**
```php
$export = monsterinsights_export_manager()->get_export( $export_id );
if ( $export ) {
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/monsterinsights/exports/' . $export->file_name;
    echo file_exists( $file_path ) ? 'File exists' : 'File missing';
}
```

### Rate Limit Errors

**Solution:** Increase rate limits via filters:
```php
add_filter( 'monsterinsights_export_rate_limit', function() {
    return 100; // Increase from 50 to 100
} );
```

---

## Next Steps (Phase 3)

1. **UI Development**
   - Vue component for export button on report screens
   - Tools page UI for export management
   - Export queue table with real-time updates

2. **Notifications**
   - Success notifications with download link
   - Failure notifications with retry option
   - Expiration warnings

3. **Polish**
   - Loading states and animations
   - Error handling improvements
   - Comprehensive logging

---

## Developer Notes

### Code Standards

- Follows WordPress PHP Coding Standards
- All functions prefixed with `monsterinsights_`
- Comprehensive PHPDoc blocks
- Security best practices implemented

### Performance Considerations

- Job locking prevents concurrent processing
- Streaming for large file downloads
- Exponential backoff for API retries
- Memory limit management

### Browser Compatibility

Download handler tested with:
- Chrome/Edge (modern)
- Firefox
- Safari
- Internet Explorer 11+ (if ZIP available)

---

## Changelog

### Version 9.0.0 (Phase 2 Complete)
- Initial implementation of export module
- Export Manager class with full CRUD operations
- Export Processor with background processing
- Export Formatter supporting CSV and Excel (XLSX)
- WP Cron integration for queue processing
- Secure download handler with permissions
- Rate limiting and retry logic
- Database schema and installation routine

---

**Last Updated:** November 7, 2025
**Author:** MonsterInsights Development Team
