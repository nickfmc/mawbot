<?php
/**
 * Content Crawler Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_GPT_Chatbot_Content_Crawler {
    
    /**
     * Initialize the content crawler
     */
    public function init() {
        // Register ajax action for manually refreshing content
        add_action('wp_ajax_wp_gpt_chatbot_refresh_content', array($this, 'ajax_refresh_content'));
        
        // Register cron event
        add_action('wp_gpt_chatbot_content_refresh', array($this, 'refresh_content'));
        
        // Add settings for cron refresh
        add_filter('wp_gpt_chatbot_validate_settings', array($this, 'add_cron_schedule_on_save'), 10, 2);
    }
    
    /**
     * Schedule or unschedule cron event based on settings
     * 
     * @param array $output The output settings
     * @param array $input The input settings
     * @return array The filtered settings
     */
    public function add_cron_schedule_on_save($output, $input) {
        $content_enabled = false;
        $auto_refresh_enabled = false;
        $refresh_frequency = 'daily';
        
        if (isset($input['website_content']) && is_array($input['website_content'])) {
            $content_enabled = isset($input['website_content']['enabled']) && $input['website_content']['enabled'];
            $auto_refresh_enabled = isset($input['website_content']['auto_refresh']) && $input['website_content']['auto_refresh'];
            
            if (isset($input['website_content']['refresh_frequency'])) {
                $refresh_frequency = sanitize_text_field($input['website_content']['refresh_frequency']);
            }
        }
        
        // Store auto-refresh settings
        $output['website_content']['auto_refresh'] = $auto_refresh_enabled;
        $output['website_content']['refresh_frequency'] = $refresh_frequency;
        
        // Schedule or unschedule the cron event
        $event = wp_next_scheduled('wp_gpt_chatbot_content_refresh');
        
        if ($content_enabled && $auto_refresh_enabled) {
            // Schedule the event if it doesn't exist
            if (!$event) {
                wp_schedule_event(time(), $refresh_frequency, 'wp_gpt_chatbot_content_refresh');
            } else {
                // Check if frequency has changed
                $current_frequency = wp_get_schedule('wp_gpt_chatbot_content_refresh');
                if ($current_frequency !== $refresh_frequency) {
                    wp_clear_scheduled_hook('wp_gpt_chatbot_content_refresh');
                    wp_schedule_event(time(), $refresh_frequency, 'wp_gpt_chatbot_content_refresh');
                }
            }
        } else {
            // Unschedule the event if it exists
            if ($event) {
                wp_clear_scheduled_hook('wp_gpt_chatbot_content_refresh');
            }
        }
        
        return $output;
    }
    
    /**
     * AJAX handler for refreshing website content
     */
    public function ajax_refresh_content() {
        check_ajax_referer('wp_gpt_chatbot_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $result = $this->refresh_content();
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'count' => $result['count']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
        
        wp_die();
    }
    
    /**
     * Process and refresh website content
     * 
     * @return array Result of the operation
     */
    
    public function refresh_content() {
        error_log('[MAWBOT] refresh_content() called');
        $settings = get_option('wp_gpt_chatbot_settings');
    
        // Check if website content settings exist
        if (!isset($settings['website_content']) || !is_array($settings['website_content'])) {
            return array(
                'success' => false,
                'message' => 'No website content settings found',
                'count' => 0
            );
        }
    
        $content_settings = $settings['website_content'];
    
        // Initialize training data array
        $training_data = array();
        $processed_count = 0;
    
        // Get excluded pages
        $excluded_pages = isset($content_settings['excluded_pages']) && is_array($content_settings['excluded_pages']) 
            ? $content_settings['excluded_pages'] 
            : array();
    
        // Always process manual pages
        $manual_pages = isset($content_settings['manual_pages']) && is_array($content_settings['manual_pages'])
            ? $content_settings['manual_pages']
            : array();
        error_log('[MAWBOT] Manual pages to process: ' . json_encode($manual_pages));
        foreach ($manual_pages as $manual_id) {
            error_log("[MAWBOT] Checking manual page $manual_id");
            if (in_array($manual_id, $excluded_pages)) {
                error_log("[MAWBOT] Manual page $manual_id skipped: in excluded_pages");
                continue;
            }
            // Avoid duplicate processing if already included
            $already_processed = false;
            foreach ($training_data as $entry) {
                if (isset($entry['source_id']) && $entry['source_id'] == $manual_id) {
                    $already_processed = true;
                    break;
                }
            }
            if ($already_processed) {
                error_log("[MAWBOT] Manual page $manual_id skipped: already processed");
                continue;
            }
            $post = get_post($manual_id);
            if (!$post) {
                error_log("[MAWBOT] Manual page $manual_id skipped: post not found");
                continue;
            }
            if ($post->post_status !== 'publish') {
                error_log("[MAWBOT] Manual page $manual_id skipped: not published (status: {$post->post_status})");
                continue;
            }
            $content = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
            if (strlen($content) < 50) {
                error_log("[MAWBOT] Manual page $manual_id skipped: content too short (" . strlen($content) . " chars)");
                continue;
            }
            error_log("[MAWBOT] Manual page $manual_id INCLUDED: '{$post->post_title}' (" . strlen($content) . " chars)");
    
            $title = $post->post_title;
            $url = get_permalink($post->ID);
            $post_type_obj = get_post_type_object($post->post_type);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
            $source_info = "Source: {$title} ({$post_type_name}) | URL: {$url}";
            $chunks = $this->split_content_into_chunks($content);
            foreach ($chunks as $index => $chunk) {
                $chunk_suffix = count($chunks) > 1 ? " (Part " . ($index + 1) . ")" : "";
                $training_data[] = array(
                    'question' => "What does the {$post_type_name} '{$title}'{$chunk_suffix} say?",
                    'answer' => $chunk . "\n\n" . $source_info,
                    'added_at' => current_time('mysql'),
                    'source_type' => 'website_content',
                    'source_id' => $post->ID,
                    'source_url' => $url
                );
                $training_data[] = array(
                    'question' => "What information is on {$url}{$chunk_suffix}?",
                    'answer' => $chunk . "\n\n" . $source_info,
                    'added_at' => current_time('mysql'),
                    'source_type' => 'website_content',
                    'source_id' => $post->ID,
                    'source_url' => $url
                );
                $training_data[] = array(
                    'question' => "Tell me about {$title}{$chunk_suffix}",
                    'answer' => $chunk . "\n\n" . $source_info,
                    'added_at' => current_time('mysql'),
                    'source_type' => 'website_content',
                    'source_id' => $post->ID,
                    'source_url' => $url
                );
            }
            $processed_count++;
        }
    
        // Now check if content crawling is enabled for main crawl
        $enabled = isset($content_settings['enabled']) ? (bool) $content_settings['enabled'] : false;
    
        if ($enabled) {
            // Get selected post types
            $post_types = isset($content_settings['post_types']) && is_array($content_settings['post_types']) 
                ? $content_settings['post_types'] 
                : array('page');
    
            // Get selected categories
            $categories = isset($content_settings['categories']) && is_array($content_settings['categories']) 
                ? $content_settings['categories'] 
                : array();
    
            // Get selected tags
            $tags = isset($content_settings['tags']) && is_array($content_settings['tags']) 
                ? $content_settings['tags'] 
                : array();
    
            // Process each post type separately to handle different taxonomies correctly
            foreach ($post_types as $post_type) {
                // Build query args for this post type
                $args = array(
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'posts_per_page' => -1, // Get all posts
                );
    
                // Add category filter for post type that supports categories
                if (!empty($categories) && $post_type === 'post') {
                    $args['category__in'] = $categories;
                }
    
                // Add tag filter for post type that supports tags
                if (!empty($tags) && $post_type === 'post') {
                    $args['tag__in'] = $tags;
                }
    
                // Add exclude filter if specified
                if (!empty($excluded_pages)) {
                    $args['post__not_in'] = $excluded_pages;
                }
    
                // Apply filters for custom taxonomies
                $args = apply_filters('wp_gpt_chatbot_content_query_args', $args, $post_type, $content_settings);
    
                // Get posts for this post type
                $query = new WP_Query($args);
                $posts = $query->posts;
    
                foreach ($posts as $post) {
                    // Skip if this page is excluded
                    if (in_array($post->ID, $excluded_pages)) {
                        continue;
                    }
    
                    // Get post content and clean it
                    $content = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
                    $title = $post->post_title;
                    $url = get_permalink($post->ID);
                    $post_type_obj = get_post_type_object($post_type);
                    $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type;
    
                    // Skip if content is too short
                    if (strlen($content) < 50) {
                        continue;
                    }
    
                    // Format the source information
                    $source_info = "Source: {$title} ({$post_type_name}) | URL: {$url}";
    
                    // Try to break content into logical chunks to avoid exceeding context limits
                    $chunks = $this->split_content_into_chunks($content);
    
                    foreach ($chunks as $index => $chunk) {
                        $chunk_suffix = count($chunks) > 1 ? " (Part " . ($index + 1) . ")" : "";
    
                        // Create training data entries for each chunk
                        $training_data[] = array(
                            'question' => "What does the {$post_type_name} '{$title}'{$chunk_suffix} say?",
                            'answer' => $chunk . "\n\n" . $source_info,
                            'added_at' => current_time('mysql'),
                            'source_type' => 'website_content',
                            'source_id' => $post->ID,
                            'source_url' => $url
                        );
    
                        // Also add a variant with URL
                        $training_data[] = array(
                            'question' => "What information is on {$url}{$chunk_suffix}?",
                            'answer' => $chunk . "\n\n" . $source_info,
                            'added_at' => current_time('mysql'),
                            'source_type' => 'website_content',
                            'source_id' => $post->ID,
                            'source_url' => $url
                        );
    
                        // Add questions based on page title
                        $training_data[] = array(
                            'question' => "Tell me about {$title}{$chunk_suffix}",
                            'answer' => $chunk . "\n\n" . $source_info,
                            'added_at' => current_time('mysql'),
                            'source_type' => 'website_content',
                            'source_id' => $post->ID,
                            'source_url' => $url
                        );
                    }
    
                    $processed_count++;
                }
            }
        }
    
        // Now check if we have any training data
        if (empty($training_data)) {
            return array(
                'success' => false,
                'message' => 'No suitable content found for training data',
                'count' => 0
            );
        }
    
        // Update settings with new website content training data
        $this->update_website_training_data($training_data);
    
        // At the end of refresh_content(), after updating training data
        error_log('[MAWBOT] SAVED TRAINING DATA: ' . print_r(get_option('wp_gpt_chatbot_settings'), true));
    
        return array(
            'success' => true,
            'message' => "Successfully processed {$processed_count} pages/posts and created " . count($training_data) . " training entries",
            'count' => count($training_data)
        );
    }
    /**
     * Split content into manageable chunks
     * 
     * @param string $content The content to split
     * @return array Array of content chunks
     */
    private function split_content_into_chunks($content) {
        // Target chunk size (~1000 tokens, approximately 750 words)
        $target_size = 4000;
        
        // If content is small enough, return as single chunk
        if (strlen($content) <= $target_size) {
            return array($content);
        }
        
        $chunks = array();
        
        // Split by paragraphs
        $paragraphs = preg_split('/\r\n|\r|\n/', $content);
        $current_chunk = '';
        
        foreach ($paragraphs as $paragraph) {
            // Skip empty paragraphs
            if (empty(trim($paragraph))) {
                continue;
            }
            
            // If adding this paragraph would exceed target size, 
            // start a new chunk (unless current chunk is empty)
            if (strlen($current_chunk) + strlen($paragraph) > $target_size && !empty($current_chunk)) {
                $chunks[] = $current_chunk;
                $current_chunk = '';
            }
            
            // Add paragraph to current chunk
            if (!empty($current_chunk)) {
                $current_chunk .= "\n\n";
            }
            $current_chunk .= $paragraph;
        }
        
        // Add the last chunk if not empty
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }
    
    /**
     * Update training data from website content
     * 
     * @param array $new_training_data New training data from website content
     */
    private function update_website_training_data($new_training_data) {
        global $wpdb;
        
        // Get current settings directly from database to avoid caching issues
        $option_name = 'wp_gpt_chatbot_settings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        $settings = $row ? maybe_unserialize($row->option_value) : array();
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        if (!isset($settings['training_data']) || !is_array($settings['training_data'])) {
            $settings['training_data'] = array();
        }
        
        // Remove old website content entries
        $filtered_training_data = array();
        foreach ($settings['training_data'] as $item) {
            if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                $filtered_training_data[] = $item;
            }
        }
        
        // Add new website content entries
        $settings['training_data'] = array_merge($filtered_training_data, $new_training_data);
        
        // Save updated settings
        update_option($option_name, $settings);
    }
    
    /**
     * Get all available post types for content crawling
     * 
     * @return array Array of post type objects
     */
    public static function get_available_post_types() {
        $args = array(
            'public' => true,
        );
        
        $post_types = get_post_types($args, 'objects');
        
        // Filter out some built-in post types that don't make sense for the chatbot
        $excluded_types = array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache');
        
        foreach ($excluded_types as $excluded_type) {
            if (isset($post_types[$excluded_type])) {
                unset($post_types[$excluded_type]);
            }
        }
        
        return $post_types;
    }
    
    /**
     * Get all available categories for content crawling
     * 
     * @return array Array of category objects
     */
    public static function get_available_categories() {
        $args = array(
            'hide_empty' => false,
        );
        
        return get_categories($args);
    }
    
    /**
     * Get all available tags for content crawling
     * 
     * @return array Array of tag objects
     */
    public static function get_available_tags() {
        $args = array(
            'hide_empty' => false,
        );
        
        return get_tags($args);
    }
    
    /**
     * Get all available taxonomies for a post type
     * 
     * @param string $post_type The post type to get taxonomies for
     * @return array Array of taxonomy objects
     */
    public static function get_taxonomies_for_post_type($post_type) {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        
        // Filter out some internal taxonomies
        $excluded_taxonomies = array('post_format', 'post_tag', 'category'); // These are handled separately
        
        foreach ($excluded_taxonomies as $excluded_taxonomy) {
            if (isset($taxonomies[$excluded_taxonomy])) {
                unset($taxonomies[$excluded_taxonomy]);
            }
        }
        
        return $taxonomies;
    }
}
