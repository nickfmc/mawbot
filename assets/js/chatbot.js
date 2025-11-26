/**
 * WP GPT Chatbot Frontend JavaScript
 */
(function($) {
    'use strict';
    
    /**
     * Push event to Google Analytics dataLayer
     * 
     * @param {string} event Event name
     * @param {object} data Additional data to track
     */
    function trackEvent(event, data) {
        window.dataLayer = window.dataLayer || [];
        console.log('GA Event:', event, data); // Debug log
        window.dataLayer.push({
            event: event,
            ...data
        });
    }
    
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
            const isOpening = !$chatBox.is(':visible');
            $chatBox.toggle();
            $input.focus();
            
            // Track chatbot open
            if (isOpening) {
                trackEvent('ai_chatbot_interaction', {
                    interaction_type: 'chatbot_opened',
                    chatbot_type: 'popup'
                });
            }
        });
        
        // Close chat box
        $closeButton.on('click', function() {
            $chatBox.hide();
        });
        
        // Track typing in AI input (debounced)
        let typingTracked = false;
        $input.on('input', function() {
            if (!typingTracked && $(this).val().trim().length > 0) {
                typingTracked = true;
                trackEvent('ai_chatbot_interaction', {
                    interaction_type: 'typing_started',
                    chatbot_type: 'popup'
                });
            }
        });
        
        // Reset typing tracker when message is sent
        $input.on('blur', function() {
            if ($(this).val().trim().length === 0) {
                typingTracked = false;
            }
        });
        
        // Send message on Enter key
        $input.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage($input, $messagesContainer, conversationHistory);
                typingTracked = false;
            }
        });
        
        // Send message on button click
        $sendButton.on('click', function() {
            sendMessage($input, $messagesContainer, conversationHistory);
            typingTracked = false;
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
            
            // Track typing in AI input (debounced)
            let typingTracked = false;
            $input.on('input', function() {
                if (!typingTracked && $(this).val().trim().length > 0) {
                    typingTracked = true;
                    trackEvent('ai_chatbot_interaction', {
                        interaction_type: 'typing_started',
                        chatbot_type: 'inline'
                    });
                }
            });
            
            // Reset typing tracker when message is sent
            $input.on('blur', function() {
                if ($(this).val().trim().length === 0) {
                    typingTracked = false;
                }
            });
            
            // Track clicks in AI search box
            $input.on('click', function() {
                trackEvent('ai_chatbot_interaction', {
                    interaction_type: 'search_box_clicked',
                    chatbot_type: 'inline'
                });
            });
            
            // Send message on Enter key
            $input.on('keypress', function(e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage($input, $messagesContainer, conversationHistory);
                    typingTracked = false;
                }
            });
            
            // Send message on button click
            $sendButton.on('click', function() {
                sendMessage($input, $messagesContainer, conversationHistory);
                typingTracked = false;
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
        
        // Track message submission
        trackEvent('ai_chatbot_interaction', {
            interaction_type: 'message_sent',
            message_length: messageText.length,
            conversation_length: conversationHistory.length
        });
        
        // Add user message to UI
        addMessage($messagesContainer, 'user', messageText);
        
        // Clear input
        $input.val('');
        
        // Add loading indicator
        const $loadingMessage = $('<div class="wp-gpt-chatbot-message bot"><div class="wp-gpt-chatbot-loading"><span></span><span></span><span></span></div></div>');
        $messagesContainer.append($loadingMessage);
        scrollToBottom($messagesContainer);
        
        // Replace "you" with company name for better context, handling grammar
        const processedMessage = replaceYouWithBotName(messageText);
        
        // Add to conversation history
        conversationHistory.push({
            role: 'user',
            content: processedMessage
        });
        
        // Send to ChatGPT API
        $.ajax({
            url: wpGptChatbotSettings.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_gpt_chatbot_send_message',
                nonce: wpGptChatbotSettings.nonce,
                message: processedMessage,
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
        
        // Track link clicks within AI responses
        if (role === 'bot') {
            $message.find('a').on('click', function(e) {
                const linkUrl = $(this).attr('href');
                const linkText = $(this).text();
                trackEvent('ai_chatbot_interaction', {
                    interaction_type: 'link_clicked',
                    link_url: linkUrl,
                    link_text: linkText,
                    link_location: 'ai_response'
                });
            });
        }
    }
    
    /**
     * Format message with markdown-like support
     * 
     * @param {string} message Message to format
     * @return {string} Formatted message
     */
    function formatMessage(message) {
        // First, process markdown links [text](url) - do this BEFORE line breaks
        message = message.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        
        // Convert line breaks to <br>
        message = message.replace(/\n/g, '<br>');
        
        // Bold text: **text** - process before single asterisks
        message = message.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        // Italic text: *text* (single asterisk only, not part of double asterisks)
        message = message.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
        
        // Convert bullet points to proper list items
        message = message.replace(/^•\s(.+)$/gm, '<li>$1</li>');
        
        // Convert standalone URLs to clickable links (simple method)
        message = message.replace(/(^|[^"'>])(https?:\/\/[^\s<"']+)/g, function(match, prefix, url) {
            // Check if this URL is already inside an anchor tag
            if (match.indexOf('<a ') !== -1 || match.indexOf('href=') !== -1) {
                return match;
            }
            return prefix + '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>';
        });
        
        // Wrap consecutive list items in ul tags
        message = message.replace(/(<li>.*?<\/li>(?:<br><li>.*?<\/li>)*)/g, '<ul>$1</ul>');
        
        // Clean up br tags around lists
        message = message.replace(/<br><ul>/g, '<ul>');
        message = message.replace(/<\/ul><br>/g, '</ul>');
        message = message.replace(/<li>(.*?)<\/li><br>/g, '<li>$1</li>');
        
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
    
    /**
     * Replace "you" references with bot name, handling grammar properly
     * 
     * @param {string} message The original message
     * @return {string} Message with "you" replaced by bot name with proper grammar
     */
    function replaceYouWithBotName(message) {
        // Get bot name from wpGptChatbotSettings, fallback to 'the company'
        const botName = wpGptChatbotSettings.botName || 'the company';
        
        let processedMessage = message;
        
        // Define patterns to match "you" references in different contexts
        const patterns = [
            // "What do you do?" -> "What does [Company] do?"
            { pattern: /\bwhat do you do\b/gi, replacement: `what does ${botName} do` },
            { pattern: /\bwhat are you doing\b/gi, replacement: `what is ${botName} doing` },
            { pattern: /\bwhat can you do\b/gi, replacement: `what can ${botName} do` },
            
            // "Who are you?" -> "Who is [Company]?"
            { pattern: /\bwho are you\b/gi, replacement: `who is ${botName}` },
            { pattern: /\bwho is you\b/gi, replacement: `who is ${botName}` },
            
            // "Do you have/offer/provide..." -> "Does [Company] have/offer/provide..."
            { pattern: /\bdo you (have|offer|provide|sell|make|create|support|handle|deal|work)\b/gi, replacement: `does ${botName} $1` },
            { pattern: /\bdo you (specialize|focus)\b/gi, replacement: `does ${botName} $1` },
            
            // "Can you..." -> "Can [Company]..."
            { pattern: /\bcan you (help|assist|provide|offer|do|handle|support|work)\b/gi, replacement: `can ${botName} $1` },
            
            // "Are you..." -> "Is [Company]..."
            { pattern: /\bare you (available|open|closed|located|based|able|willing)\b/gi, replacement: `is ${botName} $1` },
            { pattern: /\bare you a (company|business|service|organization)\b/gi, replacement: `is ${botName} a $1` },
            
            // "How do you..." -> "How does [Company]..."
            { pattern: /\bhow do you (work|operate|function|handle|process|deal|manage)\b/gi, replacement: `how does ${botName} $1` },
            
            // "Where are you..." -> "Where is [Company]..."
            { pattern: /\bwhere are you (located|based|situated)\b/gi, replacement: `where is ${botName} $1` },
            
            // "When do you..." -> "When does [Company]..."
            { pattern: /\bwhen do you (open|close|operate|work)\b/gi, replacement: `when does ${botName} $1` },
            { pattern: /\bwhen are you (open|closed|available)\b/gi, replacement: `when is ${botName} $1` },
            
            // "Why do you..." -> "Why does [Company]..."
            { pattern: /\bwhy do you (do|offer|provide|specialize|focus)\b/gi, replacement: `why does ${botName} $1` },
            
            // General patterns for common business questions
            { pattern: /\byour (services|products|company|business|team|staff|hours|prices|pricing|location|address|phone|email)\b/gi, replacement: `${botName}'s $1` },
            { pattern: /\byour (website|site)\b/gi, replacement: `${botName}'s $1` },
            
            // Questions about clients/customers
            { pattern: /\bwhat clients do you (represent|serve|work with|have)\b/gi, replacement: `what clients does ${botName} $1` },
            { pattern: /\bwhat brands do you (represent|serve|work with|have)\b/gi, replacement: `what brands does ${botName} $1` },
            { pattern: /\bwho do you (serve|help|work with|represent)\b/gi, replacement: `who does ${botName} $1` },
            
            // Additional PAN-specific patterns
            { pattern: /\bwhat industries do you (serve|work in|focus on|specialize in)\b/gi, replacement: `what industries does ${botName} $1` },
            { pattern: /\bwhat sectors do you (serve|work in|focus on|specialize in)\b/gi, replacement: `what sectors does ${botName} $1` },
            { pattern: /\bwhat companies do you (work with|represent|serve)\b/gi, replacement: `what companies does ${botName} $1` },
            { pattern: /\bwhere are you (located|based|headquartered)\b/gi, replacement: `where is ${botName} $1` },
            { pattern: /\bhow big are you\b/gi, replacement: `how big is ${botName}` },
            { pattern: /\bhow large are you\b/gi, replacement: `how large is ${botName}` },
            { pattern: /\bwhat makes you (different|unique|special)\b/gi, replacement: `what makes ${botName} $1` },
            { pattern: /\bwhy should I (choose|hire|work with) you\b/gi, replacement: `why should I $1 ${botName}` },
            { pattern: /\bcan you help (me|us|my company)\b/gi, replacement: `can ${botName} help $1` },
            { pattern: /\bdo you work with (startups|small businesses|enterprises|nonprofits)\b/gi, replacement: `does ${botName} work with $1` },
            
            // Questions about experience/history
            { pattern: /\bhow long have you been\b/gi, replacement: `how long has ${botName} been` },
            { pattern: /\bwhen did you (start|begin|establish|found)\b/gi, replacement: `when did ${botName} $1` }
        ];
        
        // Apply all patterns
        patterns.forEach(({ pattern, replacement }) => {
            processedMessage = processedMessage.replace(pattern, replacement);
        });
        
        return processedMessage;
    }
    
})(jQuery);
