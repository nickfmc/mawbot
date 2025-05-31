<?php
/**
 * Plugin Name: WP GPT Chatbot
 * Plugin URI: https://example.com/wp-gpt-chatbot
 * Description: A WordPress plugin that creates a custom chatbot using the ChatGPT API with your own training material.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: wp-gpt-chatbot
 * License: GPL-2.0+
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WP_GPT_CHATBOT_VERSION', '1.0.0');
define('WP_GPT_CHATBOT_PATH', plugin_dir_path(__FILE__));
define('WP_GPT_CHATBOT_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once WP_GPT_CHATBOT_PATH . 'includes/class-database-manager.php';
require_once WP_GPT_CHATBOT_PATH . 'includes/class-content-crawler.php';
require_once WP_GPT_CHATBOT_PATH . 'includes/admin/class-admin-settings.php';
require_once WP_GPT_CHATBOT_PATH . 'includes/api/class-chatgpt-api.php';
require_once WP_GPT_CHATBOT_PATH . 'includes/frontend/class-chatbot-widget.php';

// Activation hook
register_activation_hook(__FILE__, 'wp_gpt_chatbot_activate');
function wp_gpt_chatbot_activate() {
    // Create default settings if they don't exist
    if (!get_option('wp_gpt_chatbot_settings')) {
        $default_settings = array(
            'api_key' => '',
            'model' => 'gpt-4.1-nano',
            'training_prompt' => 'CRITICAL INSTRUCTIONS: You are a chatbot that can ONLY answer questions using the specific training data provided below. You are FORBIDDEN from using any general AI knowledge, including information about famous people, technology, or any other topics not explicitly provided in your training data. 

STRICT RULES:
1. ONLY use information from the Q&A pairs provided in your training data
2. If a question is not directly answered in your training data, you MUST respond with: "I don\'t have that specific information in my knowledge base. Please contact us directly for assistance."
3. Do NOT provide general knowledge answers about famous people, historical figures, or any other topics
4. Do NOT make up or infer answers that aren\'t explicitly in your training data
5. Stay strictly within the boundaries of your provided training material

You must follow these rules without exception.',
            'unknown_response' => 'I don\'t have that specific information in my knowledge base. I can only answer questions based on the training data I\'ve been provided. Please contact us directly for assistance with questions outside my scope.',
            'primary_color' => '#007bff',
            'secondary_color' => '#ffffff',
            'bot_name' => 'GPT Assistant',
            'position' => 'bottom-right',
            'welcome_message' => 'Hello! How can I help you today?',
            'training_data' => array(),
            'website_content' => array(
                'enabled' => false,
                'auto_refresh' => false,
                'refresh_frequency' => 'daily',
                'post_types' => array('page'),
                'categories' => array(),
                'tags' => array(),
                'excluded_pages' => array()
            ),
            // Token optimization settings
            'enable_caching' => true,
            'cache_expiration' => 604800, // 1 week in seconds
            'conversation_memory' => 5,
            'selective_context' => true
        );
        update_option('wp_gpt_chatbot_settings', $default_settings);
    }
    
    // Create database tables
    WP_GPT_Chatbot_Database_Manager::create_tables();
    
    // Schedule content refresh if enabled
    $settings = get_option('wp_gpt_chatbot_settings');
    if (isset($settings['website_content']) && 
        isset($settings['website_content']['enabled']) && 
        $settings['website_content']['enabled'] &&
        isset($settings['website_content']['auto_refresh']) && 
        $settings['website_content']['auto_refresh']) {
        
        $frequency = isset($settings['website_content']['refresh_frequency']) 
            ? $settings['website_content']['refresh_frequency'] 
            : 'daily';
            
        if (!wp_next_scheduled('wp_gpt_chatbot_content_refresh')) {
            wp_schedule_event(time(), $frequency, 'wp_gpt_chatbot_content_refresh');
        }
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wp_gpt_chatbot_deactivate');
function wp_gpt_chatbot_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('wp_gpt_chatbot_content_refresh');
}

// Initialize the plugin
function wp_gpt_chatbot_init() {
    // Initialize admin settings
    $admin_settings = new WP_GPT_Chatbot_Admin_Settings();
    $admin_settings->init();
    
    // Initialize content crawler
    $content_crawler = new WP_GPT_Chatbot_Content_Crawler();
    $content_crawler->init();
    
    // Initialize ChatGPT API
    $chatgpt_api = new WP_GPT_Chatbot_API();
    
    // Initialize frontend widget
    $chatbot_widget = new WP_GPT_Chatbot_Widget($chatgpt_api);
    $chatbot_widget->init();
    
    // Register the Unknown Questions admin page
    add_action('admin_menu', 'wp_gpt_chatbot_add_questions_page');
}
add_action('plugins_loaded', 'wp_gpt_chatbot_init');

/**
 * Add the Unknown Questions admin page
 */
function wp_gpt_chatbot_add_questions_page() {
    add_submenu_page(
        'wp-gpt-chatbot',
        __('Unknown Questions', 'wp-gpt-chatbot'),
        __('Unknown Questions', 'wp-gpt-chatbot'),
        'manage_options',
        'wp-gpt-chatbot-questions',
        'wp_gpt_chatbot_display_questions_page'
    );
}

/**
 * Display the Unknown Questions admin page
 */
function wp_gpt_chatbot_display_questions_page() {
    include WP_GPT_CHATBOT_PATH . 'includes/admin/views/unknown-questions.php';
}

// Enqueue frontend scripts and styles
function wp_gpt_chatbot_enqueue_scripts() {
    wp_enqueue_style('wp-gpt-chatbot-css', WP_GPT_CHATBOT_URL . 'assets/css/chatbot.css', array(), WP_GPT_CHATBOT_VERSION);
    wp_enqueue_script('wp-gpt-chatbot-js', WP_GPT_CHATBOT_URL . 'assets/js/chatbot.js', array('jquery'), WP_GPT_CHATBOT_VERSION, true);
    
    // Pass settings to JS
    $settings = get_option('wp_gpt_chatbot_settings');
    wp_localize_script('wp-gpt-chatbot-js', 'wpGptChatbotSettings', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_gpt_chatbot_nonce'),
        'botName' => $settings['bot_name'],
        'welcomeMessage' => $settings['welcome_message'],
        'primaryColor' => $settings['primary_color'],
        'secondaryColor' => $settings['secondary_color'],
        'position' => $settings['position']
    ));
}
add_action('wp_enqueue_scripts', 'wp_gpt_chatbot_enqueue_scripts');

// Register shortcode for inline chatbot
add_shortcode('wp_gpt_chatbot', 'wp_gpt_chatbot_shortcode');

/**
 * Shortcode function to display the chatbot inline on a page
 *
 * @param array $atts Shortcode attributes
 * @return string HTML output of the shortcode
 */
function wp_gpt_chatbot_shortcode($atts) {
    // Get plugin settings
    $settings = get_option('wp_gpt_chatbot_settings');
    
    // Don't render if API key is not set
    if (empty($settings['api_key'])) {
        return '<p>' . __('ChatGPT API key not configured.', 'wp-gpt-chatbot') . '</p>';
    }
    
    // Parse attributes, but always default to settings welcome_message if not set or empty
    $atts = shortcode_atts(array(
        'height' => '400px',
        'welcome_message' => '',
    ), $atts, 'wp_gpt_chatbot');
    if (empty($atts['welcome_message'])) {
        $atts['welcome_message'] = isset($settings['welcome_message']) ? $settings['welcome_message'] : '';
    }
    
    // Start output buffering
    ob_start();
    
    // Generate a unique ID for this instance
    $unique_id = 'wp-gpt-chatbot-inline-' . uniqid();
    ?>
    <div id="<?php echo esc_attr($unique_id); ?>" class="wp-gpt-chatbot-inline" style="height: <?php echo esc_attr($atts['height']); ?>">
        <div class="wp-gpt-chatbot-inline-messages">
            <div class="wp-gpt-chatbot-message bot">
                <div class="wp-gpt-chatbot-message-content"><?php echo esc_html($atts['welcome_message']); ?></div>
            </div>
        </div>
        <div class="wp-gpt-chatbot-inline-input-container">
            <textarea class="wp-gpt-chatbot-input" placeholder="<?php echo esc_attr__('Type your message...', 'wp-gpt-chatbot'); ?>"></textarea>
            <button class="wp-gpt-chatbot-send" style="background-color: <?php echo esc_attr($settings['primary_color']); ?>; color: <?php echo esc_attr($settings['secondary_color']); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
    </div>
    <?php
    
    // Get the buffer content and clean the buffer
    $output = ob_get_clean();
    
    return $output;
}
