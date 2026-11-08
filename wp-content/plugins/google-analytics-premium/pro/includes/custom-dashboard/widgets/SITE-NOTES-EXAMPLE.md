# Site Notes Widget - Local Data Widget Example

## Overview

The Site Notes widget is a simple example of how to create a **local data widget** that doesn't rely on the GA4 API. It demonstrates:

- ✅ Local data storage (WordPress options)
- ✅ Skipping GA4 API requests with `is_local = true`
- ✅ Returning data directly via `process_data()`
- ✅ Using standard DataTableWidget component for display
- ✅ Separation of concerns (display vs. management)

Use this as a template for creating simple local data display widgets!

## Key Concept

**This widget only DISPLAYS data, it doesn't manage it.**

Site notes would be managed elsewhere (e.g., a dedicated settings page or admin interface). This widget's job is simply to show those notes in the dashboard.

## Architecture

### Backend (PHP)

**File**: `class-site-notes-widget.php`

```
MonsterInsights_Site_Notes_Widget
├── Properties
│   ├── $is_local = true          // Marks widget as local data (NO API call)
│   ├── $type = 'site-notes'      // Widget identifier
│   └── $support['types'] = ['table']  // Standard table display
│
├── Data Management
│   ├── get_notes()               // Fetch notes from WordPress options
│   └── process_data()            // Format data for DataTableWidget
│
└── No AJAX endpoints needed!     // Data management handled elsewhere
```

### Frontend

**No custom Vue component needed!**

The widget uses the standard **DataTableWidget** component, which automatically handles:
- Table rendering
- Column headers
- Row display
- Empty states
- Responsive layout

## Key Implementation Details

### 1. Local Widget Flag

```php
$this->is_local = true;
```

**What this does:**
- Dashboard skips GA4 API request for this widget
- Calls `process_data()` directly with empty `$api_data`
- Widget returns data immediately without waiting for API

### 2. Data Format for DataTableWidget

The `process_data()` method returns data in the format expected by DataTableWidget:

```php
return array(
    'rows' => array(
        array(
            'date' => '2024-01-15',
            'note' => 'Launched new product page',
            'author' => 'John Doe',
        ),
        // ... more rows
    ),
    'total' => 5,
    'headers' => array(
        array( 'key' => 'date', 'label' => 'Date' ),
        array( 'key' => 'note', 'label' => 'Note' ),
        array( 'key' => 'author', 'label' => 'Author' ),
    ),
);
```

**Key points:**
- `rows`: Array of data rows (each row is an associative array)
- `total`: Total number of rows
- `headers`: Column definitions with `key` (field name) and `label` (display name)

### 3. Data Storage

Notes are stored in WordPress options table:

```php
const NOTES_OPTION = 'monsterinsights_site_notes';

// Retrieve notes
$notes = get_option( 'monsterinsights_site_notes', array() );

// Notes structure (managed elsewhere):
array(
    array(
        'date' => '2024-01-15',
        'content' => 'Launched new product page',
        'author' => array(
            'id' => 1,
            'name' => 'John Doe',
        ),
    ),
    // ... more notes
)
```

### 4. Widget Configuration

```php
$this->support = array(
    'metrics' => array(),      // No metrics needed
    'dimensions' => array(),   // No dimensions needed
    'types' => array( 'table' ), // Only table display
);
```

Since this is a simple display widget:
- No metrics selection
- No dimensions
- No chart type toggling
- Just shows the data in a table

## Complete Implementation

### PHP Widget Class

```php
<?php
class MonsterInsights_Site_Notes_Widget extends MonsterInsights_Abstract_Widget {

    const NOTES_OPTION = 'monsterinsights_site_notes';

    public function __construct() {
        $this->type = 'site-notes';
        $this->title = __( 'Site Notes', 'google-analytics-premium' );
        $this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
        $this->icon = 'dashicons-edit-page';

        // Important: Mark as local to skip API requests
        $this->is_local = true;

        $this->support = array(
            'metrics' => array(),
            'dimensions' => array(),
            'types' => array( 'table' ),
        );

        $this->defaults = array(
            'type' => 'table',
        );
    }

    /**
     * Return local data - called instead of making API request
     */
    public function process_data( $api_data, $config ) {
        $notes = $this->get_notes();

        // Format for DataTableWidget
        $formatted_notes = array();
        foreach ( $notes as $note ) {
            $formatted_notes[] = array(
                'date' => $note['date'],
                'note' => $note['content'],
                'author' => $note['author']['name'] ?? 'Unknown',
            );
        }

        return array(
            'rows' => $formatted_notes,
            'total' => count( $formatted_notes ),
            'headers' => array(
                array( 'key' => 'date', 'label' => __( 'Date', 'google-analytics-premium' ) ),
                array( 'key' => 'note', 'label' => __( 'Note', 'google-analytics-premium' ) ),
                array( 'key' => 'author', 'label' => __( 'Author', 'google-analytics-premium' ) ),
            ),
        );
    }

    private function get_notes() {
        $notes = get_option( self::NOTES_OPTION, array() );

        if ( ! is_array( $notes ) ) {
            $notes = array();
        }

        // Sort by date (newest first)
        usort( $notes, function( $a, $b ) {
            return strtotime( $b['date'] ) - strtotime( $a['date'] );
        });

        return $notes;
    }
}
```

### Widget Mapping (already done)

In `WidgetRenderer.vue`:

```javascript
const WIDGET_TYPE_MAP = {
  // ... other widgets
  'site-notes': 'DataTableWidget',  // Uses standard table widget
}
```

## Creating Your Own Local Widget

### Simple Display Widget (Like Site Notes)

Use this pattern when you just want to **display** local data:

```php
class MonsterInsights_My_Local_Widget extends MonsterInsights_Abstract_Widget {

    public function __construct() {
        $this->type = 'my-widget';
        $this->title = __( 'My Data', 'google-analytics-premium' );
        $this->category = __( 'Custom Dimensions', 'google-analytics-premium' );
        $this->icon = 'dashicons-admin-generic';

        $this->is_local = true; // Skip API requests

        $this->support = array(
            'metrics' => array(),
            'dimensions' => array(),
            'types' => array( 'table' ),
        );

        $this->defaults = array(
            'type' => 'table',
        );
    }

    public function process_data( $api_data, $config ) {
        // Get your local data
        $data = get_option( 'my_data', array() );

        // Format for table display
        return array(
            'rows' => $data,
            'total' => count( $data ),
            'headers' => array(
                array( 'key' => 'column1', 'label' => 'Column 1' ),
                array( 'key' => 'column2', 'label' => 'Column 2' ),
            ),
        );
    }
}
```

Then map it in `WidgetRenderer.vue`:

```javascript
'my-widget': 'DataTableWidget',
```

Done! No custom Vue component needed.

## When to Use This Pattern

**Use local widgets when:**
- ✅ Data comes from WordPress (options, custom tables, post meta)
- ✅ Data updates infrequently
- ✅ You want to display data, not manage it
- ✅ Standard table/chart display is sufficient

**Don't use local widgets when:**
- ❌ You need real-time GA4 analytics data
- ❌ You need complex user interactions (forms, editing)
- ❌ Data requires external API calls
- ❌ You need custom UI components

## Comparison: Local vs API Widgets

### API Widget (Standard)
```php
$this->is_local = false; // or omit (default)

public function build_api_query( $config ) {
    return array(
        'metrics' => array( 'sessions' ),
        'dimensions' => array( 'date' ),
    );
}
// Dashboard makes GA4 API request
// process_data() receives API response
```

### Local Widget (Site Notes)
```php
$this->is_local = true;

public function process_data( $api_data, $config ) {
    // $api_data is empty - no API request made
    return $this->get_local_data();
}
// Dashboard skips API request
// Widget returns data directly
```

## Benefits of This Approach

### 1. **Simple**
- No AJAX endpoints needed
- No custom Vue components
- Uses existing DataTableWidget

### 2. **Fast**
- No API requests
- Data returned immediately
- No network latency

### 3. **Maintainable**
- Clear separation of concerns
- Data management separate from display
- Follows existing patterns

### 4. **Flexible**
- Easy to add more local widgets
- Can mix local and API widgets
- Standard widget features work (rename, delete, etc.)

## Future Enhancements

If you need more than simple display, you can:

1. **Add a Settings Page** for note management
2. **Create AJAX endpoints** for CRUD operations (separate from widget)
3. **Build a custom Vue component** (if DataTableWidget isn't enough)
4. **Add widget actions** (e.g., "Manage Notes" button)

But keep the widget itself focused on **display only**.

## Testing Checklist

- [ ] Widget appears in sidebar under correct category
- [ ] Widget displays when no data exists (empty state)
- [ ] Widget displays data correctly when data exists
- [ ] Data is sorted properly
- [ ] No API requests are made (check Network tab)
- [ ] Widget loads instantly (no loading spinner)
- [ ] Standard widget features work (rename, delete, move)
- [ ] Data refreshes when dashboard reloads

## Conclusion

The Site Notes widget demonstrates the simplest possible local data widget:

- ✅ Set `is_local = true`
- ✅ Return formatted data in `process_data()`
- ✅ Use standard DataTableWidget component
- ✅ Keep display separate from management

This pattern is perfect for displaying any local WordPress data in your custom dashboard!
