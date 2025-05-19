<?php
/**
 * Unknown Questions Admin Page
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Load questions
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$questions = WP_GPT_Chatbot_Database_Manager::get_unknown_questions($status_filter);

// Handle form submissions
if (isset($_POST['action']) && $_POST['action'] === 'answer_question' && isset($_POST['question_id']) && isset($_POST['answer'])) {
    check_admin_referer('wp_gpt_chatbot_answer_question');
    
    $question_id = intval($_POST['question_id']);
    $answer = sanitize_textarea_field($_POST['answer']);
    
    if (WP_GPT_Chatbot_Database_Manager::update_question_answer($question_id, $answer, get_current_user_id())) {
        // Add answer to training material
        $settings = get_option('wp_gpt_chatbot_settings');
        $training_data = isset($settings['training_data']) ? $settings['training_data'] : array();
        
        $question_obj = WP_GPT_Chatbot_Database_Manager::get_question($question_id);
        
        if ($question_obj) {
            $training_data[] = array(
                'question' => $question_obj->question,
                'answer' => $answer,
                'added_at' => current_time('mysql')
            );
            
            $settings['training_data'] = $training_data;
            update_option('wp_gpt_chatbot_settings', $settings);
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Answer added successfully and added to training data.', 'wp-gpt-chatbot') . '</p></div>';
        }
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to update answer.', 'wp-gpt-chatbot') . '</p></div>';
    }
    
    // Reload questions
    $questions = WP_GPT_Chatbot_Database_Manager::get_unknown_questions($status_filter);
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['question_id'])) {
    check_admin_referer('delete_question_' . $_GET['question_id']);
    
    $question_id = intval($_GET['question_id']);
    
    if (WP_GPT_Chatbot_Database_Manager::delete_question($question_id)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Question deleted successfully.', 'wp-gpt-chatbot') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to delete question.', 'wp-gpt-chatbot') . '</p></div>';
    }
    
    // Reload questions
    $questions = WP_GPT_Chatbot_Database_Manager::get_unknown_questions($status_filter);
}
?>

<div class="wrap">
    <h1><?php echo esc_html__('Unknown Questions Log', 'wp-gpt-chatbot'); ?></h1>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <a href="?page=wp-gpt-chatbot-questions&status=all" class="button <?php echo $status_filter === 'all' ? 'button-primary' : ''; ?>"><?php echo esc_html__('All', 'wp-gpt-chatbot'); ?></a>
            <a href="?page=wp-gpt-chatbot-questions&status=pending" class="button <?php echo $status_filter === 'pending' ? 'button-primary' : ''; ?>"><?php echo esc_html__('Pending', 'wp-gpt-chatbot'); ?></a>
            <a href="?page=wp-gpt-chatbot-questions&status=answered" class="button <?php echo $status_filter === 'answered' ? 'button-primary' : ''; ?>"><?php echo esc_html__('Answered', 'wp-gpt-chatbot'); ?></a>
        </div>
        <br class="clear">
    </div>
    
    <?php if (empty($questions)): ?>
        <div class="notice notice-info">
            <p><?php echo esc_html__('No unknown questions found.', 'wp-gpt-chatbot'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Question', 'wp-gpt-chatbot'); ?></th>
                    <th scope="col"><?php echo esc_html__('Asked at', 'wp-gpt-chatbot'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'wp-gpt-chatbot'); ?></th>
                    <th scope="col"><?php echo esc_html__('Answer', 'wp-gpt-chatbot'); ?></th>
                    <th scope="col"><?php echo esc_html__('Actions', 'wp-gpt-chatbot'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?php echo esc_html($question->question); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($question->asked_at))); ?></td>
                        <td>
                            <?php if ($question->status === 'pending'): ?>
                                <span class="badge pending"><?php echo esc_html__('Pending', 'wp-gpt-chatbot'); ?></span>
                            <?php else: ?>
                                <span class="badge answered"><?php echo esc_html__('Answered', 'wp-gpt-chatbot'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($question->status === 'pending'): ?>
                                <button type="button" class="button answer-question" data-question-id="<?php echo esc_attr($question->id); ?>" data-question-text="<?php echo esc_attr($question->question); ?>"><?php echo esc_html__('Provide Answer', 'wp-gpt-chatbot'); ?></button>
                            <?php else: ?>
                                <?php echo esc_html($question->answer); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-gpt-chatbot-questions&action=delete&question_id=' . $question->id), 'delete_question_' . $question->id)); ?>" class="delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this question?', 'wp-gpt-chatbot')); ?>')"><?php echo esc_html__('Delete', 'wp-gpt-chatbot'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Answer Question Modal -->
<div id="answer-question-modal" class="wp-gpt-chatbot-modal" style="display: none;">
    <div class="wp-gpt-chatbot-modal-content">
        <span class="wp-gpt-chatbot-modal-close">&times;</span>
        <h2><?php echo esc_html__('Provide Answer', 'wp-gpt-chatbot'); ?></h2>
        <p id="question-text"></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-gpt-chatbot-questions')); ?>">
            <?php wp_nonce_field('wp_gpt_chatbot_answer_question'); ?>
            <input type="hidden" name="action" value="answer_question">
            <input type="hidden" name="question_id" id="question-id" value="">
            <textarea name="answer" rows="6" class="large-text" required></textarea>
            <p class="description"><?php echo esc_html__('This answer will be added to your chatbot\'s training data for future use.', 'wp-gpt-chatbot'); ?></p>
            <p>
                <button type="submit" class="button button-primary"><?php echo esc_html__('Save Answer', 'wp-gpt-chatbot'); ?></button>
                <button type="button" class="button wp-gpt-chatbot-modal-cancel"><?php echo esc_html__('Cancel', 'wp-gpt-chatbot'); ?></button>
            </p>
        </form>
    </div>
</div>

<style>
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 12px;
    }
    .badge.pending {
        background-color: #f0ad4e;
        color: #fff;
    }
    .badge.answered {
        background-color: #5cb85c;
        color: #fff;
    }
    
    /* Modal Styles */
    .wp-gpt-chatbot-modal {
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .wp-gpt-chatbot-modal-content {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        border: 1px solid #ddd;
        width: 50%;
        max-width: 500px;
        border-radius: 3px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .wp-gpt-chatbot-modal-close, .wp-gpt-chatbot-modal-cancel {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    .wp-gpt-chatbot-modal-close:hover {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }
</style>

<script>
jQuery(document).ready(function($) {
    // Open modal
    $('.answer-question').on('click', function() {
        var questionId = $(this).data('question-id');
        var questionText = $(this).data('question-text');
        
        $('#question-id').val(questionId);
        $('#question-text').text(questionText);
        $('#answer-question-modal').show();
    });
    
    // Close modal
    $('.wp-gpt-chatbot-modal-close, .wp-gpt-chatbot-modal-cancel').on('click', function() {
        $('#answer-question-modal').hide();
    });
    
    // Close on outside click
    $(window).on('click', function(event) {
        if ($(event.target).is('.wp-gpt-chatbot-modal')) {
            $('.wp-gpt-chatbot-modal').hide();
        }
    });
});
</script>
