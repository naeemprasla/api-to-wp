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
