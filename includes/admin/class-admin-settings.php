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
        
        // Add AJAX handlers for question logs
        add_action('wp_ajax_wp_gpt_chatbot_download_logs', array($this, 'ajax_download_logs'));
        add_action('wp_ajax_wp_gpt_chatbot_clear_logs', array($this, 'ajax_clear_logs'));
        
        // Add AJAX handlers for media coverage
        add_action('wp_ajax_wp_gpt_chatbot_upload_media_coverage', array($this, 'ajax_upload_media_coverage'));
        add_action('wp_ajax_wp_gpt_chatbot_save_media_coverage', array($this, 'ajax_save_media_coverage'));
        add_action('wp_ajax_wp_gpt_chatbot_clear_media_coverage', array($this, 'ajax_clear_media_coverage'));
        
        // Add AJAX handler for exporting training data
        add_action('wp_ajax_wp_gpt_chatbot_export_training', array($this, 'ajax_export_training'));
        
        // Ensure question logs table exists
        add_action('admin_init', array($this, 'ensure_question_logs_table'));
        
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
     * AJAX handler for downloading question logs
     */
    public function ajax_download_logs() {
        check_ajax_referer('wp_gpt_chatbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        require_once WP_GPT_CHATBOT_PATH . 'includes/class-database-manager.php';
        
        // Get all question logs
        $logs = WP_GPT_Chatbot_Database_Manager::get_question_logs(10000); // Get up to 10k records
        
        if (empty($logs)) {
            wp_die('No question logs found.');
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="chatbot-question-logs-' . date('Y-m-d-H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Write CSV header
        fputcsv($output, array(
            'ID',
            'Question',
            'Response',
            'User IP',
            'User Agent',
            'Asked At',
            'Response Time (seconds)',
            'Was Cached'
        ));
        
        // Write data rows
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log->id,
                $log->question,
                $log->response,
                $log->user_ip,
                $log->user_agent,
                $log->asked_at,
                $log->response_time,
                $log->was_cached ? 'Yes' : 'No'
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * AJAX handler for clearing question logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('wp_gpt_chatbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        require_once WP_GPT_CHATBOT_PATH . 'includes/class-database-manager.php';
        $result = WP_GPT_Chatbot_Database_Manager::clear_question_logs();
        
        if ($result) {
            wp_send_json_success(array('message' => 'Question logs cleared successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Error clearing question logs.'));
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
        // Get current settings to preserve all sections
        $option_name = 'wp_gpt_chatbot_settings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        $current_settings = $row ? maybe_unserialize($row->option_value) : array();
        if (!is_array($current_settings)) {
            $current_settings = array();
        }

        // Start with all current settings, then overwrite with new input
        $output = $current_settings;

        // Overwrite/merge each known section if present in input
        if (isset($input['api_key'])) $output['api_key'] = sanitize_text_field($input['api_key']);
        if (isset($input['model'])) $output['model'] = sanitize_text_field($input['model']);
        if (isset($input['training_prompt'])) $output['training_prompt'] = sanitize_textarea_field($input['training_prompt']);
        if (isset($input['unknown_response'])) $output['unknown_response'] = sanitize_textarea_field($input['unknown_response']);
        if (isset($input['primary_color'])) $output['primary_color'] = sanitize_hex_color($input['primary_color']);
        if (isset($input['secondary_color'])) $output['secondary_color'] = sanitize_hex_color($input['secondary_color']);
        if (isset($input['bot_name'])) $output['bot_name'] = sanitize_text_field($input['bot_name']);
        if (isset($input['position'])) {
            $output['position'] = sanitize_text_field($input['position']);
            if (!in_array($output['position'], array('bottom-right', 'bottom-left', 'none'))) {
                $output['position'] = 'bottom-right';
            }
        }
        if (isset($input['welcome_message'])) $output['welcome_message'] = sanitize_text_field($input['welcome_message']);
        if (isset($input['enable_caching'])) $output['enable_caching'] = (bool) $input['enable_caching'];
        if (isset($input['cache_expiration'])) $output['cache_expiration'] = absint($input['cache_expiration']);
        if (isset($input['conversation_memory'])) $output['conversation_memory'] = max(1, min(20, absint($input['conversation_memory'])));
        if (isset($input['selective_context'])) $output['selective_context'] = (bool) $input['selective_context'];
        if (isset($input['show_related_content'])) $output['show_related_content'] = (bool) $input['show_related_content'];
        else $output['show_related_content'] = false; // Explicitly set to false when checkbox is unchecked
        if (isset($input['enable_question_logging'])) $output['enable_question_logging'] = (bool) $input['enable_question_logging'];
        else $output['enable_question_logging'] = false; // Explicitly set to false when checkbox is unchecked

        // Website content section
        if (isset($input['website_content']) && is_array($input['website_content'])) {
            $output['website_content'] = isset($output['website_content']) && is_array($output['website_content']) ? $output['website_content'] : array();
            $output['website_content']['enabled'] = isset($input['website_content']['enabled']) ? (bool) $input['website_content']['enabled'] : false;
            $output['website_content']['auto_refresh'] = isset($input['website_content']['auto_refresh']) ? (bool) $input['website_content']['auto_refresh'] : false;
            $output['website_content']['refresh_frequency'] = isset($input['website_content']['refresh_frequency']) ? sanitize_text_field($input['website_content']['refresh_frequency']) : 'daily';
            $output['website_content']['post_types'] = isset($input['website_content']['post_types']) && is_array($input['website_content']['post_types']) ? array_map('sanitize_text_field', $input['website_content']['post_types']) : array('page');
            $output['website_content']['categories'] = isset($input['website_content']['categories']) && is_array($input['website_content']['categories']) ? array_map('intval', $input['website_content']['categories']) : array();
            $output['website_content']['tags'] = isset($input['website_content']['tags']) && is_array($input['website_content']['tags']) ? array_map('intval', $input['website_content']['tags']) : array();
            $output['website_content']['excluded_pages'] = isset($input['website_content']['excluded_pages']) && is_array($input['website_content']['excluded_pages']) ? array_map('intval', $input['website_content']['excluded_pages']) : array();
            // Manually included pages
            $output['website_content']['manual_pages'] = isset($input['website_content']['manual_pages']) && is_array($input['website_content']['manual_pages']) ? array_map('intval', $input['website_content']['manual_pages']) : array();
        }

        // Merge new training_data from settings form (if present) with latest from DB
        $db_training_data = (isset($current_settings['training_data']) && is_array($current_settings['training_data']))
            ? $current_settings['training_data'] : array();
        $input_training_data = (isset($input['training_data']) && is_array($input['training_data']))
            ? $input['training_data'] : array();
        $merged_training_data = $db_training_data;
        foreach ($input_training_data as $item) {
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
        $output['training_data'] = $merged_training_data;

        if (isset($input['placeholder_suggestions'])) {
            $output['placeholder_suggestions'] = sanitize_text_field($input['placeholder_suggestions']);
        }

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
    
    /**
     * Ensure the question logs table exists (in case plugin was updated)
     */
    public function ensure_question_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // Create the table
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                question text NOT NULL,
                response text,
                user_ip varchar(45),
                user_agent text,
                asked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                response_time float,
                was_cached tinyint(1) DEFAULT 0,
                PRIMARY KEY  (id),
                KEY asked_at (asked_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * AJAX handler for uploading and previewing media coverage CSV
     */
    public function ajax_upload_media_coverage() {
        check_ajax_referer('wp_gpt_chatbot_media_coverage', 'media_coverage_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        if (!isset($_FILES['media_coverage_file']) || $_FILES['media_coverage_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Error uploading file');
            return;
        }
        
        $file = $_FILES['media_coverage_file'];
        
        // Validate file type
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            wp_send_json_error('Please upload a CSV file');
            return;
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error('Could not read CSV file');
            return;
        }
        
        $data = array();
        $headers = array();
        $row_count = 0;
        
        // Read CSV data
        while (($row = fgetcsv($handle)) !== false) {
            if ($row_count === 0) {
                // Store headers
                $headers = array_map('trim', $row);
                $row_count++;
                continue;
            }
            
            // Process data rows (limit to first 100 for preview)
            if ($row_count <= 100) {
                $item = array();
                foreach ($headers as $index => $header) {
                    $item[strtolower(str_replace(' ', '_', trim($header)))] = isset($row[$index]) ? trim($row[$index]) : '';
                }
                $data[] = $item;
            }
            $row_count++;
        }
        
        fclose($handle);
        
        if (empty($data)) {
            wp_send_json_error('No valid data found in CSV file');
            return;
        }
        
        // Generate preview HTML
        $preview_html = '<p>' . sprintf('Found %d entries in CSV file. Showing first %d for preview:', $row_count - 1, min(count($data), 10)) . '</p>';
        $preview_html .= '<table class="wp-list-table widefat fixed striped">';
        $preview_html .= '<thead><tr>';
        
        // Use expected columns or detected headers
        $display_headers = array('outlet', 'topic', 'date', 'url', 'notes');
        foreach ($display_headers as $header) {
            $preview_html .= '<th>' . ucfirst(str_replace('_', ' ', $header)) . '</th>';
        }
        $preview_html .= '</tr></thead><tbody>';
        
        foreach (array_slice($data, 0, 10) as $item) {
            $preview_html .= '<tr>';
            foreach ($display_headers as $header) {
                $value = isset($item[$header]) ? esc_html($item[$header]) : '-';
                if ($header === 'url' && !empty($item[$header])) {
                    $value = '<a href="' . esc_url($item[$header]) . '" target="_blank">View</a>';
                }
                $preview_html .= '<td>' . $value . '</td>';
            }
            $preview_html .= '</tr>';
        }
        
        $preview_html .= '</tbody></table>';
        
        // Read entire file again to get all data
        fseek($handle = fopen($file['tmp_name'], 'r'), 0);
        $all_data = array();
        $row_count = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            if ($row_count === 0) {
                $row_count++;
                continue;
            }
            
            $item = array();
            foreach ($headers as $index => $header) {
                $item[strtolower(str_replace(' ', '_', trim($header)))] = isset($row[$index]) ? trim($row[$index]) : '';
            }
            $all_data[] = $item;
            $row_count++;
        }
        
        fclose($handle);
        
        wp_send_json_success(array(
            'message' => sprintf('Successfully parsed %d entries from CSV file.', count($all_data)),
            'preview' => $preview_html,
            'data' => $all_data
        ));
    }
    
    /**
     * AJAX handler for saving media coverage data
     */
    public function ajax_save_media_coverage() {
        check_ajax_referer('wp_gpt_chatbot_media_coverage', 'media_coverage_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        if (!isset($_POST['data']) || !is_array($_POST['data'])) {
            wp_send_json_error('No data provided');
            return;
        }
        
        $data = array_map(function($item) {
            return array_map('sanitize_text_field', $item);
        }, $_POST['data']);
        
        // Get current settings
        $settings = get_option('wp_gpt_chatbot_settings', array());
        $settings['media_coverage'] = $data;
        
        $updated = update_option('wp_gpt_chatbot_settings', $settings);
        
        if ($updated) {
            wp_send_json_success(sprintf('Successfully saved %d media coverage entries.', count($data)));
        } else {
            wp_send_json_error('Error saving media coverage data');
        }
    }
    
    /**
     * AJAX handler for clearing media coverage data
     */
    public function ajax_clear_media_coverage() {
        check_ajax_referer('wp_gpt_chatbot_media_coverage', 'media_coverage_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        // Get current settings and clear media coverage
        $settings = get_option('wp_gpt_chatbot_settings', array());
        $settings['media_coverage'] = array();
        
        $updated = update_option('wp_gpt_chatbot_settings', $settings);
        
        if ($updated) {
            wp_send_json_success('Media coverage data cleared successfully.');
        } else {
            wp_send_json_error('Error clearing media coverage data');
        }
    }
    
    /**
     * AJAX handler for exporting training data
     */
    public function ajax_export_training() {
        check_ajax_referer('wp_gpt_chatbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        // Get current settings directly from database to bypass caching
        global $wpdb;
        $option_name = 'wp_gpt_chatbot_settings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        $settings = $row ? maybe_unserialize($row->option_value) : array();
        $training_data = isset($settings['training_data']) ? $settings['training_data'] : array();
        
        if (empty($training_data)) {
            wp_send_json_error('No training data found to export');
            return;
        }
        
        // Filter to only export manual and imported entries (exclude website content)
        $exportable_data = array();
        foreach ($training_data as $item) {
            if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                $exportable_data[] = $item;
            }
        }
        
        if (empty($exportable_data)) {
            wp_send_json_error('No manual or imported training data found to export');
            return;
        }
        
        // Generate CSV content
        $csv_content = "Question,Answer,Added Date,Source\n";
        
        foreach ($exportable_data as $item) {
            $question = isset($item['question']) ? str_replace('"', '""', $item['question']) : '';
            $answer = isset($item['answer']) ? str_replace('"', '""', $item['answer']) : '';
            $added_date = isset($item['added_at']) ? $item['added_at'] : '';
            $source = isset($item['source_type']) ? 
                ($item['source_type'] === 'import' ? 'CSV Import' : 'Manual Entry') : 
                'Manual Entry';
            
            $csv_content .= sprintf('"%s","%s","%s","%s"' . "\n", 
                $question, 
                $answer, 
                $added_date, 
                $source
            );
        }
        
        $filename = 'chatbot-training-data-' . date('Y-m-d-H-i-s') . '.csv';
        
        wp_send_json_success(array(
            'csv' => $csv_content,
            'filename' => $filename,
            'count' => count($exportable_data)
        ));
    }
}
