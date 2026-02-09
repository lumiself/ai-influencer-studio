<?php
/**
 * AJAX Handler for AI Influencer Studio
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIS_Ajax_Handler {
    
    private $replicate_api;
    private $predictions_handler;
    
    public function __construct($replicate_api, $predictions_handler = null) {
        $this->replicate_api = $replicate_api;
        $this->predictions_handler = $predictions_handler;
        
        add_action('wp_ajax_ais_generate_poses', [$this, 'generate_poses']);
        add_action('wp_ajax_ais_generate_dual_poses', [$this, 'generate_dual_poses']);
        add_action('wp_ajax_ais_synthesize_image', [$this, 'synthesize_image']);
        add_action('wp_ajax_ais_synthesize_dual_image', [$this, 'synthesize_dual_image']);
        add_action('wp_ajax_ais_synthesize_image_async', [$this, 'synthesize_image_async']);
        add_action('wp_ajax_ais_synthesize_dual_image_async', [$this, 'synthesize_dual_image_async']);
        add_action('wp_ajax_ais_poll_prediction', [$this, 'poll_prediction']);
        add_action('wp_ajax_ais_save_to_media', [$this, 'save_to_media']);
        add_action('wp_ajax_ais_upload_image', [$this, 'upload_image']);
    }
    
    /**
     * Verify nonce and permissions
     */
    private function verify_request() {
        if (!check_ajax_referer('ais_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'ai-influencer-studio')], 403);
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ai-influencer-studio')], 403);
        }
    }
    
    /**
     * Upload image from device
     */
    public function upload_image() {
        $this->verify_request();
        
        if (empty($_FILES['image'])) {
            wp_send_json_error(['message' => __('No image provided.', 'ai-influencer-studio')]);
        }
        
        $file = $_FILES['image'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            wp_send_json_error(['message' => __('Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.', 'ai-influencer-studio')]);
        }
        
        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            wp_send_json_error(['message' => __('File too large. Maximum size is 10MB.', 'ai-influencer-studio')]);
        }
        
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('image', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }
        
        $url = wp_get_attachment_url($attachment_id);
        
        wp_send_json_success([
            'id' => $attachment_id,
            'url' => $url
        ]);
    }
    
    /**
     * Get attachment URL by ID
     */
    private function get_attachment_url($attachment_id) {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return new WP_Error('invalid_attachment', __('Invalid attachment ID.', 'ai-influencer-studio'));
        }
        return $url;
    }
    
    /**
     * Generate poses for single model
     */
    public function generate_poses() {
        $this->verify_request();
        
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $outfit_id = isset($_POST['outfit_id']) ? intval($_POST['outfit_id']) : 0;
        $gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : 'female';
        $pose_preset = isset($_POST['pose_preset']) ? sanitize_text_field($_POST['pose_preset']) : 'casual';
        
        if (!$background_id) {
            wp_send_json_error(['message' => __('Background image is required.', 'ai-influencer-studio')]);
        }
        
        $background_url = $this->get_attachment_url($background_id);
        if (is_wp_error($background_url)) {
            wp_send_json_error(['message' => $background_url->get_error_message()]);
        }
        
        // Outfit is optional for pose generation
        $outfit_url = null;
        if ($outfit_id) {
            $outfit_url = $this->get_attachment_url($outfit_id);
            if (is_wp_error($outfit_url)) {
                $outfit_url = null; // Proceed without outfit if invalid
            }
        }
        
        $poses = $this->replicate_api->generate_poses($background_url, $gender, $pose_preset, $outfit_url);
        
        if (is_wp_error($poses)) {
            wp_send_json_error(['message' => $poses->get_error_message()]);
        }
        
        wp_send_json_success(['poses' => $poses]);
    }
    
    /**
     * Generate poses for dual models
     */
    public function generate_dual_poses() {
        $this->verify_request();
        
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $outfit1_id = isset($_POST['outfit1_id']) ? intval($_POST['outfit1_id']) : 0;
        $outfit2_id = isset($_POST['outfit2_id']) ? intval($_POST['outfit2_id']) : 0;
        $gender_a = isset($_POST['gender_a']) ? sanitize_text_field($_POST['gender_a']) : 'female';
        $gender_b = isset($_POST['gender_b']) ? sanitize_text_field($_POST['gender_b']) : 'male';
        $pose_preset = isset($_POST['pose_preset']) ? sanitize_text_field($_POST['pose_preset']) : 'casual';
        
        if (!$background_id) {
            wp_send_json_error(['message' => __('Background image is required.', 'ai-influencer-studio')]);
        }
        
        $background_url = $this->get_attachment_url($background_id);
        if (is_wp_error($background_url)) {
            wp_send_json_error(['message' => $background_url->get_error_message()]);
        }
        
        // Outfits are optional for pose generation
        $outfit1_url = null;
        $outfit2_url = null;
        if ($outfit1_id) {
            $outfit1_url = $this->get_attachment_url($outfit1_id);
            if (is_wp_error($outfit1_url)) {
                $outfit1_url = null;
            }
        }
        if ($outfit2_id) {
            $outfit2_url = $this->get_attachment_url($outfit2_id);
            if (is_wp_error($outfit2_url)) {
                $outfit2_url = null;
            }
        }
        
        $poses = $this->replicate_api->generate_dual_poses($background_url, $gender_a, $gender_b, $pose_preset, $outfit1_url, $outfit2_url);
        
        if (is_wp_error($poses)) {
            wp_send_json_error(['message' => $poses->get_error_message()]);
        }
        
        wp_send_json_success(['poses' => $poses]);
    }
    
    /**
     * Synthesize image for single model
     */
    public function synthesize_image() {
        $this->verify_request();
        
        $identity_id = isset($_POST['identity_id']) ? intval($_POST['identity_id']) : 0;
        $outfit_id = isset($_POST['outfit_id']) ? intval($_POST['outfit_id']) : 0;
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $pose_prompt = isset($_POST['pose_prompt']) ? sanitize_textarea_field($_POST['pose_prompt']) : '';
        
        if (!$identity_id || !$outfit_id || !$background_id || empty($pose_prompt)) {
            wp_send_json_error(['message' => __('All fields are required.', 'ai-influencer-studio')]);
        }
        
        $identity_url = $this->get_attachment_url($identity_id);
        $outfit_url = $this->get_attachment_url($outfit_id);
        $background_url = $this->get_attachment_url($background_id);
        
        if (is_wp_error($identity_url)) {
            wp_send_json_error(['message' => $identity_url->get_error_message()]);
        }
        if (is_wp_error($outfit_url)) {
            wp_send_json_error(['message' => $outfit_url->get_error_message()]);
        }
        if (is_wp_error($background_url)) {
            wp_send_json_error(['message' => $background_url->get_error_message()]);
        }
        
        $result = $this->replicate_api->synthesize_image($identity_url, $outfit_url, $background_url, $pose_prompt);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['image_url' => $result]);
    }
    
    /**
     * Synthesize image for single model (ASYNC - for shared hosting)
     */
    public function synthesize_image_async() {
        $this->verify_request();
        
        $identity_id = isset($_POST['identity_id']) ? intval($_POST['identity_id']) : 0;
        $outfit_id = isset($_POST['outfit_id']) ? intval($_POST['outfit_id']) : 0;
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $pose_prompt = isset($_POST['pose_prompt']) ? sanitize_textarea_field($_POST['pose_prompt']) : '';
        
        if (!$identity_id || !$outfit_id || !$background_id || empty($pose_prompt)) {
            wp_send_json_error(['message' => __('All fields are required.', 'ai-influencer-studio')]);
        }
        
        $identity_url = $this->get_attachment_url($identity_id);
        $outfit_url = $this->get_attachment_url($outfit_id);
        $background_url = $this->get_attachment_url($background_id);
        
        if (is_wp_error($identity_url)) {
            wp_send_json_error(['message' => $identity_url->get_error_message()]);
        }
        if (is_wp_error($outfit_url)) {
            wp_send_json_error(['message' => $outfit_url->get_error_message()]);
        }
        if (is_wp_error($background_url)) {
            wp_send_json_error(['message' => $background_url->get_error_message()]);
        }
        
        $result = $this->replicate_api->synthesize_image_async(
            $identity_url, 
            $outfit_url, 
            $background_url, 
            $pose_prompt,
            get_current_user_id()
        );
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Synthesize image for dual models
     */
    public function synthesize_dual_image() {
        $this->verify_request();
        
        $identity1_id = isset($_POST['identity1_id']) ? intval($_POST['identity1_id']) : 0;
        $outfit1_id = isset($_POST['outfit1_id']) ? intval($_POST['outfit1_id']) : 0;
        $identity2_id = isset($_POST['identity2_id']) ? intval($_POST['identity2_id']) : 0;
        $outfit2_id = isset($_POST['outfit2_id']) ? intval($_POST['outfit2_id']) : 0;
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $pose_prompt = isset($_POST['pose_prompt']) ? sanitize_textarea_field($_POST['pose_prompt']) : '';
        
        if (!$identity1_id || !$outfit1_id || !$identity2_id || !$outfit2_id || !$background_id || empty($pose_prompt)) {
            wp_send_json_error(['message' => __('All fields are required.', 'ai-influencer-studio')]);
        }
        
        $id1_url = $this->get_attachment_url($identity1_id);
        $outfit1_url = $this->get_attachment_url($outfit1_id);
        $id2_url = $this->get_attachment_url($identity2_id);
        $outfit2_url = $this->get_attachment_url($outfit2_id);
        $background_url = $this->get_attachment_url($background_id);
        
        foreach ([$id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url] as $url) {
            if (is_wp_error($url)) {
                wp_send_json_error(['message' => $url->get_error_message()]);
            }
        }
        
        $result = $this->replicate_api->synthesize_dual_image($id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url, $pose_prompt);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['image_url' => $result]);
    }
    
    /**
     * Synthesize image for dual models (ASYNC - for shared hosting)
     */
    public function synthesize_dual_image_async() {
        $this->verify_request();
        
        $identity1_id = isset($_POST['identity1_id']) ? intval($_POST['identity1_id']) : 0;
        $outfit1_id = isset($_POST['outfit1_id']) ? intval($_POST['outfit1_id']) : 0;
        $identity2_id = isset($_POST['identity2_id']) ? intval($_POST['identity2_id']) : 0;
        $outfit2_id = isset($_POST['outfit2_id']) ? intval($_POST['outfit2_id']) : 0;
        $background_id = isset($_POST['background_id']) ? intval($_POST['background_id']) : 0;
        $pose_prompt = isset($_POST['pose_prompt']) ? sanitize_textarea_field($_POST['pose_prompt']) : '';
        
        if (!$identity1_id || !$outfit1_id || !$identity2_id || !$outfit2_id || !$background_id || empty($pose_prompt)) {
            wp_send_json_error(['message' => __('All fields are required.', 'ai-influencer-studio')]);
        }
        
        $id1_url = $this->get_attachment_url($identity1_id);
        $outfit1_url = $this->get_attachment_url($outfit1_id);
        $id2_url = $this->get_attachment_url($identity2_id);
        $outfit2_url = $this->get_attachment_url($outfit2_id);
        $background_url = $this->get_attachment_url($background_id);
        
        foreach ([$id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url] as $url) {
            if (is_wp_error($url)) {
                wp_send_json_error(['message' => $url->get_error_message()]);
            }
        }
        
        $result = $this->replicate_api->synthesize_dual_image_async(
            $id1_url, 
            $outfit1_url, 
            $id2_url, 
            $outfit2_url, 
            $background_url, 
            $pose_prompt,
            get_current_user_id()
        );
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Poll prediction status
     */
    public function poll_prediction() {
        $this->verify_request();
        
        $prediction_id = isset($_POST['prediction_id']) ? sanitize_text_field($_POST['prediction_id']) : '';
        
        if (empty($prediction_id)) {
            wp_send_json_error(['message' => __('Prediction ID is required.', 'ai-influencer-studio')]);
        }
        
        // First check local database (webhook may have already updated it)
        if ($this->predictions_handler) {
            $local_prediction = $this->predictions_handler->get_user_prediction($prediction_id, get_current_user_id());
            
            if ($local_prediction && $local_prediction['status'] === 'succeeded') {
                $output = $local_prediction['output_data'];
                $image_url = is_array($output) ? $output[0] : $output;
                
                wp_send_json_success([
                    'status' => 'succeeded',
                    'image_url' => $image_url,
                ]);
            }
            
            if ($local_prediction && $local_prediction['status'] === 'failed') {
                wp_send_json_error([
                    'message' => $local_prediction['error_message'] ?: __('Image generation failed.', 'ai-influencer-studio'),
                    'status' => 'failed',
                ]);
            }
        }
        
        // Poll Replicate API directly
        $result = $this->replicate_api->get_prediction_status($prediction_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        $status = isset($result['status']) ? $result['status'] : 'unknown';
        
        if ($status === 'succeeded') {
            $output = $result['output'];
            $image_url = is_array($output) ? $output[0] : $output;
            
            // Update local database
            if ($this->predictions_handler) {
                $this->predictions_handler->update_prediction($prediction_id, [
                    'status' => 'succeeded',
                    'output_data' => wp_json_encode($output),
                ]);
            }
            
            wp_send_json_success([
                'status' => 'succeeded',
                'image_url' => $image_url,
            ]);
        }
        
        if ($status === 'failed' || $status === 'canceled') {
            $error = isset($result['error']) ? $result['error'] : __('Image generation failed.', 'ai-influencer-studio');
            
            // Update local database
            if ($this->predictions_handler) {
                $this->predictions_handler->update_prediction($prediction_id, [
                    'status' => $status,
                    'error_message' => $error,
                ]);
            }
            
            wp_send_json_error([
                'message' => $error,
                'status' => $status,
            ]);
        }
        
        // Still processing
        wp_send_json_success([
            'status' => $status,
            'image_url' => null,
        ]);
    }
    
    /**
     * Save generated image to WordPress Media Library
     */
    public function save_to_media() {
        $this->verify_request();
        
        $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
        
        if (empty($image_url)) {
            wp_send_json_error(['message' => __('Image URL is required.', 'ai-influencer-studio')]);
        }
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Download and sideload the image
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            wp_send_json_error(['message' => $tmp->get_error_message()]);
        }
        
        $file_array = [
            'name' => 'ai-influencer-' . time() . '.png',
            'tmp_name' => $tmp,
        ];
        
        $attachment_id = media_handle_sideload($file_array, 0, __('AI Influencer Studio Generated Image', 'ai-influencer-studio'));
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }
        
        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        ]);
    }
}
