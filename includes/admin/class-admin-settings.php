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
    private $active_tab = 'general';
    
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add AJAX handler for page search
        add_action('wp_ajax_wp_gpt_chatbot_search_pages', array($this, 'ajax_search_pages'));
        
        // Add AJAX handler for clearing cache
        add_action('wp_ajax_wp_gpt_chatbot_clear_cache', array($this, 'ajax_clear_cache'));
        
        // Set active tab
        if (isset($_GET['tab'])) {
            $this->active_tab = sanitize_text_field($_GET['tab']);
        }
    }
    
    /**
     * AJAX handler for clearing cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('wp_gpt_chatbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        require_once WP_GPT_CHATBOT_PATH . 'includes/class-cache-manager.php';
        $result = WP_GPT_Chatbot_Cache_Manager::clear_cache();
        
        if ($result) {
            wp_send_json_success(array('message' => 'Cache cleared successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Error clearing cache.'));
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for searching pages
     */
    public function ajax_search_pages() {
        check_ajax_referer('wp_gpt_chatbot_search_nonce', 'nonce');
        
        $query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';
        
        if (empty($query)) {
            wp_send_json_error();
            return;
        }
        
        $args = array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $query,
        );
        
        $search_results = get_posts($args);
        $results = array();
        
        foreach ($search_results as $post) {
            $results[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
            );
        }
        
        wp_send_json_success($results);
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
        
        // Add Website Content submenu
        add_submenu_page(
            'wp-gpt-chatbot',
            __('Website Content', 'wp-gpt-chatbot'),
            __('Website Content', 'wp-gpt-chatbot'),
            'manage_options',
            'admin.php?page=wp-gpt-chatbot&tab=website-content',
            null
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
        $output['model'] = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4.1-nano';
        $output['training_prompt'] = isset($input['training_prompt']) ? sanitize_textarea_field($input['training_prompt']) : '';
        $output['unknown_response'] = isset($input['unknown_response']) ? sanitize_textarea_field($input['unknown_response']) : '';
        $output['primary_color'] = isset($input['primary_color']) ? sanitize_hex_color($input['primary_color']) : '#007bff';
        $output['secondary_color'] = isset($input['secondary_color']) ? sanitize_hex_color($input['secondary_color']) : '#ffffff';
        $output['bot_name'] = isset($input['bot_name']) ? sanitize_text_field($input['bot_name']) : '';
        $output['position'] = isset($input['position']) ? sanitize_text_field($input['position']) : 'bottom-right';
        if (!in_array($output['position'], array('bottom-right', 'bottom-left', 'none'))) {
            $output['position'] = 'bottom-right';
        }
        $output['welcome_message'] = isset($input['welcome_message']) ? sanitize_text_field($input['welcome_message']) : '';
        
        // Process token optimization settings
        $output['enable_caching'] = isset($input['enable_caching']) ? (bool) $input['enable_caching'] : false;
        $output['cache_expiration'] = isset($input['cache_expiration']) ? absint($input['cache_expiration']) : 604800; // Default: 1 week
        $output['conversation_memory'] = isset($input['conversation_memory']) ? 
            max(1, min(20, absint($input['conversation_memory']))) : 5; // Between 1-20, default 5
        $output['selective_context'] = isset($input['selective_context']) ? (bool) $input['selective_context'] : true;
        
        // Process website content settings
        if (isset($input['website_content']) && is_array($input['website_content'])) {
            $output['website_content'] = array();
            $output['website_content']['enabled'] = isset($input['website_content']['enabled']) ? (bool) $input['website_content']['enabled'] : false;
            
            // Post types
            if (isset($input['website_content']['post_types']) && is_array($input['website_content']['post_types'])) {
                $output['website_content']['post_types'] = array_map('sanitize_text_field', $input['website_content']['post_types']);
            } else {
                $output['website_content']['post_types'] = array('page');
            }
            
            // Categories
            if (isset($input['website_content']['categories']) && is_array($input['website_content']['categories'])) {
                $output['website_content']['categories'] = array_map('intval', $input['website_content']['categories']);
            } else {
                $output['website_content']['categories'] = array();
            }
            
            // Tags
            if (isset($input['website_content']['tags']) && is_array($input['website_content']['tags'])) {
                $output['website_content']['tags'] = array_map('intval', $input['website_content']['tags']);
            } else {
                $output['website_content']['tags'] = array();
            }
            
            // Excluded pages
            if (isset($input['website_content']['excluded_pages']) && is_array($input['website_content']['excluded_pages'])) {
                $output['website_content']['excluded_pages'] = array_map('intval', $input['website_content']['excluded_pages']);
            } else {
                $output['website_content']['excluded_pages'] = array();
            }
        } else {
            // Preserve existing website content settings if present
            $output['website_content'] = isset($current_settings['website_content']) ? $current_settings['website_content'] : array();
        }
        
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
        
        // Apply filters for extensions
        $output = apply_filters('wp_gpt_chatbot_validate_settings', $output, $input);
        
        return $output;
    }
    
    public function display_settings_page() {
        // Display tab navigation
        $this->display_tabs();
        
        // Display the appropriate tab content
        switch ($this->active_tab) {
            case 'website-content':
                include WP_GPT_CHATBOT_PATH . 'includes/admin/views/website-content.php';
                break;
            
            default:
                include WP_GPT_CHATBOT_PATH . 'includes/admin/views/admin-page.php';
                break;
        }
    }
    
    /**
     * Display tabs for settings page
     */
    private function display_tabs() {
        ?>
        <h2 class="nav-tab-wrapper">
            <a href="?page=wp-gpt-chatbot&tab=general" class="nav-tab <?php echo $this->active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html__('General Settings', 'wp-gpt-chatbot'); ?>
            </a>
            <a href="?page=wp-gpt-chatbot&tab=website-content" class="nav-tab <?php echo $this->active_tab === 'website-content' ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html__('Website Content', 'wp-gpt-chatbot'); ?>
            </a>
        </h2>
        <?php
    }
}
