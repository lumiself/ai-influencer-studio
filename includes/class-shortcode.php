<?php
/**
 * Frontend Shortcode Handler for AI Influencer Studio
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIS_Shortcode {
    
    private static $shortcode_rendered = false;
    
    public function __construct() {
        add_shortcode('ai_influencer_studio', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }
    
    /**
     * Register frontend assets (only loaded when shortcode is used)
     */
    public function register_assets() {
        wp_register_style(
            'ais-frontend-style',
            AIS_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            AIS_VERSION
        );
        
        wp_register_script(
            'ais-frontend-app',
            AIS_PLUGIN_URL . 'assets/js/frontend-app.js',
            ['jquery', 'wp-element'],
            AIS_VERSION,
            true
        );
    }
    
    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        // Parse attributes
        $atts = shortcode_atts([
            'mode' => 'single', // single or dual
            'show_tabs' => 'true',
        ], $atts, 'ai_influencer_studio');
        
        // Check if API key is configured
        $api_key = get_option('ais_replicate_api_key');
        if (empty($api_key)) {
            if (current_user_can('manage_options')) {
                return '<div class="ais-frontend-error">' . 
                    sprintf(
                        __('AI Influencer Studio: Please <a href="%s">configure your API key</a>.', 'ai-influencer-studio'),
                        admin_url('admin.php?page=ai-influencer-studio-settings')
                    ) . 
                    '</div>';
            }
            return '<div class="ais-frontend-error">' . __('This feature is currently unavailable.', 'ai-influencer-studio') . '</div>';
        }
        
        // Check user permissions (must be logged in)
        if (!is_user_logged_in()) {
            return '<div class="ais-frontend-error">' . __('Please log in to use the AI Studio.', 'ai-influencer-studio') . '</div>';
        }
        
        // Only render once per page
        if (self::$shortcode_rendered) {
            return '<div class="ais-frontend-error">' . __('AI Studio can only be displayed once per page.', 'ai-influencer-studio') . '</div>';
        }
        self::$shortcode_rendered = true;
        
        // Enqueue assets
        wp_enqueue_media();
        wp_enqueue_style('ais-frontend-style');
        wp_enqueue_script('ais-frontend-app');
        
        // Localize script data
        wp_localize_script('ais-frontend-app', 'aisFrontendData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ais_nonce'),
            'posePresets' => $this->get_pose_presets(),
            'genderOptions' => $this->get_gender_options(),
            'asyncMode' => true,
            'pollInterval' => 3000,
            'defaultMode' => sanitize_text_field($atts['mode']),
            'showTabs' => $atts['show_tabs'] === 'true',
            'i18n' => $this->get_translations(),
        ]);
        
        // Return the app container
        return '<div id="ais-frontend-root" class="ais-frontend-app"></div>';
    }
    
    /**
     * Get pose presets
     */
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
    
    /**
     * Get gender options
     */
    private function get_gender_options() {
        return [
            'male' => __('Male', 'ai-influencer-studio'),
            'female' => __('Female', 'ai-influencer-studio'),
            'nonbinary' => __('Non-binary', 'ai-influencer-studio'),
        ];
    }
    
    /**
     * Get frontend translations
     */
    private function get_translations() {
        return [
            'uploadIdentity' => __('Upload Identity', 'ai-influencer-studio'),
            'uploadOutfit' => __('Upload Outfit', 'ai-influencer-studio'),
            'uploadBackground' => __('Upload Background', 'ai-influencer-studio'),
            'tapToUpload' => __('Tap to upload', 'ai-influencer-studio'),
            'remove' => __('Remove', 'ai-influencer-studio'),
            'gender' => __('Gender', 'ai-influencer-studio'),
            'poseStyle' => __('Pose Style', 'ai-influencer-studio'),
            'generatePoses' => __('Generate Poses', 'ai-influencer-studio'),
            'analyzing' => __('Analyzing...', 'ai-influencer-studio'),
            'selectPose' => __('Select a Pose', 'ai-influencer-studio'),
            'editPose' => __('Edit pose (optional)', 'ai-influencer-studio'),
            'generateImage' => __('Generate Image', 'ai-influencer-studio'),
            'generating' => __('Generating...', 'ai-influencer-studio'),
            'saveToLibrary' => __('Save to Library', 'ai-influencer-studio'),
            'saving' => __('Saving...', 'ai-influencer-studio'),
            'saved' => __('Saved!', 'ai-influencer-studio'),
            'downloadImage' => __('Download', 'ai-influencer-studio'),
            'startOver' => __('Start Over', 'ai-influencer-studio'),
            'singleModel' => __('Single', 'ai-influencer-studio'),
            'dualModels' => __('Duo', 'ai-influencer-studio'),
            'modelA' => __('Model A', 'ai-influencer-studio'),
            'modelB' => __('Model B', 'ai-influencer-studio'),
            'background' => __('Background', 'ai-influencer-studio'),
            'errorUploadAll' => __('Please upload all required images.', 'ai-influencer-studio'),
            'errorSelectPose' => __('Please select a pose first.', 'ai-influencer-studio'),
            'errorNetwork' => __('Network error. Please try again.', 'ai-influencer-studio'),
            'step1' => __('Upload Photos', 'ai-influencer-studio'),
            'step2' => __('Choose Style', 'ai-influencer-studio'),
            'step3' => __('Select Pose', 'ai-influencer-studio'),
            'step4' => __('Result', 'ai-influencer-studio'),
        ];
    }
}
