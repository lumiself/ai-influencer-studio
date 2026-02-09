<?php
/**
 * Predictions Handler - Stores and manages async predictions
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIS_Predictions_Handler {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ais_predictions';
        
        // Register webhook REST endpoint
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }
    
    /**
     * Create predictions table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ais_predictions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            prediction_id varchar(255) NOT NULL,
            prediction_type varchar(50) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'starting',
            input_data longtext,
            output_data longtext,
            error_message text,
            user_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY prediction_id (prediction_id),
            KEY status (status),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Register webhook REST endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route('ai-influencer-studio/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Webhook is public but verified by prediction ID
        ]);
    }
    
    /**
     * Handle incoming webhook from Replicate
     */
    public function handle_webhook($request) {
        $body = $request->get_json_params();
        
        if (empty($body) || !isset($body['id'])) {
            return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        }
        
        $prediction_id = sanitize_text_field($body['id']);
        $status = isset($body['status']) ? sanitize_text_field($body['status']) : 'unknown';
        $output = isset($body['output']) ? $body['output'] : null;
        $error = isset($body['error']) ? sanitize_text_field($body['error']) : null;
        
        // Update prediction in database
        $this->update_prediction($prediction_id, [
            'status' => $status,
            'output_data' => $output ? wp_json_encode($output) : null,
            'error_message' => $error,
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * Store a new prediction
     */
    public function store_prediction($prediction_id, $type, $input_data, $user_id) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            [
                'prediction_id' => $prediction_id,
                'prediction_type' => $type,
                'status' => 'starting',
                'input_data' => wp_json_encode($input_data),
                'user_id' => $user_id,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );
    }
    
    /**
     * Update prediction status and data
     */
    public function update_prediction($prediction_id, $data) {
        global $wpdb;
        
        $update_data = [];
        $format = [];
        
        if (isset($data['status'])) {
            $update_data['status'] = $data['status'];
            $format[] = '%s';
        }
        
        if (isset($data['output_data'])) {
            $update_data['output_data'] = $data['output_data'];
            $format[] = '%s';
        }
        
        if (isset($data['error_message'])) {
            $update_data['error_message'] = $data['error_message'];
            $format[] = '%s';
        }
        
        return $wpdb->update(
            $this->table_name,
            $update_data,
            ['prediction_id' => $prediction_id],
            $format,
            ['%s']
        );
    }
    
    /**
     * Get prediction by ID
     */
    public function get_prediction($prediction_id) {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE prediction_id = %s",
                $prediction_id
            ),
            ARRAY_A
        );
        
        if ($result && $result['output_data']) {
            $result['output_data'] = json_decode($result['output_data'], true);
        }
        
        if ($result && $result['input_data']) {
            $result['input_data'] = json_decode($result['input_data'], true);
        }
        
        return $result;
    }
    
    /**
     * Get prediction by ID for specific user
     */
    public function get_user_prediction($prediction_id, $user_id) {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE prediction_id = %s AND user_id = %d",
                $prediction_id,
                $user_id
            ),
            ARRAY_A
        );
        
        if ($result && $result['output_data']) {
            $result['output_data'] = json_decode($result['output_data'], true);
        }
        
        return $result;
    }
    
    /**
     * Delete old predictions (cleanup)
     */
    public function cleanup_old_predictions($days = 7) {
        global $wpdb;
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
    
    /**
     * Get webhook URL
     */
    public function get_webhook_url() {
        return rest_url('ai-influencer-studio/v1/webhook');
    }
}
