<?php
/**
 * Admin Settings Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_GPT_Chatbot_Admin_Settings {
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('WP GPT Chatbot Settings', 'wp-gpt-chatbot'),
            __('GPT Chatbot', 'wp-gpt-chatbot'),
            'manage_options',
            'wp-gpt-chatbot',
            array($this, 'display_settings_page'),
            'dashicons-format-chat',
            85
        );
    }
    
    public function register_settings() {
        register_setting('wp_gpt_chatbot_options', 'wp_gpt_chatbot_settings', array($this, 'validate_settings'));
    }
    
    public function validate_settings($input) {
        global $wpdb;
        
        // Get current settings to preserve training_data - bypass cache by getting directly from database
        $option_name = 'wp_gpt_chatbot_settings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        $current_settings = $row ? maybe_unserialize($row->option_value) : array();
        
        if (!is_array($current_settings)) {
            $current_settings = array();
        }
        
        // Sanitize each setting
        $output = array();
        $output['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $output['model'] = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-3.5-turbo';
        $output['training_prompt'] = isset($input['training_prompt']) ? sanitize_textarea_field($input['training_prompt']) : '';
        $output['unknown_response'] = isset($input['unknown_response']) ? sanitize_textarea_field($input['unknown_response']) : '';
        $output['primary_color'] = isset($input['primary_color']) ? sanitize_hex_color($input['primary_color']) : '#007bff';
        $output['secondary_color'] = isset($input['secondary_color']) ? sanitize_hex_color($input['secondary_color']) : '#ffffff';
        $output['bot_name'] = isset($input['bot_name']) ? sanitize_text_field($input['bot_name']) : '';
        $output['position'] = isset($input['position']) ? sanitize_text_field($input['position']) : 'bottom-right';
        $output['welcome_message'] = isset($input['welcome_message']) ? sanitize_text_field($input['welcome_message']) : '';
        
        // Merge new training_data from settings form (if present) with latest from DB
        $db_training_data = (isset($current_settings['training_data']) && is_array($current_settings['training_data']))
            ? $current_settings['training_data'] : array();
        $input_training_data = (isset($input['training_data']) && is_array($input['training_data']))
            ? $input['training_data'] : array();

        // Merge: DB data comes first, then any new/updated entries from form (avoid duplicates)
        $merged_training_data = $db_training_data;
        foreach ($input_training_data as $item) {
            // Only add if not already present (by question/answer)
            $exists = false;
            foreach ($db_training_data as $existing) {
                if (
                    trim($existing['question']) === trim($item['question']) &&
                    trim($existing['answer']) === trim($item['answer'])
                ) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists && !empty($item['question']) && !empty($item['answer'])) {
                $merged_training_data[] = $item;
            }
        }
        // Debug output
        error_log('GPT Chatbot validate_settings - merged training_data: ' . print_r($merged_training_data, true));
        $output['training_data'] = $merged_training_data;
        
        return $output;
    }
    
    public function display_settings_page() {
        include WP_GPT_CHATBOT_PATH . 'includes/admin/views/admin-page.php';
    }
}
