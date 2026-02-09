<?php
/**
 * Admin Page Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIS_Admin_Page {
    
    private $replicate_api;
    
    public function __construct($replicate_api) {
        $this->replicate_api = $replicate_api;
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('AI Influencer Studio', 'ai-influencer-studio'),
            __('AI Studio', 'ai-influencer-studio'),
            'manage_options',
            'ai-influencer-studio',
            [$this, 'render_admin_page'],
            'dashicons-camera',
            30
        );
        
        add_submenu_page(
            'ai-influencer-studio',
            __('Settings', 'ai-influencer-studio'),
            __('Settings', 'ai-influencer-studio'),
            'manage_options',
            'ai-influencer-studio-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('ais_settings', 'ais_replicate_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        
        register_setting('ais_settings', 'ais_seedream_model', [
            'type' => 'string',
            'default' => 'bytedance/seedream-4',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }
    
    /**
     * Get available Seedream models
     */
    public function get_seedream_models() {
        return [
            'bytedance/seedream-4' => 'Seedream 4 (Faster, lower cost)',
            'bytedance/seedream-4.5' => 'Seedream 4.5 (Higher quality, 2K-4K)',
        ];
    }
    
    public function render_admin_page() {
        $api_key = get_option('ais_replicate_api_key');
        
        if (empty($api_key)) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('AI Influencer Studio', 'ai-influencer-studio') . '</h1>';
            echo '<div class="notice notice-warning"><p>';
            echo sprintf(
                __('Please <a href="%s">configure your Replicate API key</a> to get started.', 'ai-influencer-studio'),
                admin_url('admin.php?page=ai-influencer-studio-settings')
            );
            echo '</p></div></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Influencer Studio', 'ai-influencer-studio'); ?></h1>
            <div id="ais-app-root"></div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Influencer Studio Settings', 'ai-influencer-studio'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('ais_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ais_replicate_api_key">
                                <?php esc_html_e('Replicate API Key', 'ai-influencer-studio'); ?>
                            </label>
                        </th>
                        <td>
                            <input 
                                type="password" 
                                id="ais_replicate_api_key" 
                                name="ais_replicate_api_key" 
                                value="<?php echo esc_attr(get_option('ais_replicate_api_key')); ?>" 
                                class="regular-text"
                            />
                            <p class="description">
                                <?php 
                                printf(
                                    __('Get your API key from %s', 'ai-influencer-studio'),
                                    '<a href="https://replicate.com/account/api-tokens" target="_blank">Replicate</a>'
                                ); 
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ais_seedream_model">
                                <?php esc_html_e('Image Generation Model', 'ai-influencer-studio'); ?>
                            </label>
                        </th>
                        <td>
                            <select 
                                id="ais_seedream_model" 
                                name="ais_seedream_model" 
                                class="regular-text"
                            >
                                <?php 
                                $current_model = get_option('ais_seedream_model', 'bytedance/seedream-4');
                                foreach ($this->get_seedream_models() as $value => $label): 
                                ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_model, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Seedream 4.5 offers higher quality but only supports 2K-4K resolution (no 1K).', 'ai-influencer-studio'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
