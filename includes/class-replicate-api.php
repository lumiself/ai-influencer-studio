<?php
/**
 * Replicate API Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIS_Replicate_API {
    
    private $api_base = 'https://api.replicate.com/v1';
    private $choreographer_model = 'openai/gpt-5-nano';
    private $synthesis_model = 'bytedance/seedream-4';
    private $predictions_handler;
    
    public function __construct($predictions_handler = null) {
        $this->predictions_handler = $predictions_handler;
    }
    
    /**
     * Get API key from options
     */
    private function get_api_key() {
        return get_option('ais_replicate_api_key', '');
    }
    
    /**
     * Get webhook URL
     */
    private function get_webhook_url() {
        if ($this->predictions_handler) {
            return $this->predictions_handler->get_webhook_url();
        }
        return null;
    }
    
    /**
     * Make API request to Replicate
     */
    private function request($endpoint, $data, $wait = true) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Replicate API key not configured.', 'ai-influencer-studio'));
        }
        
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ];
        
        if ($wait) {
            $headers['Prefer'] = 'wait';
        }
        
        $response = wp_remote_post($this->api_base . $endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode($data),
            'timeout' => $wait ? 120 : 30,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API.', 'ai-influencer-studio'));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_msg = isset($result['detail']) ? $result['detail'] : __('API request failed.', 'ai-influencer-studio');
            return new WP_Error('api_error', $error_msg);
        }
        
        // Check prediction status
        if (isset($result['status'])) {
            if ($result['status'] === 'failed') {
                $error_msg = isset($result['error']) ? $result['error'] : __('Prediction failed.', 'ai-influencer-studio');
                return new WP_Error('prediction_failed', $error_msg);
            }
            if ($result['status'] === 'canceled') {
                return new WP_Error('prediction_canceled', __('Prediction was canceled.', 'ai-influencer-studio'));
            }
        }
        
        return $result;
    }
    
    /**
     * Make async API request to Replicate (returns prediction ID immediately)
     */
    private function request_async($endpoint, $data, $type, $user_id) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Replicate API key not configured.', 'ai-influencer-studio'));
        }
        
        // Add webhook URL
        $webhook_url = $this->get_webhook_url();
        if ($webhook_url) {
            $data['webhook'] = $webhook_url;
            $data['webhook_events_filter'] = ['completed'];
        }
        
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ];
        
        $response = wp_remote_post($this->api_base . $endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode($data),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API.', 'ai-influencer-studio'));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_msg = isset($result['detail']) ? $result['detail'] : __('API request failed.', 'ai-influencer-studio');
            return new WP_Error('api_error', $error_msg);
        }
        
        // Store prediction in database
        if (isset($result['id']) && $this->predictions_handler) {
            $this->predictions_handler->store_prediction(
                $result['id'],
                $type,
                $data['input'],
                $user_id
            );
        }
        
        return $result;
    }
    
    /**
     * Poll prediction status from Replicate
     */
    public function get_prediction_status($prediction_id) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Replicate API key not configured.', 'ai-influencer-studio'));
        }
        
        $response = wp_remote_get($this->api_base . '/predictions/' . $prediction_id, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API.', 'ai-influencer-studio'));
        }
        
        return $result;
    }
    
    /**
     * Generate poses using GPT-5-Nano choreographer (Single Model)
     */
    public function generate_poses($background_url, $gender, $pose_preset, $outfit_url = null) {
        $has_outfit = !empty($outfit_url);
        $system_prompt = $this->build_choreographer_system_prompt($gender, $pose_preset, $has_outfit);
        
        // Build image input array
        $image_input = [$background_url];
        if ($has_outfit) {
            $image_input[] = $outfit_url;
        }
        
        $prompt_text = $has_outfit 
            ? 'Analyze the background image (Image 1) and outfit image (Image 2) to generate 5 pose suggestions that complement this outfit.'
            : 'Analyze this background image and generate 5 pose suggestions.';
        
        $data = [
            'input' => [
                'system_prompt' => $system_prompt,
                'prompt' => $prompt_text,
                'image_input' => $image_input,
                'reasoning_effort' => 'minimal',
                'verbosity' => 'low',
            ],
        ];
        
        $result = $this->request('/models/' . $this->choreographer_model . '/predictions', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $this->parse_pose_response($result);
    }
    
    /**
     * Generate poses for dual models using GPT-5-Nano
     */
    public function generate_dual_poses($background_url, $gender_a, $gender_b, $pose_preset, $outfit1_url = null, $outfit2_url = null) {
        $has_outfits = !empty($outfit1_url) || !empty($outfit2_url);
        $system_prompt = $this->build_dual_choreographer_system_prompt($gender_a, $gender_b, $pose_preset, $outfit1_url, $outfit2_url);
        
        // Build image input array
        $image_input = [$background_url];
        if (!empty($outfit1_url)) {
            $image_input[] = $outfit1_url;
        }
        if (!empty($outfit2_url)) {
            $image_input[] = $outfit2_url;
        }
        
        $prompt_text = $has_outfits
            ? 'Analyze the background image and outfit images to generate 5 duo pose suggestions that complement the outfits.'
            : 'Analyze this background image and generate 5 duo pose suggestions for two models.';
        
        $data = [
            'input' => [
                'system_prompt' => $system_prompt,
                'prompt' => $prompt_text,
                'image_input' => $image_input,
                'reasoning_effort' => 'minimal',
                'verbosity' => 'low',
            ],
        ];
        
        $result = $this->request('/models/' . $this->choreographer_model . '/predictions', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $this->parse_pose_response($result);
    }
    
    /**
     * Synthesize final image using Seedream 4 (Single Model) - ASYNC
     * Returns prediction ID for polling
     */
    public function synthesize_image_async($identity_url, $outfit_url, $background_url, $pose_prompt, $user_id) {
        $full_prompt = $pose_prompt . ' Maintain the identity from Image 1 and the clothing details from Image 2.';
        
        $data = [
            'input' => [
                'prompt' => $full_prompt,
                'image_input' => [$identity_url, $outfit_url, $background_url],
                'size' => '4K',
                'width' => 2048,
                'height' => 2048,
                'max_images' => 1,
                'aspect_ratio' => '4:3',
                'enhance_prompt' => true,
                'sequential_image_generation' => 'disabled',
            ],
        ];
        
        $result = $this->request_async('/models/' . $this->synthesis_model . '/predictions', $data, 'synthesis_single', $user_id);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!isset($result['id'])) {
            return new WP_Error('no_prediction_id', __('Failed to start image generation.', 'ai-influencer-studio'));
        }
        
        return [
            'prediction_id' => $result['id'],
            'status' => $result['status'],
        ];
    }
    
    /**
     * Synthesize final image using Seedream 4 (Single Model) - SYNC (kept for backward compatibility)
     */
    public function synthesize_image($identity_url, $outfit_url, $background_url, $pose_prompt) {
        $full_prompt = $pose_prompt . ' Maintain the identity from Image 1 and the clothing details from Image 2.';
        
        $data = [
            'input' => [
                'prompt' => $full_prompt,
                'image_input' => [$identity_url, $outfit_url, $background_url],
                'size' => '4K',
                'width' => 2048,
                'height' => 2048,
                'max_images' => 1,
                'aspect_ratio' => '4:3',
                'enhance_prompt' => true,
                'sequential_image_generation' => 'disabled',
            ],
        ];
        
        $result = $this->request('/models/' . $this->synthesis_model . '/predictions', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $this->extract_output_url($result);
    }
    
    /**
     * Synthesize final image using Seedream 4 (Dual Model) - ASYNC
     * Returns prediction ID for polling
     */
    public function synthesize_dual_image_async($id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url, $pose_prompt, $user_id) {
        $full_prompt = $pose_prompt . ' Maintain identity of Model A from Image 1 with clothing from Image 2, and identity of Model B from Image 3 with clothing from Image 4.';
        
        $data = [
            'input' => [
                'prompt' => $full_prompt,
                'image_input' => [$id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url],
                'size' => '4K',
                'width' => 2048,
                'height' => 2048,
                'max_images' => 1,
                'aspect_ratio' => '4:3',
                'enhance_prompt' => true,
                'sequential_image_generation' => 'disabled',
            ],
        ];
        
        $result = $this->request_async('/models/' . $this->synthesis_model . '/predictions', $data, 'synthesis_dual', $user_id);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (!isset($result['id'])) {
            return new WP_Error('no_prediction_id', __('Failed to start image generation.', 'ai-influencer-studio'));
        }
        
        return [
            'prediction_id' => $result['id'],
            'status' => $result['status'],
        ];
    }
    
    /**
     * Synthesize final image using Seedream 4 (Dual Model) - SYNC (kept for backward compatibility)
     */
    public function synthesize_dual_image($id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url, $pose_prompt) {
        $full_prompt = $pose_prompt . ' Maintain identity of Model A from Image 1 with clothing from Image 2, and identity of Model B from Image 3 with clothing from Image 4.';
        
        $data = [
            'input' => [
                'prompt' => $full_prompt,
                'image_input' => [$id1_url, $outfit1_url, $id2_url, $outfit2_url, $background_url],
                'size' => '4K',
                'width' => 2048,
                'height' => 2048,
                'max_images' => 1,
                'aspect_ratio' => '4:3',
                'enhance_prompt' => true,
                'sequential_image_generation' => 'disabled',
            ],
        ];
        
        $result = $this->request('/models/' . $this->synthesis_model . '/predictions', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $this->extract_output_url($result);
    }
    
    /**
     * Build system prompt for single model choreographer
     */
    private function build_choreographer_system_prompt($gender, $pose_preset, $has_outfit = false) {
        $preset_descriptions = [
            'casual' => 'Natural, everyday poses (leaning, hands in pockets, relaxed stance)',
            'editorial' => 'Dramatic angles, elongated limbs, avant-garde positioning',
            'commercial' => 'Clean, product-focused poses showcasing outfit clearly',
            'lifestyle' => 'In-motion shots, laughing, walking, natural interactions',
            'power' => 'Confident stances, crossed arms, authoritative positioning',
            'romantic' => 'Gentle movements, flowing poses, dreamy expressions',
            'athletic' => 'Action poses, mid-stride, jumping, athletic stances',
            'seated' => 'Sitting on steps, benches, ground, relaxed seated positions',
        ];
        
        $preset_desc = isset($preset_descriptions[$pose_preset]) ? $preset_descriptions[$pose_preset] : $preset_descriptions['casual'];
        
        $outfit_context = $has_outfit 
            ? "\n- Image 2 contains the outfit the model will wear. Analyze the outfit style (formal, casual, sporty, elegant, etc.) and tailor poses to complement and showcase it effectively."
            : '';
        
        $outfit_instruction = $has_outfit
            ? ' Consider the outfit style from Image 2 when suggesting poses - ensure poses highlight the clothing\'s best features.'
            : '';
        
        return "You are a Senior Fashion Editorial Choreographer analyzing background images for realistic pose placement.{$outfit_instruction}

Your task: Generate 5 distinct, professional poses for a {$gender} model appropriate for the '{$pose_preset}' style ({$preset_desc}).

Constraints:
- Image 1 is the background. Identify walkable/sit-able areas in the background (steps, ground, walls, furniture).{$outfit_context}
- Tailor poses to be gender-appropriate.
- All poses must align with the {$pose_preset} style.
- Output ONLY a valid JSON array of 5 strings.
- Each string must follow this exact format: \"The {$gender} model from [Image 1] in the [Image 2] outfit is [POSE DESCRIPTION] in [Image 3]; 85mm lens.\"

Example output format:
[
  \"The {$gender} model from [Image 1] in the [Image 2] outfit is leaning against the brick wall with one hand in pocket in [Image 3]; 85mm lens.\",
  \"The {$gender} model from [Image 1] in the [Image 2] outfit is walking confidently down the steps in [Image 3]; 85mm lens.\"
]";
    }
    
    /**
     * Build system prompt for dual model choreographer
     */
    private function build_dual_choreographer_system_prompt($gender_a, $gender_b, $pose_preset, $outfit1_url = null, $outfit2_url = null) {
        $preset_descriptions = [
            'casual' => 'Natural, everyday poses',
            'editorial' => 'Dramatic angles, avant-garde positioning',
            'commercial' => 'Clean, product-focused poses',
            'lifestyle' => 'In-motion, natural interactions',
            'power' => 'Confident, authoritative positioning',
            'romantic' => 'Gentle movements, flowing poses',
            'athletic' => 'Action poses, dynamic stances',
            'seated' => 'Relaxed seated positions',
        ];
        
        $preset_desc = isset($preset_descriptions[$pose_preset]) ? $preset_descriptions[$pose_preset] : $preset_descriptions['casual'];
        
        // Build outfit context based on which outfits are provided
        $outfit_context = '';
        $outfit_instruction = '';
        
        if (!empty($outfit1_url) && !empty($outfit2_url)) {
            $outfit_context = "\n- Image 2 shows Model A's outfit, Image 3 shows Model B's outfit. Analyze both outfit styles and suggest poses that complement and coordinate both looks.";
            $outfit_instruction = ' Consider the outfit styles when suggesting poses - ensure poses highlight both outfits effectively and create visual harmony.';
        } elseif (!empty($outfit1_url)) {
            $outfit_context = "\n- Image 2 shows Model A's outfit. Analyze the outfit style and ensure Model A's poses showcase it effectively.";
            $outfit_instruction = ' Consider Model A\'s outfit style when suggesting poses.';
        } elseif (!empty($outfit2_url)) {
            $outfit_context = "\n- Image 2 shows Model B's outfit. Analyze the outfit style and ensure Model B's poses showcase it effectively.";
            $outfit_instruction = ' Consider Model B\'s outfit style when suggesting poses.';
        }
        
        return "You are a Senior Fashion Editorial Choreographer analyzing background images for realistic duo pose placement.{$outfit_instruction}

Your task: Generate 5 distinct, professional duo poses for two models:
- Model A: {$gender_a}
- Model B: {$gender_b}

Style: '{$pose_preset}' ({$preset_desc})

Constraints:
- Image 1 is the background. Identify valid positions for two people.{$outfit_context}
- Tailor poses to be gender-appropriate.
- All poses must align with the {$pose_preset} style.
- For mixed-gender pairs, consider classic editorial dynamics (complementary body language, height differences).
- Poses should show realistic interaction (conversation, walking together, one seated/one standing).
- Output ONLY a valid JSON array of 5 strings.
- Each string must follow: \"The {$gender_a} model from [Image 1] in the [Image 2] outfit is [POSE_A], while the {$gender_b} model from [Image 3] in the [Image 4] outfit is [POSE_B] in [Image 5]; 85mm lens.\"";
    }
    
    /**
     * Parse pose response from GPT-5-Nano
     */
    private function parse_pose_response($result) {
        // Debug: log the full response structure
        error_log('AIS Choreographer response: ' . wp_json_encode($result));
        
        // Handle different response structures from Replicate
        $output = null;
        
        // Standard output field
        if (isset($result['output'])) {
            $output = $result['output'];
        }
        // Some models return in choices array (OpenAI style)
        elseif (isset($result['choices'][0]['message']['content'])) {
            $output = $result['choices'][0]['message']['content'];
        }
        // Nested output structure
        elseif (isset($result['output']['choices'][0]['message']['content'])) {
            $output = $result['output']['choices'][0]['message']['content'];
        }
        // Direct text response
        elseif (isset($result['text'])) {
            $output = $result['text'];
        }
        // Response field
        elseif (isset($result['response'])) {
            $output = $result['response'];
        }
        
        if ($output === null) {
            // Include available keys in error for debugging
            $available_keys = is_array($result) ? implode(', ', array_keys($result)) : 'not an array';
            return new WP_Error('no_output', sprintf(
                __('No output from choreographer. Available fields: %s', 'ai-influencer-studio'),
                $available_keys
            ));
        }
        
        // If output is an array, try to concatenate or find the text
        if (is_array($output)) {
            // Could be array of strings (streaming response)
            if (isset($output[0]) && is_string($output[0])) {
                $output = implode('', $output);
            }
            // Could be the poses array directly
            elseif (isset($output[0]) && is_string($output[0]) && strpos($output[0], 'model from [Image') !== false) {
                return $output;
            }
        }
        
        // Handle if output is a string (may need JSON parsing)
        if (is_string($output)) {
            // Try to extract JSON array from the response
            preg_match('/\[[\s\S]*\]/s', $output, $matches);
            if (!empty($matches[0])) {
                $poses = json_decode($matches[0], true);
                if (is_array($poses) && !empty($poses)) {
                    return $poses;
                }
            }
            
            // If we couldn't parse, return the raw output for debugging
            return new WP_Error('parse_error', sprintf(
                __('Could not parse pose suggestions. Raw output: %s', 'ai-influencer-studio'),
                substr($output, 0, 500)
            ));
        }
        
        return new WP_Error('invalid_output', sprintf(
            __('Invalid output format from choreographer. Type: %s', 'ai-influencer-studio'),
            gettype($output)
        ));
    }
    
    /**
     * Extract output URL from Seedream 4 response
     */
    private function extract_output_url($result) {
        if (!isset($result['output'])) {
            return new WP_Error('no_output', __('No output from image generator.', 'ai-influencer-studio'));
        }
        
        $output = $result['output'];
        
        // Output could be a URL string or array of URLs
        if (is_string($output)) {
            return $output;
        }
        
        if (is_array($output) && !empty($output)) {
            return $output[0];
        }
        
        return new WP_Error('invalid_output', __('Invalid output format from image generator.', 'ai-influencer-studio'));
    }
}
