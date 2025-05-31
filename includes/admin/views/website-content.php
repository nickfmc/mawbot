<?php
/**
 * Website Content Settings Admin View
 *
 * @package WP_GPT_Chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get website content settings
$settings = get_option('wp_gpt_chatbot_settings');
$content_settings = isset($settings['website_content']) ? $settings['website_content'] : array();

// Default values
$enabled = isset($content_settings['enabled']) ? (bool) $content_settings['enabled'] : false;
$auto_refresh = isset($content_settings['auto_refresh']) ? (bool) $content_settings['auto_refresh'] : false;
$refresh_frequency = isset($content_settings['refresh_frequency']) ? $content_settings['refresh_frequency'] : 'daily';
$selected_post_types = isset($content_settings['post_types']) && is_array($content_settings['post_types']) 
    ? $content_settings['post_types'] 
    : array('page');
$selected_categories = isset($content_settings['categories']) && is_array($content_settings['categories']) 
    ? $content_settings['categories'] 
    : array();
$selected_tags = isset($content_settings['tags']) && is_array($content_settings['tags']) 
    ? $content_settings['tags'] 
    : array();
$excluded_pages = isset($content_settings['excluded_pages']) && is_array($content_settings['excluded_pages']) 
    ? $content_settings['excluded_pages'] 
    : array();

// Get all available post types
$post_types = WP_GPT_Chatbot_Content_Crawler::get_available_post_types();

// Get all available categories
$categories = WP_GPT_Chatbot_Content_Crawler::get_available_categories();

// Get all available tags
$tags = WP_GPT_Chatbot_Content_Crawler::get_available_tags();

// Count training data from website content
$website_training_count = 0;
if (isset($settings['training_data']) && is_array($settings['training_data'])) {
    foreach ($settings['training_data'] as $item) {
        if (isset($item['source_type']) && $item['source_type'] === 'website_content') {
            $website_training_count++;
        }
    }
}

?>

<div class="wrap">
    <h1><?php echo esc_html__('Website Content Settings', 'wp-gpt-chatbot'); ?></h1>
    
    <div class="wp-gpt-chatbot-card">
        <h2><?php echo esc_html__('Website Content Crawler', 'wp-gpt-chatbot'); ?></h2>
        <p class="description">
            <?php echo esc_html__('The content crawler allows the chatbot to use your website content as knowledge. It will automatically convert your pages and posts into training data.', 'wp-gpt-chatbot'); ?>
        </p>
        
        <form method="post" action="options.php" id="website-content-form">
            <?php settings_fields('wp_gpt_chatbot_options'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_gpt_chatbot_settings[website_content][enabled]"><?php echo esc_html__('Enable Content Crawler', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wp_gpt_chatbot_settings[website_content][enabled]" name="wp_gpt_chatbot_settings[website_content][enabled]" value="1" <?php checked($enabled, true); ?>>
                        <p class="description"><?php echo esc_html__('When enabled, the chatbot will use your website content to answer questions.', 'wp-gpt-chatbot'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_gpt_chatbot_settings[website_content][auto_refresh]"><?php echo esc_html__('Auto-Refresh Content', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wp_gpt_chatbot_settings[website_content][auto_refresh]" name="wp_gpt_chatbot_settings[website_content][auto_refresh]" value="1" <?php checked($auto_refresh, true); ?> <?php disabled($enabled, false); ?>>
                        <p class="description"><?php echo esc_html__('When enabled, the website content will be automatically refreshed on a schedule.', 'wp-gpt-chatbot'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_gpt_chatbot_settings[website_content][refresh_frequency]"><?php echo esc_html__('Refresh Frequency', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <select id="wp_gpt_chatbot_settings[website_content][refresh_frequency]" name="wp_gpt_chatbot_settings[website_content][refresh_frequency]" <?php disabled($enabled && $auto_refresh, false); ?>>
                            <option value="hourly" <?php selected($refresh_frequency, 'hourly'); ?>><?php echo esc_html__('Hourly', 'wp-gpt-chatbot'); ?></option>
                            <option value="twicedaily" <?php selected($refresh_frequency, 'twicedaily'); ?>><?php echo esc_html__('Twice Daily', 'wp-gpt-chatbot'); ?></option>
                            <option value="daily" <?php selected($refresh_frequency, 'daily'); ?>><?php echo esc_html__('Daily', 'wp-gpt-chatbot'); ?></option>
                            <option value="weekly" <?php selected($refresh_frequency, 'weekly'); ?>><?php echo esc_html__('Weekly', 'wp-gpt-chatbot'); ?></option>
                        </select>
                        <p class="description"><?php echo esc_html__('How often to automatically refresh website content.', 'wp-gpt-chatbot'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label><?php echo esc_html__('Content Types', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php echo esc_html__('Content Types', 'wp-gpt-chatbot'); ?></legend>
                            <?php foreach ($post_types as $post_type) : ?>
                                <label>
                                    <input type="checkbox" name="wp_gpt_chatbot_settings[website_content][post_types][]" value="<?php echo esc_attr($post_type->name); ?>" 
                                        <?php checked(in_array($post_type->name, $selected_post_types), true); ?>>
                                    <?php echo esc_html($post_type->labels->name); ?>
                                </label><br>
                            <?php endforeach; ?>
                            <p class="description"><?php echo esc_html__('Select which content types to include in the chatbot knowledge base.', 'wp-gpt-chatbot'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                
                <?php if (!empty($categories)) : ?>
                <tr>
                    <th scope="row">
                        <label><?php echo esc_html__('Categories', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php echo esc_html__('Categories', 'wp-gpt-chatbot'); ?></legend>
                            <p>
                                <label>
                                    <input type="checkbox" id="select-all-categories" class="select-all">
                                    <?php echo esc_html__('Select All', 'wp-gpt-chatbot'); ?>
                                </label>
                            </p>
                            <div class="wp-gpt-chatbot-checkbox-columns">
                                <?php foreach ($categories as $category) : ?>
                                    <label>
                                        <input type="checkbox" name="wp_gpt_chatbot_settings[website_content][categories][]" value="<?php echo esc_attr($category->term_id); ?>" 
                                            <?php checked(in_array($category->term_id, $selected_categories), true); ?> class="category-checkbox">
                                        <?php echo esc_html($category->name); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php echo esc_html__('Filter content by categories. Leave empty to include all categories.', 'wp-gpt-chatbot'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if (!empty($tags)) : ?>
                <tr>
                    <th scope="row">
                        <label><?php echo esc_html__('Tags', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php echo esc_html__('Tags', 'wp-gpt-chatbot'); ?></legend>
                            <p>
                                <label>
                                    <input type="checkbox" id="select-all-tags" class="select-all">
                                    <?php echo esc_html__('Select All', 'wp-gpt-chatbot'); ?>
                                </label>
                            </p>
                            <div class="wp-gpt-chatbot-checkbox-columns">
                                <?php foreach ($tags as $tag) : ?>
                                    <label>
                                        <input type="checkbox" name="wp_gpt_chatbot_settings[website_content][tags][]" value="<?php echo esc_attr($tag->term_id); ?>" 
                                            <?php checked(in_array($tag->term_id, $selected_tags), true); ?> class="tag-checkbox">
                                        <?php echo esc_html($tag->name); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php echo esc_html__('Filter content by tags. Leave empty to include all tags.', 'wp-gpt-chatbot'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <th scope="row">
                        <label for="excluded-pages-search"><?php echo esc_html__('Excluded Pages', 'wp-gpt-chatbot'); ?></label>
                    </th>
                    <td>
                        <div class="wp-gpt-chatbot-excluded-pages">
                            <div class="excluded-pages-search-container">
                                <input type="text" id="excluded-pages-search" placeholder="<?php echo esc_attr__('Search pages by title...', 'wp-gpt-chatbot'); ?>" class="regular-text">
                                <div id="excluded-pages-results" class="excluded-pages-results"></div>
                            </div>
                            
                            <div class="excluded-pages-list">
                                <p><?php echo esc_html__('Selected pages to exclude:', 'wp-gpt-chatbot'); ?></p>
                                <ul id="excluded-pages-list">
                                    <?php 
                                    if (!empty($excluded_pages)) {
                                        foreach ($excluded_pages as $page_id) {
                                            $page = get_post($page_id);
                                            if ($page) {
                                                echo '<li data-id="' . esc_attr($page_id) . '">' . esc_html($page->post_title) . ' <a href="#" class="remove-excluded-page">×</a>';
                                                echo '<input type="hidden" name="wp_gpt_chatbot_settings[website_content][excluded_pages][]" value="' . esc_attr($page_id) . '">';
                                                echo '</li>';
                                            }
                                        }
                                    }
                                    ?>
                                </ul>
                            </div>
                            
                            <p class="description"><?php echo esc_html__('Select specific pages you want to exclude from the chatbot knowledge base.', 'wp-gpt-chatbot'); ?></p>
                        </div>
                    </td>
                </tr>
            </table>
            
            <p>
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr__('Save Changes', 'wp-gpt-chatbot'); ?>">
                
                <?php if ($enabled) : ?>
                    <button type="button" id="refresh-content-button" class="button button-secondary" <?php echo $enabled ? '' : 'disabled'; ?>>
                        <?php echo esc_html__('Refresh Website Content Now', 'wp-gpt-chatbot'); ?>
                    </button>
                    
                    <span id="refresh-content-spinner" class="spinner" style="float: none;"></span>
                    <span id="refresh-content-message" style="margin-left: 10px;"></span>
                <?php else : ?>
                    <p class="description"><?php echo esc_html__('Save settings with "Enable Content Crawler" checked to enable manual content refresh.', 'wp-gpt-chatbot'); ?></p>
                <?php endif; ?>
            </p>
            
            <?php if ($website_training_count > 0) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php echo sprintf(
                            esc_html__('Currently using %d training entries from website content. These will be updated when you refresh the content.', 'wp-gpt-chatbot'),
                            $website_training_count
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<style>
    .wp-gpt-chatbot-checkbox-columns {
        column-count: 3;
        column-gap: 20px;
    }
    
    @media (max-width: 768px) {
        .wp-gpt-chatbot-checkbox-columns {
            column-count: 2;
        }
    }
    
    @media (max-width: 480px) {
        .wp-gpt-chatbot-checkbox-columns {
            column-count: 1;
        }
    }
    
    .excluded-pages-search-container {
        position: relative;
        margin-bottom: 10px;
    }
    
    .excluded-pages-results {
        position: absolute;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        background: white;
        border: 1px solid #ddd;
        z-index: 999;
        display: none;
    }
    
    .excluded-pages-results .result-item {
        padding: 8px 10px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .excluded-pages-results .result-item:hover {
        background-color: #f5f5f5;
    }
    
    #excluded-pages-list {
        margin: 0;
    }
    
    #excluded-pages-list li {
        margin: 5px 0;
        padding: 5px 10px;
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 3px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .remove-excluded-page {
        color: #a00;
        text-decoration: none;
        font-weight: bold;
    }
</style>

<script>
jQuery(document).ready(function($) {
    // Auto-refresh settings interactions
    $('#wp_gpt_chatbot_settings\\[website_content\\]\\[enabled\\]').on('change', function() {
        var enabled = $(this).prop('checked');
        $('#wp_gpt_chatbot_settings\\[website_content\\]\\[auto_refresh\\]').prop('disabled', !enabled);
        
        updateRefreshFrequencyState();
    });
    
    $('#wp_gpt_chatbot_settings\\[website_content\\]\\[auto_refresh\\]').on('change', function() {
        updateRefreshFrequencyState();
    });
    
    function updateRefreshFrequencyState() {
        var contentEnabled = $('#wp_gpt_chatbot_settings\\[website_content\\]\\[enabled\\]').prop('checked');
        var autoRefreshEnabled = $('#wp_gpt_chatbot_settings\\[website_content\\]\\[auto_refresh\\]').prop('checked');
        
        $('#wp_gpt_chatbot_settings\\[website_content\\]\\[refresh_frequency\\]').prop('disabled', !(contentEnabled && autoRefreshEnabled));
    }
    
    // Initial state update
    updateRefreshFrequencyState();
    
    // Select all checkboxes functionality
    $('.select-all').change(function() {
        var isChecked = $(this).prop('checked');
        var checkboxClass = $(this).hasClass('select-all-categories') ? '.category-checkbox' : '.tag-checkbox';
        
        $(checkboxClass).prop('checked', isChecked);
    });
    
    // Excluded pages search functionality
    var searchTimeout;
    
    $('#excluded-pages-search').on('keyup', function() {
        var query = $(this).val();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            $('#excluded-pages-results').hide();
            return;
        }
        
        searchTimeout = setTimeout(function() {
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'wp_gpt_chatbot_search_pages',
                    query: query,
                    nonce: '<?php echo wp_create_nonce('wp_gpt_chatbot_search_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        renderSearchResults(response.data);
                    }
                }
            });
        }, 300);
    });
    
    function renderSearchResults(results) {
        var $results = $('#excluded-pages-results');
        $results.empty();
        
        if (results.length === 0) {
            $results.append('<div class="result-item">No pages found</div>');
        } else {
            $.each(results, function(index, page) {
                // Skip if already in excluded list
                if ($('#excluded-pages-list li[data-id="' + page.id + '"]').length === 0) {
                    $results.append('<div class="result-item" data-id="' + page.id + '" data-title="' + page.title + '">' + page.title + '</div>');
                }
            });
        }
        
        $results.show();
    }
    
    // Handle result item click
    $(document).on('click', '.result-item', function() {
        var pageId = $(this).data('id');
        var pageTitle = $(this).data('title');
        
        // Add to excluded pages list
        if (pageId && pageTitle) {
            $('#excluded-pages-list').append(
                '<li data-id="' + pageId + '">' + pageTitle + ' <a href="#" class="remove-excluded-page">×</a>' +
                '<input type="hidden" name="wp_gpt_chatbot_settings[website_content][excluded_pages][]" value="' + pageId + '">' +
                '</li>'
            );
        }
        
        // Clear search
        $('#excluded-pages-search').val('');
        $('#excluded-pages-results').hide();
    });
    
    // Handle remove excluded page
    $(document).on('click', '.remove-excluded-page', function(e) {
        e.preventDefault();
        $(this).parent().remove();
    });
    
    // Handle clicking outside of search results
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.excluded-pages-search-container').length) {
            $('#excluded-pages-results').hide();
        }
    });
    
    // Refresh content button
    $('#refresh-content-button').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $('#refresh-content-spinner');
        var $message = $('#refresh-content-message');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.text('<?php echo esc_js(__('Refreshing content...', 'wp-gpt-chatbot')); ?>');
        
        // First, save the form
        var formData = $('#website-content-form').serialize();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function() {
                // After saving, refresh the content
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_gpt_chatbot_refresh_content',
                        nonce: '<?php echo wp_create_nonce('wp_gpt_chatbot_nonce'); ?>'
                    },
                    success: function(response) {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        
                        if (response.success) {
                            $message.html('<span style="color: green;">' + response.data.message + '</span>');
                        } else {
                            $message.html('<span style="color: red;">' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        $message.html('<span style="color: red;"><?php echo esc_js(__('Error refreshing content. Please try again.', 'wp-gpt-chatbot')); ?></span>');
                    }
                });
            }
        });
    });
});
</script>
