<?php
/**
 * Cache Manager Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_GPT_Chatbot_Cache_Manager {
    
    /**
     * Get cached response for a query
     * 
     * @param string $query The user's query
     * @return string|false Cached response or false if not found
     */
    public static function get_cached_response($query) {
        // Check if caching is enabled in settings
        $settings = get_option('wp_gpt_chatbot_settings');
        if (!isset($settings['enable_caching']) || !$settings['enable_caching']) {
            return false;
        }
        
        // Normalize the query (remove extra spaces, convert to lowercase)
        $normalized_query = self::normalize_query($query);
        
        // Generate cache key
        $cache_key = 'wp_gpt_chatbot_response_' . md5($normalized_query);
        
        // Try to get from cache
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            // Log cache hit
            self::log_cache_event('hit', $normalized_query);
            return $cached_data;
        }
        
        // Log cache miss
        self::log_cache_event('miss', $normalized_query);
        return false;
    }
    
    /**
     * Cache a response
     * 
     * @param string $query The user's query
     * @param string $response The response to cache
     * @param int $expiration Expiration time in seconds (default: 1 week)
     */
    public static function cache_response($query, $response, $expiration = 604800) {
        // Check if caching is enabled in settings
        $settings = get_option('wp_gpt_chatbot_settings');
        if (!isset($settings['enable_caching']) || !$settings['enable_caching']) {
            return;
        }

        // Use custom expiration from settings if available
        if (isset($settings['cache_expiration']) && !empty($settings['cache_expiration'])) {
            $expiration = intval($settings['cache_expiration']);
        }
        
        // Normalize the query
        $normalized_query = self::normalize_query($query);
        
        // Generate cache key
        $cache_key = 'wp_gpt_chatbot_response_' . md5($normalized_query);
        
        // Cache the response
        set_transient($cache_key, $response, $expiration);
        
        // Log cache store
        self::log_cache_event('store', $normalized_query);
    }
    
    /**
     * Normalize a query for consistent caching
     * 
     * @param string $query The query to normalize
     * @return string Normalized query
     */
    private static function normalize_query($query) {
        // Convert to lowercase
        $query = strtolower($query);
        
        // Remove extra whitespace
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        return $query;
    }
    
    /**
     * Log cache events for analysis
     * 
     * @param string $event The cache event (hit, miss, store)
     * @param string $query The normalized query
     */
    private static function log_cache_event($event, $query) {
        // Get the cache log
        $cache_log = get_option('wp_gpt_chatbot_cache_log', array());
        
        // Ensure the log doesn't grow too large (keep last 1000 entries)
        if (count($cache_log) > 1000) {
            $cache_log = array_slice($cache_log, -999, 999);
        }
        
        // Add new entry
        $cache_log[] = array(
            'event' => $event,
            'query' => $query,
            'time' => current_time('mysql')
        );
        
        // Update the log
        update_option('wp_gpt_chatbot_cache_log', $cache_log);
    }
    
    /**
     * Clear all cached responses
     * 
     * @return boolean Success status
     */
    public static function clear_cache() {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_gpt_chatbot_response_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_gpt_chatbot_response_%'");
        
        return true;
    }
}
