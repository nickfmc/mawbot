<?php
/**
 * ChatGPT API Integration Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the Database Manager class
require_once WP_GPT_CHATBOT_PATH . 'includes/class-database-manager.php';

class WP_GPT_Chatbot_API {
    private $api_key;
    private $model;
    private $training_prompt;
    private $training_data;
    private $unknown_response;
    private $conversation_memory;
    private $selective_context;
    
    public function __construct() {
        $settings = get_option('wp_gpt_chatbot_settings');
        $this->api_key = $settings['api_key'];
        $this->model = $settings['model'];
        $this->training_prompt = $settings['training_prompt'];
        $this->training_data = isset($settings['training_data']) ? $settings['training_data'] : array();
        $this->unknown_response = isset($settings['unknown_response']) ? $settings['unknown_response'] : 'I don\'t have enough information to answer that question yet. Your question has been logged and our team will provide an answer soon.';
        $this->conversation_memory = isset($settings['conversation_memory']) ? (int)$settings['conversation_memory'] : 5;
        $this->selective_context = isset($settings['selective_context']) ? (bool)$settings['selective_context'] : true;
        
        // Debug: Log the unknown response value
        error_log('WP GPT Chatbot: Constructor - Unknown response value: "' . $this->unknown_response . '"');
        
        // Add AJAX handlers
        add_action('wp_ajax_wp_gpt_chatbot_send_message', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_wp_gpt_chatbot_send_message', array($this, 'handle_chat_request'));
    }
    
    public function handle_chat_request() {
        check_ajax_referer('wp_gpt_chatbot_nonce', 'nonce');
        
        $message = sanitize_text_field($_POST['message']);
        $conversation_history = isset($_POST['conversation']) ? json_decode(stripslashes($_POST['conversation']), true) : array();
        
        if (empty($this->api_key)) {
            wp_send_json_error(array('message' => 'API key not configured.'));
            return;
        }
        
        try {
            $response = $this->send_to_chatgpt($message, $conversation_history);
            error_log('WP GPT Chatbot: Final response before sending to frontend: "' . $response . '"');
            wp_send_json_success(array('message' => $response));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
        
        wp_die();
    }
    
    /**
     * Get relevant training data for a query
     * 
     * @param string $query The user's query
     * @return array Relevant training data entries
     */
    private function get_relevant_training_data($query) {
        // Normalize the query
        $query = strtolower(trim($query));
        
        // Extract keywords (remove common stop words)
        $stopwords = array('a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'in', 'on', 'at', 'to', 'for', 'with', 'about', 'what', 'how', 'when', 'where', 'why', 'who', 'which');
        $words = explode(' ', $query);
        $keywords = array();
        
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word) && !in_array($word, $stopwords) && strlen($word) > 2) {
                $keywords[] = $word;
            }
        }
        
        // Get context limit from settings
        $settings = get_option('wp_gpt_chatbot_settings');
        $context_limit = isset($settings['context_limit']) ? intval($settings['context_limit']) : 15;
        
        // Early return if no keywords
        if (empty($keywords)) {
            // Return a subset of manual entries as fallback
            $manual_entries = array();
            foreach ($this->training_data as $item) {
                if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                    $manual_entries[] = $item;
                    // Limit to 5 manual entries to save tokens
                    if (count($manual_entries) >= min(5, $context_limit / 3)) {
                        break;
                    }
                }
            }
            return $manual_entries;
        }
        
        // Score each training data entry
        $scored_entries = array();
        
        foreach ($this->training_data as $index => $item) {
            $score = 0;
            $content = strtolower($item['question'] . ' ' . $item['answer']);
            
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    $score += 1;
                }
            }
            
            // Only include entries with at least one keyword match
            if ($score > 0) {
                $scored_entries[] = array(
                    'item' => $item,
                    'score' => $score
                );
            }
        }
        
        // Sort by score (highest first)
        usort($scored_entries, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top results (limiting to conserve tokens)
        $result = array();
        $manual_count = 0;
        $website_count = 0;
        
        // Calculate limits based on context_limit setting
        $manual_limit = max(3, intval($context_limit * 0.3)); // 30% for manual entries
        $website_limit = max(5, intval($context_limit * 0.7)); // 70% for website content
        
        foreach ($scored_entries as $entry) {
            $item = $entry['item'];
            
            // Limit to manual_limit manual entries and website_limit website content entries
            if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
                if ($website_count < $website_limit) {
                    $result[] = $item;
                    $website_count++;
                }
            } else {
                if ($manual_count < $manual_limit) {
                    $result[] = $item;
                    $manual_count++;
                }
            }
            
            // Stop once we have enough entries
            if ($manual_count >= $manual_limit && $website_count >= $website_limit) {
                break;
            }
        }
        
        // If we don't have enough relevant entries, add some top manual entries
        if (count($result) < 3) {
            $manual_entries = array();
            foreach ($this->training_data as $item) {
                if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                    $manual_entries[] = $item;
                    // Limit to 3 additional entries
                    if (count($manual_entries) >= 3) {
                        break;
                    }
                }
            }
            $result = array_merge($result, $manual_entries);
        }
        
        return $result;
    }

    /**
     * Generate training content from the saved Q&A pairs
     * 
     * @param string $query Optional query to get relevant training data
     * @return string Formatted training content
     */
    private function generate_training_content($query = null) {
        $content = "";
        
        if (empty($this->training_data)) {
            return $content;
        }
        
        // Get website content settings
        $settings = get_option('wp_gpt_chatbot_settings');
        $website_content_enabled = isset($settings['website_content']['enabled']) && $settings['website_content']['enabled'];
        
        // If we have a query and selective context is enabled, get relevant training data
        if (!empty($query) && $this->selective_context && $website_content_enabled) {
            $relevant_data = $this->get_relevant_training_data($query);
            
            if (!empty($relevant_data)) {
                $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                
                // Group data by source type
                $manual_entries = array();
                $website_entries = array();
                
                foreach ($relevant_data as $item) {
                    if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
                        $website_entries[] = $item;
                    } else {
                        $manual_entries[] = $item;
                    }
                }
                
                // Add manual entries first (they are typically more important)
                foreach ($manual_entries as $item) {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                }
                
                // Add website content entries
                if (!empty($website_entries)) {
                    $content .= "\n\nAdditional website information:\n\n";
                    
                    foreach ($website_entries as $item) {
                        $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                    }
                }
                
                $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
            } else {
                // Add standard training content
                $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                
                // Add a limited subset of manual entries
                $count = 0;
                foreach ($this->training_data as $item) {
                    if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                        $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                        $count++;
                        
                        // Limit to 5 entries to save tokens
                        if ($count >= 5) {
                            break;
                        }
                    }
                }
                
                $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
            }
        } else {
            // Without a query, just add all training data with clear boundaries
            $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
            
            foreach ($this->training_data as $item) {
                if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                }
            }
            
            $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
            $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
        }
        
        return $content;
    }
    
    /**
     * Determine if the question should be classified as unknown
     * This uses a simplified approach to save tokens
     * 
     * @param string $question The user's question
     * @return bool Whether the question should be treated as unknown
     */
    private function is_unknown_question($question) {
        // If no training data exists, we can't confidently answer anything specific
        if (empty($this->training_data)) {
            return true;
        }
        
        // First, check for very specific company/brand questions
        $question_lower = strtolower($question);
        
        // Check for company-specific patterns that should be treated as unknown unless explicitly mentioned
        $company_patterns = array(
            '/what does ([a-zA-Z\s]+) communications? do/',
            '/what is ([a-zA-Z\s]+) (company|corp|inc|ltd|llc)/',
            '/who is ([a-zA-Z\s]+) (company|corp|inc|ltd|llc)/',
            '/what does ([a-zA-Z\s]+) (company|corp|inc|ltd|llc) do/',
            '/tell me about ([a-zA-Z\s]+) (company|corp|inc|ltd|llc)/',
            '/what services does ([a-zA-Z\s]+) provide/',
            '/what does ([a-zA-Z\s]+) specialize in/'
        );
        
        foreach ($company_patterns as $pattern) {
            if (preg_match($pattern, $question_lower, $matches)) {
                // Extract the company name
                $company_name = trim($matches[1]);
                
                // Debug logging
                error_log('WP GPT Chatbot: Company pattern matched - Pattern: ' . $pattern . ', Company: ' . $company_name);
                
                // Check if this specific company is mentioned in our training data
                $found_company = false;
                foreach ($this->training_data as $item) {
                    $training_text = strtolower($item['question'] . ' ' . $item['answer']);
                    if (strpos($training_text, $company_name) !== false) {
                        $found_company = true;
                        error_log('WP GPT Chatbot: Company found in training data: ' . $company_name);
                        break;
                    }
                }
                
                // If the specific company isn't in our training data, it's unknown
                if (!$found_company) {
                    error_log('WP GPT Chatbot: Company NOT found in training data, marking as unknown: ' . $company_name);
                    return true;
                }
            }
        }
        
        // Get relevant data to check if we have useful information
        // Only use selective context if it's enabled in settings
        $relevant_data = $this->selective_context ? 
            $this->get_relevant_training_data($question) : 
            $this->training_data;
        
        // Be more strict: require at least some reasonably relevant information
        // If we have fewer than 2 relevant entries, consider it unknown
        if (empty($relevant_data) || count($relevant_data) < 2) {
            return true;
        }
        
        // For questions with generic keywords that might match loosely, be more strict
        $generic_keywords = array('what', 'does', 'do', 'company', 'business', 'services', 'provide', 'specialize');
        $question_words = explode(' ', $question_lower);
        $generic_word_count = 0;
        $specific_word_count = 0;
        
        foreach ($question_words as $word) {
            $word = trim($word, '.,?!');
            if (strlen($word) > 2) {
                if (in_array($word, $generic_keywords)) {
                    $generic_word_count++;
                } else {
                    $specific_word_count++;
                }
            }
        }
        
        // If the question is mostly generic words, require higher relevance
        if ($generic_word_count >= $specific_word_count && count($relevant_data) < 3) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Send a message to the ChatGPT API and get a response
     * 
     * @param string $message The user's message
     * @param array $conversation_history Previous messages in the conversation
     * @return string The response from ChatGPT or unknown question message
     */
    private function send_to_chatgpt($message, $conversation_history) {
        // Check cache first
        require_once WP_GPT_CHATBOT_PATH . 'includes/class-cache-manager.php';
        $cached_response = WP_GPT_Chatbot_Cache_Manager::get_cached_response($message);
        
        if ($cached_response !== false) {
            error_log('WP GPT Chatbot: Returning cached response: "' . $cached_response . '"');
            return $cached_response;
        }
        
        // Check if this is an unknown question
        $is_unknown = $this->is_unknown_question($message);
        
        if ($is_unknown) {
            // Log the unknown question to the database
            WP_GPT_Chatbot_Database_Manager::log_unknown_question($message);
            
            // Debug: Log the unknown response value
            error_log('WP GPT Chatbot: Unknown question detected: ' . $message);
            error_log('WP GPT Chatbot: Unknown response: ' . $this->unknown_response);
            
            // Ensure we have a fallback response if the setting is empty
            $response = !empty($this->unknown_response) ? $this->unknown_response : 'I don\'t have that specific information in my knowledge base. Please contact us directly for assistance.';
            
            // Return the unknown question response
            return $response;
        }
        
        // If we have confidence to answer, proceed with the API call
        $url = 'https://api.openai.com/v1/chat/completions';
        
        // Generate the full system prompt with relevant training data
        $full_prompt = $this->training_prompt . $this->generate_training_content($message);
        
        // Prepare the conversation messages
        $messages = array(
            array(
                'role' => 'system',
                'content' => $full_prompt
            )
        );
        
        // Add conversation history (limited based on settings to save tokens)
        $recent_history = array_slice($conversation_history, -$this->conversation_memory);
        foreach ($recent_history as $entry) {
            $messages[] = array(
                'role' => $entry['role'],
                'content' => $entry['content']
            );
        }
        
        // Add the current user message
        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );
        
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.7
        );
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'body' => json_encode($body),
            'method' => 'POST',
            'timeout' => 30
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($response_body['error'])) {
            throw new Exception($response_body['error']['message']);
        }
        
        $answer = $response_body['choices'][0]['message']['content'];
        
        // Check if ChatGPT responded that it doesn't have the information
        // This catches cases where our keyword matching thought we had relevant data, but ChatGPT correctly identified it doesn't
        $unknown_indicators = array(
            "I don't have that specific information",
            "I don't have information about",
            "not in my knowledge base",
            "I don't have enough information",
            "I can only answer questions based on",
            "I don't have that information available",
            "Please contact us directly",
            "I can't provide information about",
            "I don't have details about",
            "I don't have any information about",
            "I cannot provide information about",
            "I don't have data about",
            "I'm not able to provide information about",
            "I cannot answer questions about",
            "I don't know about",
            "I'm unable to provide information about",
            "that information is not available",
            "that specific information is not available",
            "I can only answer based on",
            "I only have access to",
            "I'm limited to answering"
        );
        
        $answer_lower = strtolower($answer);
        $is_unknown_response = false;
        
        foreach ($unknown_indicators as $indicator) {
            if (strpos($answer_lower, strtolower($indicator)) !== false) {
                $is_unknown_response = true;
                break;
            }
        }
        
        // Additional check: if the response is very short and mentions limitations
        if (!$is_unknown_response && strlen($answer) < 200) {
            $limitation_words = array('sorry', 'unable', 'cannot', 'can\'t', 'limited', 'only', 'just', 'knowledge base');
            $word_count = 0;
            foreach ($limitation_words as $word) {
                if (strpos($answer_lower, $word) !== false) {
                    $word_count++;
                }
            }
            // If 2 or more limitation words are found in a short response, it's likely an unknown response
            if ($word_count >= 2) {
                $is_unknown_response = true;
            }
        }
        
        if ($is_unknown_response) {
            // This is actually an unknown question - log it and return the unknown response
            WP_GPT_Chatbot_Database_Manager::log_unknown_question($message);
            return $this->unknown_response;
        }
        
        // Cache the response for future use
        if (isset($settings['enable_caching']) && $settings['enable_caching']) {
            // Get the expiration time from settings
            $expiration = isset($settings['cache_expiration']) ? intval($settings['cache_expiration']) : 604800; // Default 1 week
            
            WP_GPT_Chatbot_Cache_Manager::cache_response($message, $answer, $expiration);
        }
        
        return $answer;
    }
}
