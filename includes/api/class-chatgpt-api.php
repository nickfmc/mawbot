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
     * Get relevant training data for a query (improved: partial/fuzzy match, always include at least one website_content entry)
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
                    if (count($manual_entries) >= min(5, $context_limit / 3)) {
                        break;
                    }
                }
            }
            error_log('WP GPT Chatbot: [MATCH] No keywords, returning manual entries only: ' . count($manual_entries));
            return $manual_entries;
        }
        
        // Score each training data entry (improved: allow partial/substring match for website_content)
        $scored_entries = array();
        $website_candidates = array();
        foreach ($this->training_data as $index => $item) {
            $score = 0;
            $content = strtolower($item['question'] . ' ' . $item['answer']);
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    $score += 1;
                }
            }
            // For website_content, also allow partial/substring/phrase match on the question
            if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
                if (strpos($content, $query) !== false) {
                    $score += 2; // Strong boost for phrase match
                } elseif (similar_text($item['question'], $query, $percent) && $percent > 60) {
                    $score += 1; // Fuzzy match
                }
                $website_candidates[] = array('item' => $item, 'score' => $score);
            }
            if ($score > 0) {
                $scored_entries[] = array('item' => $item, 'score' => $score);
            }
        }
        
        // Always include at least one website_content entry if any exist and question is not empty
        if (!empty($website_candidates)) {
            usort($website_candidates, function($a, $b) { return $b['score'] - $a['score']; });
            $top_website = $website_candidates[0]['item'];
            $already_included = false;
            foreach ($scored_entries as $entry) {
                if ($entry['item'] === $top_website) { $already_included = true; break; }
            }
            if (!$already_included) {
                array_unshift($scored_entries, array('item' => $top_website, 'score' => 99));
            }
        }
        
        // Sort by score (highest first)
        usort($scored_entries, function($a, $b) { return $b['score'] - $a['score']; });
        
        // Return top results (limiting to conserve tokens)
        $result = array();
        $manual_count = 0;
        $website_count = 0;
        $manual_limit = max(3, intval($context_limit * 0.3));
        $website_limit = max(5, intval($context_limit * 0.7));
        foreach ($scored_entries as $entry) {
            $item = $entry['item'];
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
                    if (count($manual_entries) >= 3) {
                        break;
                    }
                }
            }
            $result = array_merge($result, $manual_entries);
        }
        
        // Debug log which entries are being included
        $log = array();
        foreach ($result as $item) {
            $log[] = ($item['source_type'] ?? 'manual') . ': ' . (mb_substr($item['question'],0,60));
        }
        error_log('WP GPT Chatbot: [MATCH] Included entries for "' . $query . '": ' . print_r($log, true));
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
        $settings = get_option('wp_gpt_chatbot_settings');
        $website_content_enabled = isset($settings['website_content']['enabled']) && $settings['website_content']['enabled'];
        $manual_pages = isset($settings['website_content']['manual_pages']) && is_array($settings['website_content']['manual_pages']) ? $settings['website_content']['manual_pages'] : array();
        // Always use selective context if enabled, and always include manual_pages if set
        if (!empty($query) && $this->selective_context) {
            $relevant_data = $this->get_relevant_training_data($query);
            if (!empty($relevant_data)) {
                $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $manual_entries = array();
                $website_entries = array();
                $manual_page_entries = array();
                foreach ($relevant_data as $item) {
                    if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
                        // If crawler is enabled, include all website_content; if disabled, only include if in manual_pages
                        if ($website_content_enabled || (in_array($item['source_id'], $manual_pages))) {
                            // If crawler is off, only include manual_pages
                            if (!$website_content_enabled && !in_array($item['source_id'], $manual_pages)) continue;
                            // Group for output
                            if (in_array($item['source_id'], $manual_pages)) {
                                $manual_page_entries[] = $item;
                            } else {
                                $website_entries[] = $item;
                            }
                        }
                    } else {
                        $manual_entries[] = $item;
                    }
                }
                // Add manual entries first
                foreach ($manual_entries as $item) {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                }
                // Add manually included pages (when crawler is off)
                if (!empty($manual_page_entries)) {
                    $content .= "\n\nIncluded website pages:\n\n";
                    foreach ($manual_page_entries as $item) {
                        $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                    }
                }
                // Add website content entries (when crawler is on)
                if ($website_content_enabled && !empty($website_entries)) {
                    $content .= "\n\nAdditional website information:\n\n";
                    foreach ($website_entries as $item) {
                        $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                    }
                }
                $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
            } else {
                // Fallback: add a limited subset of manual entries
                $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $count = 0;
                foreach ($this->training_data as $item) {
                    if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                        $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                        $count++;
                        if ($count >= 5) {
                            break;
                        }
                    }
                }
                $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
                $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
            }
        } else {
            // No query: just add all manual and included website_content (if any)
            $content .= "\n\n--- START OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
            foreach ($this->training_data as $item) {
                if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                } elseif (!$website_content_enabled && in_array($item['source_id'], $manual_pages)) {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                } elseif ($website_content_enabled) {
                    $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
                }
            }
            $content .= "--- END OF YOUR COMPLETE KNOWLEDGE BASE ---\n\n";
            $content .= "REMINDER: You can ONLY answer questions using the information between the START and END markers above. Do not use any other knowledge.";
        }
        
        return $content;
    }
    
    /**
     * Determine if the question should be classified as unknown (lower threshold: only if no relevant entries)
     *
     * @param string $question The user's question
     * @return bool Whether the question should be treated as unknown
     */
    private function is_unknown_question($question) {
        if (empty($this->training_data)) {
            return true;
        }
        $relevant_data = $this->selective_context ? $this->get_relevant_training_data($question) : $this->training_data;
        // Lower threshold: only unknown if no relevant entries at all
        if (empty($relevant_data)) {
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
        error_log('WP GPT Chatbot: [DEBUG] Full prompt sent to OpenAI: ' . print_r($full_prompt, true));
        
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
        // Log the full API response for debugging
        error_log('WP GPT Chatbot: [DEBUG] OpenAI API response: ' . print_r($response, true));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($response_body['error'])) {
            throw new Exception($response_body['error']['message']);
        }
        
        $answer = $response_body['choices'][0]['message']['content'];
        
        // Fallback: If the answer is empty or only whitespace, treat as unknown
        if (trim($answer) === '') {
            error_log('WP GPT Chatbot: OpenAI API returned empty response, using unknown response fallback.');
            WP_GPT_Chatbot_Database_Manager::log_unknown_question($message);
            return $this->unknown_response;
        }
        
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
