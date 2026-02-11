<?php
/**
 * Plugin Name: AI Influencer Studio
 * Plugin URI: https://example.com/ai-influencer-studio
 * Description: Identity-consistent AI image generation with intelligent pose choreography.
 * Version: 1.0.1
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: ai-influencer-studio
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIS_VERSION', '1.0.1');
define('AIS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once AIS_PLUGIN_DIR . 'includes/class-predictions-handler.php';
require_once AIS_PLUGIN_DIR . 'includes/class-replicate-api.php';
require_once AIS_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once AIS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once AIS_PLUGIN_DIR . 'includes/class-shortcode.php';

/**
 * Main plugin class
 */
class AI_Influencer_Studio {
    
    private static $instance = null;
    private $admin_page;
    private $ajax_handler;
    private $replicate_api;
    private $predictions_handler;
    private $shortcode;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->predictions_handler = new AIS_Predictions_Handler();
        $this->replicate_api = new AIS_Replicate_API($this->predictions_handler);
        $this->admin_page = new AIS_Admin_Page($this->replicate_api);
        $this->ajax_handler = new AIS_Ajax_Handler($this->replicate_api, $this->predictions_handler);
        $this->shortcode = new AIS_Shortcode();
        
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_ai-influencer-studio') {
            return;
        }
        
        wp_enqueue_media();
        
        wp_enqueue_style(
            'ais-admin-style',
            AIS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AIS_VERSION
        );
        
        wp_enqueue_script(
            'ais-admin-app',
            AIS_PLUGIN_URL . 'assets/js/admin-app.js',
            ['jquery', 'wp-element', 'wp-components', 'wp-api-fetch'],
            AIS_VERSION,
            true
        );
        
        wp_localize_script('ais-admin-app', 'aisData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ais_nonce'),
            'posePresets' => $this->get_pose_presets(),
            'genderOptions' => $this->get_gender_options(),
            'asyncMode' => true, // Use async mode for shared hosting compatibility
            'pollInterval' => 3000, // Poll every 3 seconds
        ]);
    }
    
    private function get_pose_presets() {
        return [
            'casual' => __('Casual/Relaxed', 'ai-influencer-studio'),
            'editorial' => __('Editorial/High Fashion', 'ai-influencer-studio'),
            'commercial' => __('Commercial/Catalog', 'ai-influencer-studio'),
            'lifestyle' => __('Lifestyle/Candid', 'ai-influencer-studio'),
            'power' => __('Power/Corporate', 'ai-influencer-studio'),
            'romantic' => __('Romantic/Soft', 'ai-influencer-studio'),
            'athletic' => __('Athletic/Dynamic', 'ai-influencer-studio'),
            'seated' => __('Seated/Lounge', 'ai-influencer-studio'),
        ];
    }
    
    private function get_gender_options() {
        return [
            'male' => __('Male', 'ai-influencer-studio'),
            'female' => __('Female', 'ai-influencer-studio'),
            'nonbinary' => __('Non-binary', 'ai-influencer-studio'),
        ];
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    AI_Influencer_Studio::get_instance();
});

// Ensure subscriber role has upload_files capability (runs once per version)
add_action('init', function() {
    $cap_version = '1.0.1';
    if (get_option('ais_subscriber_cap_version') === $cap_version) {
        return;
    }
    $subscriber = get_role('subscriber');
    if ($subscriber) {
        $subscriber->add_cap('upload_files');
    }
    update_option('ais_subscriber_cap_version', $cap_version);
});

// Activation hook
register_activation_hook(__FILE__, function() {
    add_option('ais_replicate_api_key', '');
    AIS_Predictions_Handler::create_table();
    
    // Grant upload_files capability to subscriber role for frontend usage
    $subscriber = get_role('subscriber');
    if ($subscriber && !$subscriber->has_cap('upload_files')) {
        $subscriber->add_cap('upload_files');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Remove upload_files capability from subscriber role
    $subscriber = get_role('subscriber');
    if ($subscriber && $subscriber->has_cap('upload_files')) {
        $subscriber->remove_cap('upload_files');
    }
    delete_option('ais_subscriber_cap_version');
});
