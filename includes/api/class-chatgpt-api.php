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
        $start_time = microtime(true);
        
        // Check cache first
        require_once WP_GPT_CHATBOT_PATH . 'includes/class-cache-manager.php';
        $cached_response = WP_GPT_Chatbot_Cache_Manager::get_cached_response($message);
        
        if ($cached_response !== false) {
            // Log cached response if logging is enabled
            $settings = get_option('wp_gpt_chatbot_settings');
            if (isset($settings['enable_question_logging']) && $settings['enable_question_logging']) {
                $response_time = microtime(true) - $start_time;
                WP_GPT_Chatbot_Database_Manager::log_question($message, $cached_response, $response_time, true);
            }
            // error_log('WP GPT Chatbot: Returning cached response: "' . $cached_response . '"');
            return $cached_response;
        }
        
        // Check if this is an unknown question
        $is_unknown = $this->is_unknown_question($message);
        
        if ($is_unknown) {
            // Log the unknown question to the database
            WP_GPT_Chatbot_Database_Manager::log_unknown_question($message);
            // Debug: Log the unknown response value
            // error_log('WP GPT Chatbot: Unknown question detected: ' . $message);
            // error_log('WP GPT Chatbot: Unknown response: ' . $this->unknown_response);
            // Ensure we have a fallback response if the setting is empty
            $response = !empty($this->unknown_response) ? $this->unknown_response : 'I don\'t have that specific information in my knowledge base. Please contact us directly for assistance.';
            
            // Log the unknown response if logging is enabled
            $settings = get_option('wp_gpt_chatbot_settings');
            if (isset($settings['enable_question_logging']) && $settings['enable_question_logging']) {
                $response_time = microtime(true) - $start_time;
                WP_GPT_Chatbot_Database_Manager::log_question($message, $response, $response_time, false);
            }
            
            // Return the unknown question response
            return $response;
        }
        
        // If we have confidence to answer, proceed with the API call
        $url = 'https://api.openai.com/v1/chat/completions';
        
        // Generate the full system prompt with relevant training data
        $full_prompt = $this->training_prompt . $this->generate_training_content($message);
        // error_log('WP GPT Chatbot: [DEBUG] Full prompt sent to OpenAI: ' . print_r($full_prompt, true));
        
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
        // error_log('WP GPT Chatbot: [DEBUG] OpenAI API response: ' . print_r($response, true));
        
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
            // error_log('WP GPT Chatbot: OpenAI API returned empty response, using unknown response fallback.');
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
        
        // Get related content suggestions to append to the response
        $settings = get_option('wp_gpt_chatbot_settings');
        $show_related_content = isset($settings['show_related_content']) ? $settings['show_related_content'] : true;
        
        if ($show_related_content) {
            $used_entries = $this->selective_context ? $this->get_relevant_training_data($message) : array();
            $related_suggestions = $this->get_related_content_suggestions($message, $used_entries);
            
            // Append related content suggestions if available
            if (!empty($related_suggestions)) {
                $answer .= "\n\n**For more information, you might find these helpful:**\n";
                foreach ($related_suggestions as $suggestion) {
                    $answer .= "• [{$suggestion['title']}]({$suggestion['url']})\n";
                }
            }
        }
        
        // Cache the response for future use
        $settings = get_option('wp_gpt_chatbot_settings');
        if (isset($settings['enable_caching']) && $settings['enable_caching']) {
            // Get the expiration time from settings
            $expiration = isset($settings['cache_expiration']) ? intval($settings['cache_expiration']) : 604800; // Default 1 week
            
            WP_GPT_Chatbot_Cache_Manager::cache_response($message, $answer, $expiration);
        }
        
        // Log the question if logging is enabled
        if (isset($settings['enable_question_logging']) && $settings['enable_question_logging']) {
            $response_time = isset($start_time) ? (microtime(true) - $start_time) : 0;
            $was_cached = ($cached_response !== false);
            WP_GPT_Chatbot_Database_Manager::log_question($message, $answer, $response_time, $was_cached);
        }
        
        return $answer;
    }
    
    /**
     * Get related content suggestions based on the user's query
     * 
     * @param string $query The user's query
     * @param array $used_entries Training data entries already used in the response
     * @return array Array of related content suggestions with titles and URLs
     */
    private function get_related_content_suggestions($query, $used_entries = array()) {
        $suggestions = array();
        $used_source_ids = array();
        $prioritized_source_ids = array();
        
        // Extract source IDs from used entries - these should be PRIORITIZED, not excluded
        foreach ($used_entries as $entry) {
            if (isset($entry['source_id']) && isset($entry['source_url']) && !empty($entry['source_url'])) {
                $prioritized_source_ids[] = $entry['source_id'];
            }
        }
        
        // Get all website content entries
        $website_content = array();
        foreach ($this->training_data as $item) {
            if (isset($item['source_type']) && $item['source_type'] === 'website_content' 
                && isset($item['source_url']) && !empty($item['source_url'])
                && isset($item['source_id'])) {
                
                $website_content[] = $item;
            }
        }
        
        // If we have website content, score it for relevance
        if (!empty($website_content)) {
            $query_lower = strtolower($query);
            
            // Enhanced keyword analysis
            $query_words = $this->extract_meaningful_words($query_lower);
            $question_intent = $this->analyze_question_intent($query_lower);
            
            $scored_content = array();
            foreach ($website_content as $item) {
                $score = $this->calculate_content_relevance_score($item, $query_lower, $query_words, $question_intent);
                
                // BOOST score significantly if this page was used in generating the response
                if (in_array($item['source_id'], $prioritized_source_ids)) {
                    $score += 50; // Major boost for pages that were actually relevant enough to use
                }
                
                if ($score > 0) {
                    $scored_content[] = array('item' => $item, 'score' => $score);
                }
            }
            
            // Sort by score (highest first)
            usort($scored_content, function($a, $b) { return $b['score'] - $a['score']; });
            
            // Lower minimum threshold since we want to show the actually relevant pages
            $min_score_threshold = 1;
            
            // Get unique pages (avoid multiple entries for the same page)
            $added_pages = array();
            foreach ($scored_content as $content) {
                if ($content['score'] < $min_score_threshold) {
                    break; // Stop when scores get too low
                }
                
                $item = $content['item'];
                
                if (!in_array($item['source_id'], $added_pages)) {
                    // Extract page title
                    $title = $this->extract_page_title($item);
                    
                    if (!empty($title)) {
                        $suggestions[] = array(
                            'title' => $title,
                            'url' => $item['source_url'],
                            'source_id' => $item['source_id'],
                            'relevance_score' => $content['score'] // For debugging
                        );
                        
                        $added_pages[] = $item['source_id'];
                        
                        // Limit to 3 suggestions total
                        if (count($suggestions) >= 3) {
                            break;
                        }
                    }
                }
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Extract meaningful words from query, filtering out stop words and short words
     */
    private function extract_meaningful_words($query_lower) {
        $stop_words = array('the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'can', 'may', 'might', 'must', 'shall', 'a', 'an', 'what', 'how', 'when', 'where', 'why', 'who', 'which');
        
        $words = preg_split('/[^a-z0-9]+/', $query_lower);
        $meaningful_words = array();
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stop_words)) {
                $meaningful_words[] = $word;
            }
        }
        
        return $meaningful_words;
    }
    
    /**
     * Analyze the intent behind a question to improve content matching
     */
    private function analyze_question_intent($query_lower) {
        $intent_patterns = array(
            'contact' => array('contact', 'reach', 'phone', 'email', 'address', 'location', 'office', 'call', 'speak', 'talk', 'get in touch', 'reach out', 'contact us', 'how to contact', 'how do i contact', 'where to contact'),
            'services' => array('services', 'offer', 'provide', 'do', 'specialize', 'expertise', 'capabilities', 'solutions'),
            'about' => array('about', 'who', 'company', 'team', 'history', 'founded', 'background', 'mission', 'vision'),
            'pricing' => array('cost', 'price', 'pricing', 'rates', 'fees', 'budget', 'expensive', 'cheap', 'affordable'),
            'process' => array('process', 'how', 'workflow', 'methodology', 'approach', 'steps', 'procedure'),
            'portfolio' => array('work', 'portfolio', 'examples', 'case studies', 'case study', 'projects', 'clients', 'results', 'success story', 'success stories', 'achievements', 'accomplishments', 'best work', 'showcase', 'previous work', 'past work', 'client work', 'sample work'),
            'industries' => array('industry', 'industries', 'sector', 'healthcare', 'technology', 'b2b', 'market'),
            'career' => array('jobs', 'career', 'hiring', 'employment', 'positions', 'work here', 'join'),
            'privacy' => array('privacy', 'data', 'gdpr', 'policy', 'security', 'confidential'),
            'legal' => array('legal', 'terms', 'conditions', 'disclaimer', 'compliance')
        );
        
        $detected_intents = array();
        foreach ($intent_patterns as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query_lower, $keyword) !== false) {
                    $detected_intents[] = $intent;
                    break;
                }
            }
        }
        
        return array_unique($detected_intents);
    }
    
    /**
     * Calculate relevance score for content based on query, keywords, and intent
     */
    private function calculate_content_relevance_score($item, $query_lower, $query_words, $question_intents) {
        $score = 0;
        $content_text = strtolower($item['question'] . ' ' . $item['answer']);
        $page_url = strtolower($item['source_url'] ?? '');
        
        // Get page title and content for additional context
        $page_title = '';
        $page_content = '';
        if (isset($item['source_id'])) {
            $post = get_post($item['source_id']);
            if ($post) {
                $page_title = strtolower($post->post_title);
                $page_content = strtolower(wp_strip_all_tags($post->post_content));
            }
        }
        
        $all_content = $content_text . ' ' . $page_title . ' ' . $page_content . ' ' . $page_url;
        
        // 1. Exact phrase matching (highest weight)
        if (strlen($query_lower) > 5 && strpos($all_content, $query_lower) !== false) {
            $score += 10;
        }
        
        // 2. Keyword matching with different weights
        foreach ($query_words as $word) {
            $word_score = 0;
            
            // Higher score for matches in title
            if (strpos($page_title, $word) !== false) {
                $word_score += 3;
            }
            
            // Medium score for matches in URL
            if (strpos($page_url, $word) !== false) {
                $word_score += 2;
            }
            
            // Base score for matches in content
            if (strpos($all_content, $word) !== false) {
                $word_score += 1;
            }
            
            $score += $word_score;
        }
        
        // 3. Intent-based matching (very high weight for relevant pages)
        foreach ($question_intents as $intent) {
            $intent_score = 0;
            
            switch ($intent) {
                case 'contact':
                    // Very high score for exact contact URL matches
                    if (strpos($page_url, '/contact/') !== false || strpos($page_url, '/contact') !== false) {
                        $intent_score = 25; // Extremely high for exact contact page
                    } elseif (strpos($page_title, 'contact') !== false) {
                        $intent_score = 20; // High for contact in title
                    } elseif (preg_match('/phone|email|address|office|location/i', $all_content)) {
                        $intent_score = 8;
                    }
                    break;
                    
                case 'about':
                    if (strpos($page_url, '/about/') !== false || strpos($page_url, '/about') !== false) {
                        $intent_score = 20;
                    } elseif (strpos($page_title, 'about') !== false) {
                        $intent_score = 15;
                    } elseif (preg_match('/team|company|history|mission|vision/i', $all_content)) {
                        $intent_score = 8;
                    }
                    break;
                    
                case 'services':
                    if (strpos($page_url, '/services/') !== false || strpos($page_url, '/services') !== false) {
                        $intent_score = 20;
                    } elseif (strpos($page_title, 'services') !== false) {
                        $intent_score = 15;
                    } elseif (preg_match('/expertise|solutions|capabilities|offer/i', $all_content)) {
                        $intent_score = 8;
                    }
                    break;
                    
                case 'privacy':
                    if (strpos($page_url, '/privacy') !== false || strpos($page_title, 'privacy') !== false) {
                        $intent_score = 25; // Very high for exact matches
                    }
                    break;
                    
                case 'career':
                    if (preg_match('/\/career|\/jobs|\/hiring|\/employment/i', $page_url) || preg_match('/career|jobs|hiring|employment/i', $page_title)) {
                        $intent_score = 20;
                    }
                    break;
                    
                case 'portfolio':
                    if (strpos($page_url, '/work/') !== false || strpos($page_url, '/work') !== false) {
                        $intent_score = 25; // Very high for work page
                    } elseif (preg_match('/\/portfolio|\/case-studies|\/projects|\/case_studies/i', $page_url)) {
                        $intent_score = 25; // Very high for portfolio/case studies pages
                    } elseif (preg_match('/portfolio|work|case studies|projects|success stor/i', $page_title)) {
                        $intent_score = 20; // High for relevant titles
                    } elseif (preg_match('/client|results|achievement|example|showcase/i', $all_content)) {
                        $intent_score = 10; // Medium for related content
                    }
                    break;
            }
            
            $score += $intent_score;
        }
        
        // 4. Penalize very short content that might not be useful
        if (strlen($content_text) < 50) {
            $score = max(0, $score - 2);
        }
        
        // 5. Bonus for pages that are likely to be high-value landing pages
        if (preg_match('/\/(contact|about|services|portfolio)(?:\/|$)/i', $page_url)) {
            $score += 5; // Bonus for main navigation pages
        }
        
        return $score;
    }
    
    /**
     * Extract a meaningful title for a page
     */
    private function extract_page_title($item) {
        $title = '';
        
        // Try to get the actual page title first
        if (isset($item['source_id'])) {
            $post = get_post($item['source_id']);
            if ($post) {
                $title = $post->post_title;
            }
        }
        
        // Fallback: extract title from question patterns
        if (empty($title)) {
            if (preg_match("/What does the .* '(.*)' say\?/", $item['question'], $matches)) {
                $title = $matches[1];
            } elseif (preg_match("/Tell me about (.*?)( \(Part \d+\))?$/", $item['question'], $matches)) {
                $title = $matches[1];
            } elseif (preg_match("/What information is on .+?\?/", $item['question'])) {
                // Try to extract from URL
                $url_path = parse_url($item['source_url'], PHP_URL_PATH);
                if ($url_path) {
                    $title = ucwords(str_replace(array('-', '_', '/'), ' ', trim($url_path, '/')));
                }
            }
        }
        
        return $title;
    }
}
