<?php
/**
 * Admin Settings Page
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get settings directly from the database to bypass caching
global $wpdb;
$option_name = 'wp_gpt_chatbot_settings';
$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
$settings = $row ? maybe_unserialize($row->option_value) : array();

// Initialize training_data if it doesn't exist
if (!isset($settings['training_data']) || !is_array($settings['training_data'])) {
    $settings['training_data'] = array();
}

// Handle adding training material manually
if (isset($_POST['add_training_material']) && isset($_POST['training_question']) && isset($_POST['training_answer'])) {
    check_admin_referer('wp_gpt_chatbot_add_training');
    
    $question = sanitize_text_field($_POST['training_question']);
    $answer = sanitize_textarea_field($_POST['training_answer']);
    
    if (!empty($question) && !empty($answer)) {
        // Get the latest settings directly from database to bypass caching
        global $wpdb;
        $option_name = 'wp_gpt_chatbot_settings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
        $current_settings = $row ? maybe_unserialize($row->option_value) : array();
        
        // Make sure current_settings and training_data are arrays
        if (!is_array($current_settings)) {
            $current_settings = array();
        }
        
        if (!isset($current_settings['training_data']) || !is_array($current_settings['training_data'])) {
            $current_settings['training_data'] = array();
        }
        
        // Add the new training item
        $current_settings['training_data'][] = array(
            'question' => $question,
            'answer' => $answer,
            'added_at' => current_time('mysql'),
            'source_type' => 'manual'
        );
        
        // Update the option with the latest settings
        update_option('wp_gpt_chatbot_settings', $current_settings);
        
        // Update the settings variable for the current page display
        $settings = $current_settings;
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Training material added successfully.', 'wp-gpt-chatbot') . '</p></div>';
    }
}

// Handle removing training material
if (isset($_GET['action']) && $_GET['action'] === 'remove_training' && isset($_GET['index'])) {
    check_admin_referer('remove_training_' . $_GET['index']);
    
    $index = intval($_GET['index']);
    
    // Get the latest settings directly from database to bypass caching
    global $wpdb;
    $option_name = 'wp_gpt_chatbot_settings';
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
    $current_settings = $row ? maybe_unserialize($row->option_value) : array();
    
    // Make sure it's an array
    if (!is_array($current_settings)) {
        $current_settings = array();
    }
    
    if (isset($current_settings['training_data'][$index])) {
        array_splice($current_settings['training_data'], $index, 1);
        update_option('wp_gpt_chatbot_settings', $current_settings);
        
        // Update the settings variable for the current page display
        $settings = $current_settings;
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Training material removed successfully.', 'wp-gpt-chatbot') . '</p></div>';
    }
}

// Handle importing training material from CSV
if (isset($_POST['import_training']) && isset($_FILES['training_csv'])) {
    check_admin_referer('wp_gpt_chatbot_import_training');
    
    $file = $_FILES['training_csv'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($file['tmp_name'], 'r');
        $count = 0;
        
        if ($handle !== false) {
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 2 && !empty($data[0]) && !empty($data[1])) {
                    $settings['training_data'][] = array(
                        'question' => sanitize_text_field($data[0]),
                        'answer' => sanitize_textarea_field($data[1]),
                        'added_at' => current_time('mysql'),
                        'source_type' => 'import'
                    );
                    $count++;
                }
            }
            
            fclose($handle);
            
            if ($count > 0) {
                // Get the latest settings directly from database to bypass caching
                global $wpdb;
                $option_name = 'wp_gpt_chatbot_settings';
                $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
                $current_settings = $row ? maybe_unserialize($row->option_value) : array();
                
                // Make sure current_settings and training_data are arrays
                if (!is_array($current_settings)) {
                    $current_settings = array();
                }
                
                if (!isset($current_settings['training_data']) || !is_array($current_settings['training_data'])) {
                    $current_settings['training_data'] = array();
                }
                
                // Add the imported training items
                $current_settings['training_data'] = array_merge($current_settings['training_data'], $settings['training_data']);
                
                // Update the option with the latest settings
                update_option('wp_gpt_chatbot_settings', $current_settings);
                
                // Update the settings variable for the current page display
                $settings = $current_settings;
                
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Successfully imported %d training material entries.', 'wp-gpt-chatbot'), $count) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('No valid training material found in the CSV file.', 'wp-gpt-chatbot') . '</p></div>';
            }
        }
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Error uploading CSV file.', 'wp-gpt-chatbot') . '</p></div>';
    }
}
?>

<div class="wp-gpt-chatbot-settings-container">
    <form method="post" action="options.php">
        <?php settings_fields('wp_gpt_chatbot_options'); ?>
        <?php do_settings_sections('wp_gpt_chatbot_options'); ?>
        <h2><?php echo esc_html__('API Settings', 'wp-gpt-chatbot'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[api_key]"><?php echo esc_html__('OpenAI API Key', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="password" id="wp_gpt_chatbot_settings[api_key]" name="wp_gpt_chatbot_settings[api_key]" value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text">
                    <p class="description"><?php echo esc_html__('Enter your OpenAI API key. You can get one from https://platform.openai.com/account/api-keys', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[model]"><?php echo esc_html__('OpenAI Model', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <select id="wp_gpt_chatbot_settings[model]" name="wp_gpt_chatbot_settings[model]">
                        <option value="gpt-4.1-nano" <?php selected($settings['model'], 'gpt-4.1-nano'); ?>><?php echo esc_html__('GPT-4.1 Nano', 'wp-gpt-chatbot'); ?></option>
                        <option value="gpt-3.5-turbo" <?php selected($settings['model'], 'gpt-3.5-turbo'); ?>><?php echo esc_html__('GPT-3.5 Turbo', 'wp-gpt-chatbot'); ?></option>
                        <option value="gpt-4" <?php selected($settings['model'], 'gpt-4'); ?>><?php echo esc_html__('GPT-4', 'wp-gpt-chatbot'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('Select the OpenAI model to use. GPT-4.1 Nano is the recommended default, offering an excellent balance of performance and cost.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[training_prompt]"><?php echo esc_html__('System Prompt', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <textarea id="wp_gpt_chatbot_settings[training_prompt]" name="wp_gpt_chatbot_settings[training_prompt]" rows="5" class="large-text"><?php echo esc_textarea($settings['training_prompt']); ?></textarea>
                    <p class="description"><?php echo esc_html__('Enter your base system prompt. This sets the overall behavior and tone of your chatbot.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[unknown_response]"><?php echo esc_html__('Unknown Question Response', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <textarea id="wp_gpt_chatbot_settings[unknown_response]" name="wp_gpt_chatbot_settings[unknown_response]" rows="3" class="large-text"><?php echo esc_textarea(isset($settings['unknown_response']) ? $settings['unknown_response'] : 'I don\'t have enough information to answer that question yet. Your question has been logged and our team will provide an answer soon.'); ?></textarea>
                    <p class="description"><?php echo esc_html__('This message will be shown when the chatbot cannot confidently answer a question based on the training material.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[placeholder_suggestions]"><?php echo esc_html__('Input Placeholder Suggestions', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="text" id="wp_gpt_chatbot_settings[placeholder_suggestions]" name="wp_gpt_chatbot_settings[placeholder_suggestions]" value="<?php echo isset($settings['placeholder_suggestions']) ? esc_attr($settings['placeholder_suggestions']) : ''; ?>" class="regular-text" />
                    <p class="description"><?php echo esc_html__('Enter placeholder suggestions separated by a comma. Each will be animated in the input placeholder.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php echo esc_html__('Appearance Settings', 'wp-gpt-chatbot'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[bot_name]"><?php echo esc_html__('Bot Name', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="text" id="wp_gpt_chatbot_settings[bot_name]" name="wp_gpt_chatbot_settings[bot_name]" value="<?php echo esc_attr($settings['bot_name']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[welcome_message]"><?php echo esc_html__('Welcome Message', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="text" id="wp_gpt_chatbot_settings[welcome_message]" name="wp_gpt_chatbot_settings[welcome_message]" value="<?php echo esc_attr($settings['welcome_message']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[primary_color]"><?php echo esc_html__('Primary Color', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="color" id="wp_gpt_chatbot_settings[primary_color]" name="wp_gpt_chatbot_settings[primary_color]" value="<?php echo esc_attr($settings['primary_color']); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[secondary_color]"><?php echo esc_html__('Secondary Color', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="color" id="wp_gpt_chatbot_settings[secondary_color]" name="wp_gpt_chatbot_settings[secondary_color]" value="<?php echo esc_attr($settings['secondary_color']); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[position]"><?php echo esc_html__('Widget Position', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <select id="wp_gpt_chatbot_settings[position]" name="wp_gpt_chatbot_settings[position]">
                        <option value="bottom-right" <?php selected($settings['position'], 'bottom-right'); ?>><?php echo esc_html__('Bottom Right', 'wp-gpt-chatbot'); ?></option>
                        <option value="bottom-left" <?php selected($settings['position'], 'bottom-left'); ?>><?php echo esc_html__('Bottom Left', 'wp-gpt-chatbot'); ?></option>
                        <option value="none" <?php selected($settings['position'], 'none'); ?>><?php echo esc_html__('No Widget (Shortcode Only)', 'wp-gpt-chatbot'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('Choose "No Widget" if you only want to use the shortcode and not display the floating widget.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php echo esc_html__('Token Usage & Performance Settings', 'wp-gpt-chatbot'); ?></h2>
        <p class="description"><?php echo esc_html__('Configure settings to optimize token usage and improve performance when using OpenAI API.', 'wp-gpt-chatbot'); ?></p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[enable_caching]"><?php echo esc_html__('Enable Response Caching', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="wp_gpt_chatbot_settings[enable_caching]" name="wp_gpt_chatbot_settings[enable_caching]" value="1" <?php checked(isset($settings['enable_caching']) && $settings['enable_caching']); ?>>
                    <p class="description"><?php echo esc_html__('Cache responses to save tokens on repeated questions.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[cache_expiration]"><?php echo esc_html__('Cache Expiration (seconds)', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="number" id="wp_gpt_chatbot_settings[cache_expiration]" name="wp_gpt_chatbot_settings[cache_expiration]" value="<?php echo esc_attr(isset($settings['cache_expiration']) ? $settings['cache_expiration'] : 604800); ?>" class="regular-text">
                    <p class="description"><?php echo esc_html__('How long to keep cached responses (default: 604800 = 1 week).', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[conversation_memory]"><?php echo esc_html__('Conversation Memory', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="number" id="wp_gpt_chatbot_settings[conversation_memory]" name="wp_gpt_chatbot_settings[conversation_memory]" value="<?php echo esc_attr(isset($settings['conversation_memory']) ? $settings['conversation_memory'] : 5); ?>" min="1" max="20" class="regular-text">
                    <p class="description"><?php echo esc_html__('Number of previous messages to remember in conversation history (1-20). Lower values use fewer tokens.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[selective_context]"><?php echo esc_html__('Enable Selective Context', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="wp_gpt_chatbot_settings[selective_context]" name="wp_gpt_chatbot_settings[selective_context]" value="1" <?php checked(isset($settings['selective_context']) && $settings['selective_context']); ?>>
                    <p class="description"><?php echo esc_html__('Only send relevant training content based on the user\'s question. Saves tokens by reducing context size.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wp_gpt_chatbot_settings[show_related_content]"><?php echo esc_html__('Show Related Content Links', 'wp-gpt-chatbot'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="wp_gpt_chatbot_settings[show_related_content]" name="wp_gpt_chatbot_settings[show_related_content]" value="1" <?php checked(isset($settings['show_related_content']) && $settings['show_related_content']); ?>>
                    <p class="description"><?php echo esc_html__('Append suggested related content links at the end of chatbot responses.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__('Manage Cache', 'wp-gpt-chatbot'); ?>
                </th>
                <td>
                    <button type="button" id="clear-cache-button" class="button button-secondary"><?php echo esc_html__('Clear Response Cache', 'wp-gpt-chatbot'); ?></button>
                    <span id="cache-status-message"></span>
                    <p class="description"><?php echo esc_html__('Clear all cached responses. Use this if you\'ve updated training content or settings.', 'wp-gpt-chatbot'); ?></p>
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            $('#clear-cache-button').on('click', function() {
                var button = $(this);
                var statusMessage = $('#cache-status-message');
                
                button.prop('disabled', true);
                statusMessage.text('<?php echo esc_js(__('Clearing cache...', 'wp-gpt-chatbot')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_gpt_chatbot_clear_cache',
                        nonce: '<?php echo wp_create_nonce('wp_gpt_chatbot_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusMessage.text(response.data.message);
                            setTimeout(function() {
                                statusMessage.text('');
                            }, 3000);
                        } else {
                            statusMessage.text(response.data.message);
                        }
                    },
                    error: function() {
                        statusMessage.text('<?php echo esc_js(__('Error clearing cache.', 'wp-gpt-chatbot')); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        
        <?php submit_button(__('Save Settings', 'wp-gpt-chatbot')); ?>
    </form>
    
    <h2><?php echo esc_html__('Training Material Management', 'wp-gpt-chatbot'); ?></h2>
        <p class="description"><?php echo esc_html__('Add question-answer pairs to train your chatbot with specific knowledge. The more specific entries you add, the better your chatbot will become at answering related questions.', 'wp-gpt-chatbot'); ?></p>
        
        <!-- Add Training Material Form -->
        <div class="wp-gpt-chatbot-card">
            <h3><?php echo esc_html__('Add New Training Material', 'wp-gpt-chatbot'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-gpt-chatbot')); ?>">
                <?php wp_nonce_field('wp_gpt_chatbot_add_training'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="training_question"><?php echo esc_html__('Question', 'wp-gpt-chatbot'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="training_question" name="training_question" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="training_answer"><?php echo esc_html__('Answer', 'wp-gpt-chatbot'); ?></label>
                        </th>
                        <td>
                            <textarea id="training_answer" name="training_answer" rows="4" class="large-text" required></textarea>
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="add_training_material" class="button button-primary" value="<?php echo esc_attr__('Add Training Material', 'wp-gpt-chatbot'); ?>">
                </p>
            </form>
        </div>
        
        <!-- Import Training Material -->
        <div class="wp-gpt-chatbot-card">
            <h3><?php echo esc_html__('Import Training Material from CSV', 'wp-gpt-chatbot'); ?></h3>
            <p class="description"><?php echo esc_html__('Upload a CSV file with columns: Question, Answer. First row should be the header.', 'wp-gpt-chatbot'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-gpt-chatbot')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wp_gpt_chatbot_import_training'); ?>
                <input type="file" name="training_csv" accept=".csv" required>
                <p>
                    <input type="submit" name="import_training" class="button button-secondary" value="<?php echo esc_attr__('Import CSV', 'wp-gpt-chatbot'); ?>">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-gpt-chatbot-questions')); ?>" class="button"><?php echo esc_html__('Manage Unknown Questions', 'wp-gpt-chatbot'); ?></a>
                </p>
            </form>
        </div>
        
        <!-- Training Material Table -->
        <div class="wp-gpt-chatbot-card">
            <h3><?php echo esc_html__('Existing Training Material', 'wp-gpt-chatbot'); ?></h3>
            <?php 
            // Count manual training entries vs website content entries
            $manual_entries = 0;
            $website_entries = 0;
            foreach ($settings['training_data'] as $item) {
                if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
                    $website_entries++;
                } else {
                    $manual_entries++;
                }
            }
            
            $total_entries = count($settings['training_data']);
            ?>
            
            <?php if ($website_entries > 0) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php echo sprintf(
                            esc_html__('You have a total of %d training entries (%d manual, %d from website content). Website content entries are managed in the Website Content tab.', 'wp-gpt-chatbot'),
                            $total_entries,
                            $manual_entries,
                            $website_entries
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <?php
            // Filter to only show manual entries in this table
            $manual_training_data = array();
            foreach ($settings['training_data'] as $index => $item) {
                if (!isset($item['source_type']) || $item['source_type'] !== 'website_content') {
                    $item['original_index'] = $index;
                    $manual_training_data[] = $item;
                }
            }
            
            if (empty($manual_training_data)):
            ?>
                <p><?php echo esc_html__('No manual training material entries found.', 'wp-gpt-chatbot'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Question', 'wp-gpt-chatbot'); ?></th>
                            <th><?php echo esc_html__('Answer', 'wp-gpt-chatbot'); ?></th>
                            <th><?php echo esc_html__('Added', 'wp-gpt-chatbot'); ?></th>
                            <th><?php echo esc_html__('Source', 'wp-gpt-chatbot'); ?></th>
                            <th><?php echo esc_html__('Actions', 'wp-gpt-chatbot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manual_training_data as $item): 
                            $source = isset($item['source_type']) ? $item['source_type'] : 'manual';
                            $source_display = $source === 'import' ? 'CSV Import' : 'Manual Entry';
                        ?>
                            <tr>
                                <td><?php echo esc_html($item['question']); ?></td>
                                <td><?php echo esc_html(substr($item['answer'], 0, 100) . (strlen($item['answer']) > 100 ? '...' : '')); ?></td>
                                <td><?php echo isset($item['added_at']) ? esc_html(date_i18n(get_option('date_format'), strtotime($item['added_at']))) : '-'; ?></td>
                                <td><?php echo esc_html($source_display); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-gpt-chatbot&action=remove_training&index=' . $item['original_index']), 'remove_training_' . $item['original_index'])); ?>" class="button-link-delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to remove this training material?', 'wp-gpt-chatbot')); ?>')"><?php echo esc_html__('Remove', 'wp-gpt-chatbot'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
            .wp-gpt-chatbot-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 15px;
                margin-bottom: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            .wp-gpt-chatbot-card h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
        </style>
    </div>
</div>
