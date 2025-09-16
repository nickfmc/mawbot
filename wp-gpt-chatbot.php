<?php
/**
 * Plugin Name: MAWBOT - Custom Chatbot with ChatGPT API
 * Plugin URI: https://example.com/wp-gpt-chatbot
 * Description: A WordPress plugin that creates a custom chatbot using the ChatGPT API with your own training material.
 * Version: 1.0.0
 * Author: Nick Murray
 * Author URI: https://mountainairweb.com
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
            'training_prompt' => 'You are PAN\'s intelligent website assistant. Your role is to guide visitors by answering questions about PAN\'s business, services, industry expertise, client success stories, and company culture. Always use content from PAN\'s website as your source, and provide accurate, concise, and helpful answers that reflect PAN\'s brand voice: confident but not boastful, curious and informed, purpose-driven, empathetic, and approachable.\n\nCRITICAL PRIORITY RULES:\n1. TRAINING MATERIAL PRIORITY: When Training Material Management Q&A pairs exist and are relevant to the user\'s query, you MUST use those answers 100% of the time. These manually created Q&A pairs take absolute precedence over all other content.\n2. LINK VALIDATION: ONLY include links that exist in your website content knowledge base. All internal links must be relative paths (e.g., /about-us/, /services/marketing/) and must be verified to exist on the website. NEVER link to pages that don\'t exist or return 404 errors.\n3. COMPANY REFERENCE: When users say "you," "your," or similar pronouns, they are referring to PAN, not the chatbot. Always interpret and respond as if they are asking about PAN directly (e.g., "What do you do?" = "What does PAN do?").\n\nPrioritize these topics when answering:\n• PAN\'s integrated marketing and PR services\n• Industries PAN serves (especially B2B technology and healthcare)\n• Case studies and client success stories\n• PAN\'s approach to measurement, strategy, and storytelling\n• Career opportunities and company values\n\nAnswering guidelines:\n• Keep responses concise (2–4 sentences unless the visitor requests more depth). Use spacing to increase readability.\n• Use clear, audience-friendly language. Avoid jargon unless explained.\n• Where possible, include an example, stat, or client reference to provide value before linking to a page.\n• When linking to a page, use descriptive anchor text (e.g., "Explore our approach to integrated storytelling"). Never use "Click here." Include links to relevant insight pages, success stories, services and expertise pages as relevant. Offer 2-3 link options, when relevant, in conversations after every 2-3 queries.\n• Draw from PAN\'s boilerplate and brand messaging when describing the agency at a high level.\n• Maintain brand style conventions: Oxford comma, spell out "percent," and follow Chicago Manual of Style formatting.\n\nIMPORTANT: Only include valid links that do not return 404 errors from pages within the website content knowledge base.\n\nHandling limits:\n• If a question is outside the scope of PAN\'s website content or business focus, politely redirect the visitor to contact our CMO for more details, and provide the following email address: pandabot@pancomm.com\n• Do not answer questions about competitors, pricing, or topics unrelated to PAN. Instead say: "That\'s outside the scope of what I can answer, but our team would be happy to help."\n\nTone & personality:\n• Professional, friendly, and informative; occasionally playful when it adds warmth.\n• Empathetic and audience-aware (acknowledge the user\'s perspective, e.g., "That\'s a common challenge for many B2B brands we work with.").\n• Reinforce PAN\'s identity by occasionally referencing key phrases such as "We move ideas" or "Good people doing good work."\n\nIMPORTANT: Treat questions as equivalent regardless of punctuation differences. A question with or without a question mark should be considered the same (e.g., "What do you do" and "What do you do?" are identical questions). Also consider slight variations in wording as potentially matching the same intent.\n\nAlways refer to PAN as PAN, never PAN Communications.',
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
            'media_coverage' => array(), // New media coverage data storage
            // Token optimization settings
            'enable_caching' => true,
            'cache_expiration' => 604800, // 1 week in seconds
            'conversation_memory' => 5,
            'selective_context' => true,
            'show_related_content' => true,
            'enable_question_logging' => false
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
    if (empty($settings['api_key'])) {
        return '<p>' . __('ChatGPT API key not configured.', 'wp-gpt-chatbot') . '</p>';
    }
    $atts = shortcode_atts(array(
        'height' => '400px',
        'welcome_message' => '',
    ), $atts, 'wp_gpt_chatbot');
    if (empty($atts['welcome_message'])) {
        $atts['welcome_message'] = isset($settings['welcome_message']) ? $settings['welcome_message'] : '';
    }
    ob_start();
    $unique_id = 'wp-gpt-chatbot-inline-' . uniqid();
    $primary_color = isset($settings['primary_color']) ? $settings['primary_color'] : '#007bff';
    $secondary_color = isset($settings['secondary_color']) ? $settings['secondary_color'] : '#ffffff';
    $placeholder_suggestions = isset($settings['placeholder_suggestions']) ? $settings['placeholder_suggestions'] : '';
    ?>
    <div id="<?php echo esc_attr($unique_id); ?>" class="wp-gpt-chatbot-inline-form-wrapper" style="--wp-gpt-primary: <?php echo esc_attr($primary_color); ?>; --wp-gpt-secondary: <?php echo esc_attr($secondary_color); ?>;" data-placeholder-suggestions="<?php echo esc_attr($placeholder_suggestions); ?>">
        <form class="wp-gpt-chatbot-inline-form" autocomplete="off">
            <input type="text" class="wp-gpt-chatbot-inline-input" placeholder="How do you service global clients?" />
            <button type="submit" class="wp-gpt-chatbot-inline-btn">Ask Us How &gt;</button>
        </form>
        <div class="wp-gpt-chatbot-inline-popup"></div>
        <div class="wp-gpt-chatbot-pills-popup" style="display:none"></div>
    </div>
    <script>
    (function($){
        var $wrapper = $('#<?php echo esc_js($unique_id); ?>');
        var $form = $wrapper.find('.wp-gpt-chatbot-inline-form');
        var $input = $wrapper.find('.wp-gpt-chatbot-inline-input');
        var $popup = $wrapper.find('.wp-gpt-chatbot-inline-popup');
        var $questionsBtn = $wrapper.find('.wp-gpt-chatbot-questions-btn');
        var $pillsPopup = $wrapper.find('.wp-gpt-chatbot-pills-popup');
        var conversation = [];
        var welcomeMessage = <?php echo json_encode($atts['welcome_message']); ?>;
        var chatOpen = false;

        function renderPopup(contentHtml, animateOpen = true) {
            $popup.html(
                '<div class="wp-gpt-chatbot-popup-content">'+contentHtml+'</div>'+
                '<div class="wp-gpt-chatbot-popup-actions">'+
                    '<button type="button" class="wp-gpt-chatbot-popup-human" onclick="window.location.href=\'/contact\'">Contact a Human</button>'+
                    '<button type="button" class="wp-gpt-chatbot-popup-close" aria-label="Close"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 4L14 14M14 4L4 14" stroke="#243550" stroke-width="2" stroke-linecap="round"/></svg></button>'+
                '</div>'
            );
            if (animateOpen) {
                $popup.show();
                requestAnimationFrame(function(){
                    $popup.addClass('open').removeClass('closing');
                    // Scroll to bottom after animation
                    setTimeout(function(){
                        var $content = $popup.find('.wp-gpt-chatbot-popup-content');
                        $content.scrollTop($content[0].scrollHeight);
                    }, 350);
                });
            } else {
                $popup.show().addClass('open').removeClass('closing');
                // Scroll to bottom immediately
                var $content = $popup.find('.wp-gpt-chatbot-popup-content');
                $content.scrollTop($content[0].scrollHeight);
            }
        }

        function typeAssistantMessage($container, text, callback) {
            var i = 0;
            var speed = 18; // ms per character
            
            // Format the full text first with markdown support
            function formatMarkdown(message) {
                // First, process markdown links [text](url)
                message = message.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
                
                // Bold text: **text**
                message = message.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                
                // Italic text: *text*
                message = message.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
                
                // Convert bullet points to proper list items
                message = message.replace(/^•\s(.+)$/gm, '<li>$1</li>');
                
                // Convert standalone URLs to clickable links
                message = message.replace(/(^|[^"'>])(https?:\/\/[^\s<"']+)/g, function(match, prefix, url) {
                    if (match.indexOf('<a ') !== -1 || match.indexOf('href=') !== -1) {
                        return match;
                    }
                    return prefix + '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>';
                });
                
                // Wrap consecutive list items in ul tags
                message = message.replace(/(<li>.*?<\/li>(?:\n<li>.*?<\/li>)*)/g, '<ul>$1</ul>');
                
                // Convert line breaks to <br>
                message = message.replace(/\n/g, '<br>');
                
                // Clean up br tags around lists
                message = message.replace(/<br><ul>/g, '<ul>');
                message = message.replace(/<\/ul><br>/g, '</ul>');
                message = message.replace(/<li>(.*?)<\/li><br>/g, '<li>$1</li>');
                
                return message;
            }
            
            var formattedText = formatMarkdown(text);
            
            function type() {
                if (i <= formattedText.length) {
                    $container.html(formattedText.substring(0, i));
                    i++;
                    setTimeout(type, speed);
                    // Scroll to bottom as message types
                    var $content = $container.closest('.wp-gpt-chatbot-popup-content');
                    $content.scrollTop($content[0].scrollHeight);
                } else if (callback) {
                    callback();
                }
            }
            type();
        }

        $form.on('submit', function(e){
            e.preventDefault();
            var question = $input.val().trim();
            if(!question) return;
            conversation.push({role:'user',content:question});
            renderPopup('<div class="wp-gpt-chatbot-thinking">Thinking...</div>');
            $.ajax({
                url: wpGptChatbotSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_gpt_chatbot_send_message',
                    nonce: wpGptChatbotSettings.nonce,
                    message: question,
                    conversation: JSON.stringify(conversation)
                },
                success: function(response){
                    if(response.success){
                        conversation.push({role:'assistant',content:response.data.message});
                        var html = '';
                        for(var i=0;i<conversation.length;i++){
                            var msg = conversation[i];
                            if(msg.role==='user'){
                                html += '<div class="wp-gpt-chatbot-msg-user"><span>' + $('<div>').text(msg.content).html() + '</span></div>';
                            }else if(i === conversation.length-1){
                                html += '<div class="wp-gpt-chatbot-msg-assistant"><span class="wp-gpt-type-anim"></span></div>';
                            }else{
                                html += '<div class="wp-gpt-chatbot-msg-assistant"><span>' + $('<div>').text(msg.content).html().replace(/\n/g,'<br>') + '</span></div>';
                            }
                        }
                        renderPopup(html);
                        var $typeAnim = $popup.find('.wp-gpt-type-anim');
                        if($typeAnim.length) {
                            typeAssistantMessage($typeAnim, response.data.message);
                        }
                    }else{
                        renderPopup('<div class="wp-gpt-chatbot-error">'+$('<div>').text(response.data.message).html()+'</div>');
                    }
                },
                error: function(){
                    renderPopup('<div class="wp-gpt-chatbot-error">Sorry, there was an error. Please try again.</div>');
                }
            });
            $input.val('');
        });
        $wrapper.on('click','.wp-gpt-chatbot-popup-close',function(){
            chatOpen = false;
            $popup.removeClass('open').addClass('closing');
            setTimeout(function(){ $popup.hide().removeClass('closing'); conversation = []; }, 400);
        });
        $wrapper.on('click','.wp-gpt-chatbot-popup-human',function(){
            $popup.append('');
        });
        $input.on('focus',function(){
            // Do not hide popup on focus anymore
        });
        var placeholderAnimationActive = true;
        var placeholderTimeout;
        var placeholderSuggestions = $wrapper.data('placeholder-suggestions');
        var $pillList = null;
        var phrases = [];
        if (placeholderSuggestions) {
            phrases = placeholderSuggestions.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
            if (phrases.length > 0) {
                var pillsShown = false;
                // Build the popup pills list (hidden by default)
                var pillsHtml = '<div class="wp-gpt-chatbot-pills-popup-inner">';
                pillsHtml += '<div class="wp-gpt-chatbot-pills-heading">Common questions…</div>';
                pillsHtml += '<div class="wp-gpt-chatbot-pills-list">';
                phrases.forEach(function(phrase) {
                    pillsHtml += '<button type="button" class="wp-gpt-chatbot-pill"><span>'+phrase+'</span><svg class="wp-gpt-chatbot-pill-send" width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M3 15L15 9L3 3V7L11 9L3 11V15Z" fill="#FF9F00"/></svg></button>';
                });
                pillsHtml += '</div></div>';
                $pillsPopup.html(pillsHtml);
                // Show/hide popup on input focus
                $input.on('focus', function(){
                    if (!chatOpen && !pillsShown && $pillsPopup.children().length) {
                        $pillsPopup.addClass('opening').css('display','block');
                        setTimeout(function(){
                            $pillsPopup.addClass('opening');
                        }, 10); // ensure transition
                        pillsShown = true;
                    }
                });
                $input.on('blur', function(){
                    setTimeout(function(){ $pillsPopup.removeClass('opening').css('display','none'); }, 180);
                });
                // Send question on pill click
                $pillsPopup.on('click', '.wp-gpt-chatbot-pill', function(){
                    var phrase = $(this).find('span').text();
                    $input.val(phrase).focus();
                    $pillsPopup.hide();
                    $form.submit();
                });
            }
        }
        // Placeholder animation logic (must run after pill list setup)
        if (phrases.length > 0) {
            var input = $input.get(0);
            var phraseIndex = 0;
            var charIndex = 0;
            var typing = true;
            var delay = 60;
            var eraseDelay = 30;
            var holdDelay = 1200;
            function typePlaceholder() {
                if (!placeholderAnimationActive) return;
                var phrase = phrases[phraseIndex];
                if (typing) {
                    if (charIndex <= phrase.length) {
                        input.setAttribute('placeholder', phrase.substring(0, charIndex));
                        charIndex++;
                        placeholderTimeout = setTimeout(typePlaceholder, delay);
                    } else {
                        typing = false;
                        placeholderTimeout = setTimeout(typePlaceholder, holdDelay);
                    }
                } else {
                    if (charIndex > 0) {
                        charIndex--;
                        input.setAttribute('placeholder', phrase.substring(0, charIndex));
                        placeholderTimeout = setTimeout(typePlaceholder, eraseDelay);
                    } else {
                        typing = true;
                        phraseIndex = (phraseIndex + 1) % phrases.length;
                        placeholderTimeout = setTimeout(typePlaceholder, 400);
                    }
                }
            }
            typePlaceholder();
            $input.on('focus', function(){
                input.setAttribute('placeholder', '');
                placeholderAnimationActive = false;
                clearTimeout(placeholderTimeout);
            });
            $input.on('blur', function(){
                // Do not restart placeholder animation after blur
            });
        }
        // Stop placeholder animation and set generic placeholder on question submit
        $form.on('submit', function(e){
            placeholderAnimationActive = false;
            clearTimeout(placeholderTimeout);
            $input.attr('placeholder', 'What else is on your mind?');
            chatOpen = true;
            $pillsPopup.removeClass('opening').hide(); // Hide common questions popup on first submit
        });
    })(jQuery);
    </script>
    <?php
    $output = ob_get_clean();
    return $output;
}
