# API to WordPress

A powerful PHP class for importing API data into WordPress with automatic field mapping and Advanced Custom Fields (ACF) support.

## Features

### 🔄 Automatic Data Processing
- **Smart Field Mapping** - Automatically detects and maps API fields to WordPress
- **ACF Integration** - Creates custom fields on-the-fly
- **Repeater Support** - Handles nested/repeated data structures

### 🛡 Robust Error Handling
- Detailed error logging
- Preserves original API IDs
- Clear success/failure reporting

### 📊 Comprehensive Reporting
- JSON-formatted output
- Import statistics
- Timestamped results

## Installation

1. Copy the `APItoWP.php` file to your WordPress plugin or theme directory
2. Include it in your project:



```php
require_once 'path/to/APItoWP.php';

// Initialize with your API endpoint
$importer = new APItoWP('https://api.example.com');

// Fetch and import posts
$posts = $importer->fetch('/posts');
$mapping = $importer->generate_mapping($posts[0]);
$results = $importer->save_all($posts, 'custom_post_type', $mapping);

// Output results as JSON
header('Content-Type: application/json');
echo json_encode($results);

Advance Config

$options = [
    'title_field' => 'title',          // Field to use for post title
    'content_field' => 'description',  // Field to use for content
    'unique_field' => 'external_id',   // Field to prevent duplicates
    'max_depth' => 3,                  // For nested data structures
    'detect_images' => true,           // Auto-create image fields
    'auto_create_fields' => true       // Create missing ACF fields
];

$mapping = $importer->generate_mapping($sample_data, $options);



