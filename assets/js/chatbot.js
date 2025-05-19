/**
 * WP GPT Chatbot Frontend JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialize popup chatbot if it exists
        initPopupChatbot();
        
        // Initialize all inline chatbots from shortcodes
        initInlineChatbots();
    });
    
    /**
     * Initialize the popup chatbot
     */
    function initPopupChatbot() {
        const $container = $('#wp-gpt-chatbot-container');
        
        // If popup chatbot doesn't exist, return
        if ($container.length === 0) {
            return;
        }
        
        const $chatButton = $('.wp-gpt-chatbot-button', $container);
        const $chatBox = $('.wp-gpt-chatbot-box', $container);
        const $closeButton = $('.wp-gpt-chatbot-close', $container);
        const $messagesContainer = $('.wp-gpt-chatbot-messages', $container);
        const $input = $('.wp-gpt-chatbot-input', $container);
        const $sendButton = $('.wp-gpt-chatbot-send', $container);
        
        // Store conversation history
        let conversationHistory = [];
        
        // Toggle chat box
        $chatButton.on('click', function() {
            $chatBox.toggle();
            $input.focus();
        });
        
        // Close chat box
        $closeButton.on('click', function() {
            $chatBox.hide();
        });
        
        // Send message on Enter key
        $input.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage($input, $messagesContainer, conversationHistory);
            }
        });
        
        // Send message on button click
        $sendButton.on('click', function() {
            sendMessage($input, $messagesContainer, conversationHistory);
        });
    }
    
    /**
     * Initialize all inline chatbots from shortcodes
     */
    function initInlineChatbots() {
        $('.wp-gpt-chatbot-inline').each(function() {
            const $container = $(this);
            const $messagesContainer = $('.wp-gpt-chatbot-inline-messages', $container);
            const $input = $('.wp-gpt-chatbot-input', $container);
            const $sendButton = $('.wp-gpt-chatbot-send', $container);
            
            // Store conversation history for this instance
            let conversationHistory = [];
            
            // Send message on Enter key
            $input.on('keypress', function(e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage($input, $messagesContainer, conversationHistory);
                }
            });
            
            // Send message on button click
            $sendButton.on('click', function() {
                sendMessage($input, $messagesContainer, conversationHistory);
            });
        });
    }
    
    /**
     * Send message to ChatGPT API
     * 
     * @param {jQuery} $input Input element
     * @param {jQuery} $messagesContainer Messages container element
     * @param {Array} conversationHistory Conversation history array
     */
    function sendMessage($input, $messagesContainer, conversationHistory) {
        const messageText = $input.val().trim();
        
        if (messageText === '') {
            return;
        }
        
        // Add user message to UI
        addMessage($messagesContainer, 'user', messageText);
        
        // Clear input
        $input.val('');
        
        // Add loading indicator
        const $loadingMessage = $('<div class="wp-gpt-chatbot-message bot"><div class="wp-gpt-chatbot-loading"><span></span><span></span><span></span></div></div>');
        $messagesContainer.append($loadingMessage);
        scrollToBottom($messagesContainer);
        
        // Add to conversation history
        conversationHistory.push({
            role: 'user',
            content: messageText
        });
        
        // Send to ChatGPT API
        $.ajax({
            url: wpGptChatbotSettings.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_gpt_chatbot_send_message',
                nonce: wpGptChatbotSettings.nonce,
                message: messageText,
                conversation: JSON.stringify(conversationHistory)
            },
            success: function(response) {
                // Remove loading indicator
                $loadingMessage.remove();
                
                if (response.success) {
                    // Add bot response to UI
                    addMessage($messagesContainer, 'bot', response.data.message);
                    
                    // Add to conversation history
                    conversationHistory.push({
                        role: 'assistant',
                        content: response.data.message
                    });
                    
                    // Keep conversation history manageable (last 10 messages)
                    if (conversationHistory.length > 10) {
                        conversationHistory = conversationHistory.slice(-10);
                    }
                } else {
                    // Show error
                    addMessage($messagesContainer, 'bot', 'Error: ' + response.data.message);
                }
            },
            error: function() {
                // Remove loading indicator
                $loadingMessage.remove();
                
                // Show error
                addMessage($messagesContainer, 'bot', 'Sorry, there was an error communicating with the server.');
            }
        });
    }
    
    /**
     * Add message to UI
     * 
     * @param {jQuery} $messagesContainer Messages container element
     * @param {string} role Message role (user or bot)
     * @param {string} content Message content
     */
    function addMessage($messagesContainer, role, content) {
        const $message = $('<div class="wp-gpt-chatbot-message ' + role + '"><div class="wp-gpt-chatbot-message-content">' + formatMessage(content) + '</div></div>');
        $messagesContainer.append($message);
        scrollToBottom($messagesContainer);
    }
    
    /**
     * Format message with markdown-like support
     * 
     * @param {string} message Message to format
     * @return {string} Formatted message
     */
    function formatMessage(message) {
        // Convert line breaks to <br>
        message = message.replace(/\n/g, '<br>');
        
        // Bold text: **text**
        message = message.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        // Italic text: *text*
        message = message.replace(/\*(.*?)\*/g, '<em>$1</em>');
        
        // Links: [text](url)
        message = message.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank">$1</a>');
        
        return message;
    }
    
    /**
     * Scroll messages to bottom
     * 
     * @param {jQuery} $messagesContainer Messages container element
     */
    function scrollToBottom($messagesContainer) {
        $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
    }
    
})(jQuery);
