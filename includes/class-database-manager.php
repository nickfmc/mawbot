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
     * Delete a question
     * 
     * @param int $id Question ID
     * @return bool True on success, false on failure
     */
    public static function delete_question($id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gpt_chatbot_unknown_questions';
        
        $result = $wpdb->delete(
            $table_name,
            array('id' => $id),
            array('%d')
        );
        
        return $result !== false;
    }
}
