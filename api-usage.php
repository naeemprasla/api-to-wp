
<?php 
// 1. Initialize with API endpoint
$importer = new APItoWP('https://jsonplaceholder.typicode.com');

// 2. Fetch sample data
$sample_post = $importer->fetch('/posts/1');

// 3. Generate mapping automatically
$mapping = $importer->generate_mapping($sample_post, [
    'title_field' => 'title',
    'content_field' => 'body'
]);

// 4. Import all posts
$posts = $importer->fetch('/posts');


foreach ($posts as $post) {
    $post_id = $importer->save(
        $post,
        'imported_post', // Your custom post type
        $mapping,
        'id' // Unique field to prevent duplicates
    );
    
    if (!is_wp_error($post_id)) {
        echo "Imported post #{$post['id']} as WP ID $post_id\n";
    }
}


// 1. Initialize the importer
$importer = new APItoWP('https://jsonplaceholder.typicode.com');

// 2. Fetch ALL posts (typically paginated in real APIs)
$all_posts = $importer->fetch('/posts');

// 3. Generate mapping from the first post (assuming consistent structure)
if (!empty($all_posts)) {
    $mapping = $importer->generate_mapping($all_posts[0], [
        'title_field'   => 'title',
        'content_field' => 'body',
        'max_depth'     => 2 // Limit nested array processing
    ]);
    
    // 4. Process all posts
    $import_results = [];
    foreach ($all_posts as $post) {
        $post_id = $importer->save(
            $post,
            'imported_post', // Your custom post type
            $mapping,
            'id' // Unique identifier field
        );
        
        $import_results[] = [
            'api_id' => $post['id'],
            'wp_id'  => is_wp_error($post_id) ? $post_id->get_error_message() : $post_id,
            'status' => is_wp_error($post_id) ? 'failed' : 'success'
        ];
    }
    
    // 5. Output results
    echo "<h2>Import Results</h2>";
    echo "<table border='1'><tr><th>API ID</th><th>WP ID</th><th>Status</th></tr>";
    foreach ($import_results as $result) {
        echo "<tr>
                <td>{$result['api_id']}</td>
                <td>{$result['wp_id']}</td>
                <td>{$result['status']}</td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "No posts found in API response";
}



// 1. Initialize the importer
$importer = new APItoWP('https://jsonplaceholder.typicode.com');

// 2. Fetch all posts
$all_posts = $importer->fetch('/posts');

// Prepare JSON response structure
$response = [
    'status' => 'success',
    'data' => [
        'total_posts' => 0,
        'imported' => 0,
        'failed' => 0,
        'results' => []
    ],
    'timestamp' => date('c')
];

if (!empty($all_posts)) {
    // 3. Generate mapping from first post
    $mapping = $importer->generate_mapping($all_posts[0], [
        'title_field' => 'title',
        'content_field' => 'body'
    ]);
    
    // 4. Process all posts
    foreach ($all_posts as $post) {
        $result = [
            'api_id' => $post['id'],
            'wp_id' => null,
            'status' => 'pending',
            'error' => null
        ];
        
        $post_id = $importer->save(
            $post,
            'imported_post',
            $mapping,
            'id'
        );
        
        if (is_wp_error($post_id)) {
            $response['data']['failed']++;
            $result['status'] = 'failed';
            $result['error'] = $post_id->get_error_message();
        } else {
            $response['data']['imported']++;
            $result['status'] = 'success';
            $result['wp_id'] = $post_id;
        }
        
        $response['data']['results'][] = $result;
    }
    
    $response['data']['total_posts'] = count($all_posts);
} else {
    $response['status'] = 'error';
    $response['error'] = 'No posts found in API response';
}

// 5. Output as JSON
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
