<?php
/**
 * WordPress API Integration Tool
 * - Fetch API data
 * - Auto-generate field mappings
 * - Create posts with ACF fields
 * - Handle repeaters, galleries, and images
 */
class APItoWP {

    private $api_url;
    private $default_headers;

    public function __construct($api_url = '', $headers = []) {
        $this->api_url = rtrim($api_url, '/');
        $this->default_headers = $headers;
    }

    // ====================
    // CORE FUNCTIONALITY
    // ====================

    /**
     * Fetch data from API
     */
    public function fetch($endpoint, $method = 'GET', $params = [], $body = [], $headers = []) {
        $url = $this->api_url . '/' . ltrim($endpoint, '/');
        
        if ($method === 'GET' && !empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => array_merge($this->default_headers, $headers),
            'timeout' => 30,
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
            if (!isset($headers['Content-Type'])) {
                $args['headers']['Content-Type'] = 'application/json';
            }
        }

        $response = wp_remote_request($url, $args);
        return $this->handle_response($response);
    }

    /**
     * Generate field mapping automatically
     */
    public function generate_mapping($sample_data, $options = []) {
        $defaults = [
            'title_field'    => 'title',
            'content_field'  => 'content',
            'detect_images'  => true,
            'max_depth'      => 3
        ];
        $options = array_merge($defaults, $options);
        
        $mapping = [];
        
        // Map title and content if they exist
        if (isset($sample_data[$options['title_field']])) {
            $mapping['post_title'] = $options['title_field'];
        }
        
        if (isset($sample_data[$options['content_field']])) {
            $mapping['post_content'] = $options['content_field'];
        }
        
        // Process other fields
        foreach ($sample_data as $field => $value) {
            if (in_array($field, [$options['title_field'], $options['content_field']])) {
                continue;
            }
            
            if (is_array($value)) {
                if ($this->is_repeater_candidate($value, $options)) {
                    $mapping[$field] = $this->map_repeater_field($field, $value, $options);
                }
                continue;
            }
            
            if ($options['detect_images'] && $this->is_image($value)) {
                $mapping[$field] = [
                    'path' => $field,
                    'acf_config' => ['type' => 'image']
                ];
                continue;
            }
            
            $mapping[$field] = $field;
        }
        
        return $mapping;
    }

    /**
     * Save data to WordPress
     */
    public function save($api_data, $post_type, $mapping, $unique_field = null, $create_fields = true) {
        $transformed = $this->transform_data($api_data, $mapping);
        $post_id = $this->find_existing_post($post_type, $unique_field, $transformed[$unique_field] ?? null);
        
        $post_id = wp_insert_post([
            'ID'           => $post_id,
            'post_type'    => $post_type,
            'post_status'  => 'publish',
            'post_title'   => $transformed['post_title'] ?? '',
            'post_content' => $transformed['post_content'] ?? '',
        ], true);

        if (!is_wp_error($post_id)) {
            $this->process_acf_fields($post_id, $post_type, $transformed, $create_fields);
        }

        return $post_id;
    }

    // ====================
    // PRIVATE METHODS
    // ====================

    private function handle_response($response) {
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return ($code >= 200 && $code < 300) 
            ? ($body ?: wp_remote_retrieve_body($response))
            : new WP_Error('api_error', $body['message'] ?? 'API request failed', [
                'status' => $code,
                'response' => $body
            ]);
    }

    private function transform_data($data, $mapping) {
        $result = [];
        foreach ($mapping as $wp_field => $api_field) {
            if (is_array($api_field) && ($api_field['repeater'] ?? false)) {
                $result[$wp_field] = $this->process_repeater($data, $api_field);
            } else {
                $value = $this->get_nested_value($data, $api_field);
                $result[$wp_field] = is_array($api_field) && isset($api_field['filter']) 
                    ? $this->apply_filter($value, $api_field['filter']) 
                    : $value;
            }
        }
        return $result;
    }

    private function process_acf_fields($post_id, $post_type, $data, $create_fields) {
        if (!function_exists('update_field')) return;

        $standard_fields = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'post_date'];
        $field_group = $this->get_field_group($post_type);

        foreach ($data as $field => $value) {
            if (in_array($field, $standard_fields)) continue;

            if ($create_fields && !$this->field_exists($field, $post_type)) {
                $this->create_acf_field($field, $value, $field_group['ID'], $post_type);
            }

            if ($this->is_repeater($field, $post_id)) {
                $this->update_repeater($post_id, $field, $value);
            } else {
                update_field($field, $value, $post_id);
            }
        }
    }

    // ====================
    // FIELD MAPPING HELPERS
    // ====================

    private function is_repeater_candidate($array, $options) {
        if (empty($array)) return false;
        
        $first = reset($array);
        return is_array($first) && 
               $options['max_depth'] > 0 && 
               !$this->is_image_array($first);
    }

    private function map_repeater_field($field_name, $sample_data, $options) {
        $first_item = reset($sample_data);
        $sub_fields = [];
        
        foreach ($first_item as $sub_field => $sub_value) {
            if (is_array($sub_value) && $options['max_depth'] > 1) {
                $sub_options = $options;
                $sub_options['max_depth']--;
                
                if ($this->is_repeater_candidate($sub_value, $sub_options)) {
                    $sub_fields[$sub_field] = $this->map_repeater_field(
                        $sub_field, 
                        $sub_value, 
                        $sub_options
                    );
                }
                continue;
            }
            
            $sub_fields[$sub_field] = $sub_field;
        }
        
        return [
            'repeater' => true,
            'path' => $field_name,
            'sub_fields' => $sub_fields
        ];
    }

    // ====================
    // ACF FIELD MANAGEMENT
    // ====================

    private function create_acf_field($field_name, $sample_value, $group_id, $post_type) {
        $field_type = $this->determine_field_type($sample_value);
        $field = [
            'key'    => 'field_' . uniqid(),
            'label'  => ucwords(str_replace('_', ' ', $field_name)),
            'name'   => $field_name,
            'type'   => $field_type,
            'parent' => $group_id
        ];

        if ($field_type === 'repeater') {
            $field['sub_fields'] = $this->generate_repeater_subfields($sample_value);
        }

        if (in_array($field_type, ['image', 'gallery'])) {
            $field['return_format'] = 'array';
            $field['mime_types'] = 'jpg,jpeg,png,gif,webp';
        }

        acf_add_local_field($field);
    }

    private function generate_repeater_subfields($sample_data) {
        $first_item = reset($sample_data);
        $sub_fields = [];
        
        foreach ($first_item as $sub_field => $sub_value) {
            $sub_fields[] = [
                'key'   => 'field_' . uniqid(),
                'label' => ucwords(str_replace('_', ' ', $sub_field)),
                'name'  => $sub_field,
                'type'  => $this->determine_field_type($sub_value)
            ];
        }
        
        return $sub_fields;
    }

    // ====================
    // UTILITY METHODS
    // ====================

    private function determine_field_type($value) {
        if (is_array($value)) {
            if (!empty($value) && is_array(reset($value))) return 'repeater';
            if ($this->is_image_array($value)) return 'gallery';
            return 'checkbox';
        }
        
        if (is_numeric($value)) return 'number';
        if (is_bool($value)) return 'true_false';
        if (filter_var($value, FILTER_VALIDATE_URL)) return $this->is_image($value) ? 'image' : 'url';
        if (strtotime($value) !== false) return 'date_time_picker';
        if (strlen($value) > 255) return 'textarea';
        return 'text';
    }

    private function is_image($value) {
        if (!is_string($value)) return false;
        $ext = strtolower(pathinfo(parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
    }

    private function is_image_array($array) {
        if (empty($array)) return false;
        $first = reset($array);
        return is_string($first) ? $this->is_image($first) : 
              (is_array($first) && isset($first['url']) && $this->is_image($first['url']));
    }

    private function get_nested_value($data, $path) {
        if (is_array($path) && isset($path['path'])) $path = $path['path'];
        $keys = explode('.', $path);
        $value = $data;
        foreach ($keys as $key) {
            if (!isset($value[$key])) return null;
            $value = $value[$key];
        }
        return $value;
    }

    private function apply_filter($value, $filter) {
        if (is_callable($filter)) return $filter($value);
        switch ($filter) {
            case 'int': return (int)$value;
            case 'float': return (float)$value;
            case 'bool': return (bool)$value;
            case 'string': return (string)$value;
            case 'date': return date('Y-m-d H:i:s', strtotime($value));
            default: return $value;
        }
    }

    private function find_existing_post($post_type, $meta_key, $meta_value) {
        if (!$meta_key || !$meta_value) return null;
        
        $query = new WP_Query([
            'post_type'      => $post_type,
            'posts_per_page' => 1,
            'meta_key'       => $meta_key,
            'meta_value'    => $meta_value,
            'fields'         => 'ids',
        ]);
        
        return $query->have_posts() ? $query->posts[0] : null;
    }

    private function is_repeater($field_name, $post_id) {
        $field = get_field_object($field_name, $post_id);
        return $field && $field['type'] === 'repeater';
    }

    private function update_repeater($post_id, $field_name, $rows) {
        delete_field($field_name, $post_id);
        foreach ($rows as $row) {
            add_row($field_name, $row, $post_id);
        }
    }

    private function field_exists($field_name, $post_type) {
        $fields = acf_get_fields($this->get_field_group($post_type)['ID'] ?? []);
        foreach ($fields as $field) {
            if ($field['name'] === $field_name) return true;
            if ($field['type'] === 'repeater') {
                foreach ($field['sub_fields'] as $sub_field) {
                    if ($sub_field['name'] === $field_name) return true;
                }
            }
        }
        return false;
    }

    private function get_field_group($post_type) {
        $groups = acf_get_field_groups(['post_type' => $post_type]);
        return empty($groups) ? $this->create_field_group($post_type) : $groups[0];
    }

    private function create_field_group($post_type) {
        $post_type_obj = get_post_type_object($post_type);
        $group = [
            'key'    => 'group_' . uniqid(),
            'title'  => $post_type_obj->labels->singular_name . ' Fields',
            'fields' => [],
            'location' => [[[
                'param' => 'post_type',
                'operator' => '==',
                'value' => $post_type,
            ]]],
        ];
        acf_add_local_field_group($group);
        return $group;
    }
}