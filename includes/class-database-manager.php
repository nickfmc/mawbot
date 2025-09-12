<?php
/**
 * Database Manager Class
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_GPT_Chatbot_Database_Manager {
    
    /**
     * Create database tables on plugin activation
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Unknown questions table
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            asked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            answer text,
            answered_at datetime,
            answered_by bigint(20),
            status varchar(20) DEFAULT 'pending' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Question logs table
        $logs_table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        $logs_sql = "CREATE TABLE $logs_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            response text,
            user_ip varchar(45),
            user_agent text,
            asked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            response_time float,
            was_cached tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY asked_at (asked_at)
        ) $charset_collate;";
        
        dbDelta($logs_sql);
    }
    
    /**
     * Log an unknown question to the database
     * 
     * @param string $question The question that couldn't be answered
     * @return int|false The ID of the inserted record, or false on failure
     */
    public static function log_unknown_question($question) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'question' => $question,
                'status' => 'pending'
            ),
            array('%s', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get all unknown questions
     * 
     * @param string $status Filter by status (pending, answered, all)
     * @return array Array of questions
     */
    public static function get_unknown_questions($status = 'all') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        $sql = "SELECT * FROM $table_name";
        
        if ($status !== 'all') {
            $sql .= $wpdb->prepare(" WHERE status = %s", $status);
        }
        
        $sql .= " ORDER BY asked_at DESC";
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Update an unknown question with an answer
     * 
     * @param int $id Question ID
     * @param string $answer The answer to the question
     * @param int $user_id ID of the user providing the answer
     * @return bool True on success, false on failure
     */
    public static function update_question_answer($id, $answer, $user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'answer' => $answer,
                'answered_at' => current_time('mysql'),
                'answered_by' => $user_id,
                'status' => 'answered'
            ),
            array('id' => $id),
            array('%s', '%s', '%d', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Get a single question by ID
     * 
     * @param int $id Question ID
     * @return object|null Question object or null if not found
     */
    public static function get_question($id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    }
    
    /**
     * Log a question to the question logs table
     * 
     * @param string $question The question asked
     * @param string $response The response given
     * @param float $response_time Response time in seconds
     * @param bool $was_cached Whether the response was cached
     * @return int|false The ID of the inserted record, or false on failure
     */
    public static function log_question($question, $response = '', $response_time = 0, $was_cached = false) {
        global $wpdb;
        
        $settings = get_option('wp_gpt_chatbot_settings');
        if (!isset($settings['enable_question_logging']) || !$settings['enable_question_logging']) {
            return false; // Logging is disabled
        }
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'question' => sanitize_text_field($question),
                'response' => sanitize_textarea_field($response),
                'user_ip' => self::get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'asked_at' => current_time('mysql'),
                'response_time' => floatval($response_time),
                'was_cached' => $was_cached ? 1 : 0
            ),
            array('%s', '%s', '%s', '%s', '%s', '%f', '%d')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get all question logs with optional filtering
     * 
     * @param int $limit Number of records to return
     * @param int $offset Offset for pagination
     * @param string $search Search term to filter questions
     * @return array Array of question log objects
     */
    public static function get_question_logs($limit = 100, $offset = 0, $search = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        $where_clause = '';
        $params = array();
        
        if (!empty($search)) {
            $where_clause = "WHERE question LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $params[] = intval($limit);
        $params[] = intval($offset);
        
        $sql = "SELECT * FROM $table_name $where_clause ORDER BY asked_at DESC LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get question logs count
     * 
     * @param string $search Search term to filter questions
     * @return int Total number of question logs
     */
    public static function get_question_logs_count($search = '') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        $where_clause = '';
        $params = array();
        
        if (!empty($search)) {
            $where_clause = "WHERE question LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $sql = "SELECT COUNT(*) FROM $table_name $where_clause";
        
        if (!empty($params)) {
            return $wpdb->get_var($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_var($sql);
        }
    }
    
    /**
     * Delete all question logs
     * 
     * @return bool True on success, false on failure
     */
    public static function clear_question_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_question_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        return $result !== false;
    }
    
    /**
     * Get user IP address
     * 
     * @return string User IP address
     */
    private static function get_user_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
