<?php
/**
 * Chatbot Frontend Widget Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_GPT_Chatbot_Widget {
    private $api;
    
    public function __construct($api) {
        $this->api = $api;
    }
    
    public function init() {
        add_action('wp_footer', array($this, 'render_chatbot'));
    }
    
    public function render_chatbot() {
        $settings = get_option('wp_gpt_chatbot_settings');
        
        // Don't render if API key is not set
        if (empty($settings['api_key'])) {
            return;
        }
        
        ?>
        <div id="wp-gpt-chatbot-container" class="wp-gpt-chatbot-<?php echo esc_attr($settings['position']); ?>">
            <div class="wp-gpt-chatbot-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            <div class="wp-gpt-chatbot-box" style="display: none;">
                <div class="wp-gpt-chatbot-header" style="background-color: <?php echo esc_attr($settings['primary_color']); ?>; color: <?php echo esc_attr($settings['secondary_color']); ?>;">
                    <div class="wp-gpt-chatbot-title"><?php echo esc_html($settings['bot_name']); ?></div>
                    <div class="wp-gpt-chatbot-close">×</div>
                </div>
                <div class="wp-gpt-chatbot-messages">
                    <div class="wp-gpt-chatbot-message bot">
                        <div class="wp-gpt-chatbot-message-content"><?php echo esc_html($settings['welcome_message']); ?></div>
                    </div>
                </div>
                <div class="wp-gpt-chatbot-input-container">
                    <textarea class="wp-gpt-chatbot-input" placeholder="Type your message..."></textarea>
                    <button class="wp-gpt-chatbot-send" style="background-color: <?php echo esc_attr($settings['primary_color']); ?>; color: <?php echo esc_attr($settings['secondary_color']); ?>;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
