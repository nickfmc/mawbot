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
    
    public function __construct() {
        $settings = get_option('wp_gpt_chatbot_settings');
        $this->api_key = $settings['api_key'];
        $this->model = $settings['model'];
        $this->training_prompt = $settings['training_prompt'];
        $this->training_data = isset($settings['training_data']) ? $settings['training_data'] : array();
        $this->unknown_response = isset($settings['unknown_response']) ? $settings['unknown_response'] : 'I don\'t have enough information to answer that question yet. Your question has been logged and our team will provide an answer soon.';
        
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
     * Generate training content from the saved Q&A pairs
     * 
     * @return string Formatted training content
     */
    private function generate_training_content() {
        $content = "";
        
        if (!empty($this->training_data)) {
            $content .= "\n\nHere are specific questions and answers to learn from:\n\n";
            
            foreach ($this->training_data as $item) {
                $content .= "Q: {$item['question']}\nA: {$item['answer']}\n\n";
            }
        }
        
        return $content;
    }
    
    /**
     * Determine if the question should be classified as unknown
     * This uses a second API call to evaluate confidence
     * 
     * @param string $question The user's question
     * @return bool Whether the question should be treated as unknown
     */
    private function is_unknown_question($question) {
        // If no training data exists, we can't confidently answer anything specific
        if (empty($this->training_data)) {
            return true;
        }
        
        $url = 'https://api.openai.com/v1/chat/completions';
        
        // Create a specialized prompt to evaluate confidence
        $evaluation_prompt = "You are an AI tasked with determining if a question can be answered confidently based on your training data. ";
        $evaluation_prompt .= "Respond with only 'yes' if you can confidently answer the question based on the training data provided, ";
        $evaluation_prompt .= "or 'no' if you don't have enough specific information to answer accurately.";
        
        $training_content = $this->generate_training_content();
        
        $messages = array(
            array(
                'role' => 'system',
                'content' => $evaluation_prompt . $training_content
            ),
            array(
                'role' => 'user',
                'content' => "Can I confidently answer this question based on my training data: {$question}"
            )
        );
        
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 10,
            'temperature' => 0.1 // Low temperature for more deterministic response
        );
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'body' => json_encode($body),
            'method' => 'POST',
            'timeout' => 15
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            // If the evaluation fails, err on the side of caution and treat as known
            return false;
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($response_body['error'])) {
            // If there's an API error, err on the side of caution
            return false;
        }
        
        $evaluation = strtolower(trim($response_body['choices'][0]['message']['content']));
        
        // Return true if the evaluation contains 'no'
        return strpos($evaluation, 'no') !== false;
    }
    
    /**
     * Send a message to the ChatGPT API and get a response
     * 
     * @param string $message The user's message
     * @param array $conversation_history Previous messages in the conversation
     * @return string The response from ChatGPT or unknown question message
     */
    private function send_to_chatgpt($message, $conversation_history) {
        // First check if this is an unknown question
        $is_unknown = $this->is_unknown_question($message);
        
        if ($is_unknown) {
            // Log the unknown question to the database
            WP_GPT_Chatbot_Database_Manager::log_unknown_question($message);
            
            // Return the unknown question response
            return $this->unknown_response;
        }
        
        // If we have confidence to answer, proceed with the normal API call
        $url = 'https://api.openai.com/v1/chat/completions';
        
        // Generate the full system prompt including training data
        $full_prompt = $this->training_prompt . $this->generate_training_content();
        
        // Prepare the conversation messages
        $messages = array(
            array(
                'role' => 'system',
                'content' => $full_prompt
            )
        );
        
        // Add conversation history
        foreach ($conversation_history as $entry) {
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
        
        return $response_body['choices'][0]['message']['content'];
    }
}
