<?php
/**
 * Uninstall plugin
 *
 * @package WP_GPT_Chatbot
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wp_gpt_chatbot_settings');
