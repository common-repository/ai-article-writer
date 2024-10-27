<?php
/**
 * Plugin Name: AI Article Writer
 * Plugin URI: https://aitoolbuddy.com/plugins
 * Description: Creates automatic articles using the AIToolBuddy API
 * Version: 1.9
 * Author: AI Tool Buddy
 * Author URI: https://aitoolbuddy.com/
 * License: GPL2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class AI_Article_Writer {
    
    private $api_base_url = 'https://aitoolbuddy.com/api-endpoint';
    private $options;
    private $cron_hook = 'ai_article_writer_create_article';
    private $notification_hook = 'ai_article_writer_notification';

    
    public function __construct() {
        // Load options
        $this->options = get_option('ai_article_writer_options');
        
        // Hook for scheduling
        add_action('wp', array($this, 'schedule_article_creation'));
        
        // Hook for creating articles
        add_action($this->cron_hook, array($this, 'create_article_from_api'));
        
        add_action($this->notification_hook, array($this, 'send_no_topics_notification'));


        // Hook for plugin activation
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // Hook for plugin deactivation
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add AJAX action for manual run
        add_action('wp_ajax_run_ai_article_writer', array($this, 'ajax_run_article_writer'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function activate() {
        $this->schedule_cron_jobs();
    }

    private function schedule_cron_jobs() {
        if (!empty($this->options['cron_enabled']) && $this->options['cron_enabled'] == 'on') {
            $posts_per_day = isset($this->options['posts_per_day']) ? intval($this->options['posts_per_day']) : 1;
            $interval = max(1, intval(24 / $posts_per_day));
            
            if (!wp_next_scheduled($this->cron_hook)) {
                wp_schedule_event(time(), 'hourly', $this->cron_hook);
            }
        } else {
            $this->clear_cron_jobs();
        }
    }
    
    private function clear_cron_jobs() {
        $timestamp = wp_next_scheduled($this->cron_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cron_hook);
        }
    }
    
    public function schedule_article_creation() {
        $this->schedule_cron_jobs();
    }
    
    public function create_article_from_api() {
        if (empty($this->options['api_key']) || empty($this->options['topics'])) {
            error_log('AI Article Writer: API key or topics not set');
            return false;
        }
        
        // Check if it's time to create a post based on posts_per_day setting
        $posts_per_day = isset($this->options['posts_per_day']) ? intval($this->options['posts_per_day']) : 1;
        $last_post_time = get_option('ai_article_writer_last_post_time', 0);
        $time_since_last_post = time() - $last_post_time;
        $interval = max(1, intval(24 / $posts_per_day)) * HOUR_IN_SECONDS;
        
        if ($time_since_last_post < $interval) {
            return false;
        }
        
        // Step 1: Choose an unused topic
        $topic = $this->get_unused_topic();
        if (!$topic) {
            $this->all_topics_used();
            return false;
        }
        
        // Step 2: Generate an article using AI Story Writer
        $job_id = $this->generate_article($topic);
        if (!$job_id) {
            error_log('AI Article Writer: Failed to generate article');
            return false;
        }
        
        // Step 3: Wait for the job to complete
        $article = $this->wait_for_job_completion($job_id);
        if (!$article) {
            error_log('AI Article Writer: Failed to retrieve generated article');
            return false;
        }
        
        // Step 4: Process the article content for WordPress blocks
        $processed_content = $this->process_content_for_blocks($article);
        
        // Step 5: Create a post with the generated article
        $post_data = array(
            'post_title'    => $topic,
            'post_content'  => $processed_content,
            'post_status'   => $this->options['post_status'] ?? 'publish',
            'post_author'   => 1,  // Default admin user
            'post_type'     => 'post',
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (!is_wp_error($post_id)) {
            update_option('ai_article_writer_last_post_time', time());
            $this->mark_topic_as_used($topic);
            error_log('AI Article Writer: Successfully created article with ID ' . $post_id);
            return true;
        } else {
            error_log('AI Article Writer: Failed to create article. Error: ' . $post_id->get_error_message());
            return false;
        }
    }
    
    private function generate_article($topic) {
        $endpoint = $this->api_base_url . '/?action=upload';
        
        $body = array(
            'tool' => 'AI Article Writer',
            'topic' => $topic,
            'lang' => $this->options['language'] ?? 'English',
            'format' => 'html',
            'request' => 'wp'
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'APIKEY' => $this->options['api_key'],
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => $body
        ));
        
        if (is_wp_error($response)) {
            error_log('AI Article Writer: Failed to generate article. Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['job_id'])) {
            return $body['job_id'];
        }
        
        return false;
    }
    
    private function wait_for_job_completion($job_id) {
        $endpoint = $this->api_base_url . '/?action=status';
        $max_attempts = 10;
        $attempt = 0;
        
        while ($attempt < $max_attempts) {
            $response = wp_remote_post($endpoint, array(
                'headers' => array(
                    'APIKEY' => $this->options['api_key'],
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => array('job_id' => $job_id)
            ));
            
            if (is_wp_error($response)) {
                error_log('AI Article Writer: Failed to check job status. Error: ' . $response->get_error_message());
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['status']) && $body['status'] === 'Completed') {
                return $this->download_article($job_id);
            }
            
            $attempt++;
            sleep(30);  // Wait for 30 seconds before checking again
        }
        
        error_log('AI Article Writer: Job did not complete within the expected time');
        return false;
    }
    
    private function download_article($job_id) {
        $endpoint = $this->api_base_url . '/?action=download';
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'APIKEY' => $this->options['api_key'],
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => array('job_id' => $job_id)
        ));
        
        if (is_wp_error($response)) {
            error_log('AI Article Writer: Failed to download article. Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['response']['text'])) {
            return $body['response']['text'];
        }
        
        return false;
    }
    
    private function process_content_for_blocks($content) {
        // Ensure that wp_parse_blocks function is available
        if (!function_exists('wp_parse_blocks')) {
            require_once(ABSPATH . 'wp-includes/blocks.php');
        }
        
        // Convert HTML to blocks
        $blocks = array();
        
        // Split content into paragraphs
        $paragraphs = preg_split('/<\/?(?:p|h[1-6]|ul|ol|li)>/i', $content, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            
            // Skip empty paragraphs
            if (empty($paragraph)) {
                continue;
            }
            
            // Check if the paragraph is a heading
            if (preg_match('/^<h([1-6])>(.*?)<\/h[1-6]>$/i', $paragraph, $matches)) {
                $heading_level = $matches[1];
                $heading_content = $matches[2];
                $blocks[] = array(
                    'blockName' => 'core/heading',
                    'attrs' => array('level' => intval($heading_level)),
                    'innerHTML' => $heading_content,
                    'innerContent' => array($heading_content),
                );
            }
            // Check if the paragraph is a list
            elseif (strpos($paragraph, '<ul>') === 0 || strpos($paragraph, '<ol>') === 0) {
                $list_type = (strpos($paragraph, '<ul>') === 0) ? 'ul' : 'ol';
                $blocks[] = array(
                    'blockName' => 'core/list',
                    'attrs' => array('ordered' => ($list_type === 'ol')),
                    'innerHTML' => $paragraph,
                    'innerContent' => array($paragraph),
                );
            }
            // Otherwise, treat it as a regular paragraph
            else {
                $blocks[] = array(
                    'blockName' => 'core/paragraph',
                    'attrs' => array(),
                    'innerHTML' => '<p>' . $paragraph . '</p>',
                    'innerContent' => array('<p>' . $paragraph . '</p>'),
                );
            }
        }
        
        // Serialize blocks back to content
        $processed_content = serialize_blocks($blocks);
        
        return $processed_content;
    }
    
    private function get_unused_topic() {
        $topics = explode("\n", $this->options['topics']);
        $topics = array_map('trim', $topics);
        $topics = array_filter($topics);
        
        $used_topics = get_option('ai_article_writer_used_topics', array());
        $unused_topics = array_diff($topics, $used_topics);
        
        if (empty($unused_topics)) {
            return false;
        }
        
        return $unused_topics[array_rand($unused_topics)];
    }
    
    private function mark_topic_as_used($topic) {
        $used_topics = get_option('ai_article_writer_used_topics', array());
        $used_topics[] = $topic;
        update_option('ai_article_writer_used_topics', $used_topics);
    }
    
    private function all_topics_used() {
        $this->clear_cron_jobs();
        update_option('ai_article_writer_all_topics_used', true);
        
        // Schedule twice-daily notifications
        if (!wp_next_scheduled($this->notification_hook)) {
            wp_schedule_event(time(), 'twicedaily', $this->notification_hook);
        }
    }
    
    public function send_no_topics_notification() {
        if (get_option('ai_article_writer_all_topics_used', false)) {
            $admin_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            $subject = "[$site_name] AI Article Writer: No Topics Available";
            $message = "All topics have been used in the AI Article Writer plugin. The cron job has been stopped. Please add new topics and reset the used topics list in the plugin settings.";
            
            wp_mail($admin_email, $subject, $message);
        } else {
            // If topics are available again, clear the notification schedule
            $this->clear_notification_schedule();
        }
    }

    private function clear_notification_schedule() {
        $timestamp = wp_next_scheduled($this->notification_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->notification_hook);
        }
    }

    public function deactivate() {
        $this->clear_cron_jobs();
        $this->clear_notification_schedule();
    }


    public function add_settings_page() {
        add_options_page(
            'AI Article Writer Settings',
            'AI Article Writer',
            'manage_options',
            'ai-article-writer',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('ai_article_writer_options', 'ai_article_writer_options', array($this, 'sanitize_options'));
        
        add_settings_section(
            'api_settings',
            'API Settings',
            array($this, 'api_settings_section_callback'),
            'ai-article-writer'
        );
        
        add_settings_field(
            'api_key',
            'API Key',
            array($this, 'api_key_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'topics',
            'Topics (one per line)',
            array($this, 'topics_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'language',
            'Language',
            array($this, 'language_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'post_status',
            'Post Status',
            array($this, 'post_status_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'posts_per_day',
            'Posts Per Day',
            array($this, 'posts_per_day_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'cron_enabled',
            'Enable Automatic Posting',
            array($this, 'cron_enabled_callback'),
            'ai-article-writer',
            'api_settings'
        );
        
        add_settings_field(
            'reset_topics',
            'Reset Used Topics',
            array($this, 'reset_topics_callback'),
            'ai-article-writer',
            'api_settings'
        );
    }
    
    public function sanitize_options($input) {
        $sanitized_input = array();
        if (isset($input['api_key'])) {
            $sanitized_input['api_key'] = sanitize_text_field($input['api_key']);
        }
        if (isset($input['topics'])) {
            $sanitized_input['topics'] = sanitize_textarea_field($input['topics']);
        }
        if (isset($input['language'])) {
            $sanitized_input['language'] = sanitize_text_field($input['language']);
        }
        if (isset($input['post_status'])) {
            $sanitized_input['post_status'] = in_array($input['post_status'], ['publish', 'draft']) ? $input['post_status'] : 'publish';
        }
        if (isset($input['posts_per_day'])) {
            $sanitized_input['posts_per_day'] = max(1, min(24, intval($input['posts_per_day'])));
        }
        if (isset($input['cron_enabled'])) {
            $sanitized_input['cron_enabled'] = $input['cron_enabled'] ? 'on' : 'off';
        }
        if (isset($input['reset_topics']) && $input['reset_topics'] === 'yes') {
            delete_option('ai_article_writer_used_topics');
            delete_option('ai_article_writer_all_topics_used');
            $this->schedule_cron_jobs();
        }
        return $sanitized_input;
    }
    
    public function api_settings_section_callback() {
        echo '<p>Enter your AIToolBuddy API settings below:</p>';
    }
    
    public function api_key_callback() {
        $api_key = isset($this->options['api_key']) ? $this->options['api_key'] : '';
        echo '<input type="text" id="api_key" name="ai_article_writer_options[api_key]" value="' . esc_attr($api_key) . '" class="regular-text"><br><a><a target="_blank" href="https://aitoolbuddy.com/account">Get API Key</a>';
    }
    
    public function topics_callback() {
        $topics = isset($this->options['topics']) ? $this->options['topics'] : '';
        echo '<textarea id="topics" name="ai_article_writer_options[topics]" rows="5" cols="50">' . esc_textarea($topics) . '</textarea>';
        echo '<p class="description">Enter one topic per line. Topics will be used in the order they appear.</p>';
    }
    
    public function language_callback() {
        $language = isset($this->options['language']) ? $this->options['language'] : 'English';
        echo '<input type="text" id="language" name="ai_article_writer_options[language]" value="' . esc_attr($language) . '" class="regular-text">';
        echo '<p class="description">Enter the language code (e.g., English or Spanish)</p>';
    }
    
    public function post_status_callback() {
        $post_status = isset($this->options['post_status']) ? $this->options['post_status'] : 'publish';
        echo '<select id="post_status" name="ai_article_writer_options[post_status]">';
        echo '<option value="publish"' . selected($post_status, 'publish', false) . '>Publish</option>';
        echo '<option value="draft"' . selected($post_status, 'draft', false) . '>Draft</option>';
        echo '</select>';
        echo '<p class="description">Choose whether to publish articles immediately or save them as drafts.</p>';
    }
    
    public function posts_per_day_callback() {
        $posts_per_day = isset($this->options['posts_per_day']) ? intval($this->options['posts_per_day']) : 1;
        echo '<input type="number" id="posts_per_day" name="ai_article_writer_options[posts_per_day]" value="' . esc_attr($posts_per_day) . '" min="1" max="24">';
        echo '<p class="description">Choose how many posts to create per day (1-24).</p>';
    }
    
    public function cron_enabled_callback() {
        $cron_enabled = isset($this->options['cron_enabled']) ? $this->options['cron_enabled'] : 'on';
        echo '<input type="checkbox" id="cron_enabled" name="ai_article_writer_options[cron_enabled]" ' . checked($cron_enabled, 'on', false) . '>';
        echo '<label for="cron_enabled">Enable automatic posting</label>';
    }
    
    public function reset_topics_callback() {
        echo '<input type="checkbox" id="reset_topics" name="ai_article_writer_options[reset_topics]" value="yes">';
        echo '<label for="reset_topics">Reset used topics list and restart cron job</label>';
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>AI Article Writer - Settings</h1>
            Check for plugin updates: <a target="_blank" href="https://aitoolbuddy.com/plugins/#wordpress">aitoolbuddy.com</a>

            <div id="all-topics-used-warning" class="notice notice-warning" <?php echo get_option('ai_article_writer_all_topics_used', false) ? '' : 'style="display:none;"'; ?>>
                <p><strong>All topics have been used. Please add new topics and reset the used topics list.</strong></p>
            </div>
            <form method="post" action="options.php">
                <?php
                settings_fields('ai_article_writer_options');
                do_settings_sections('ai-article-writer');
                submit_button();
                ?>
            </form>
            <hr>
            <h2>Manual Run</h2>
            <p>Click the button below to manually generate and post an article:</p>
            <button id="run-ai-article-writer" class="button button-primary">Generate Article Now</button>
            <div id="ai-article-writer-result"></div>
        </div>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_ai-article-writer' !== $hook) {
            return;
        }
        wp_enqueue_script('ai-article-writer-admin', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '1.0', true);
        wp_localize_script('ai-article-writer-admin', 'aiArticleWriter', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai-article-writer-nonce')
        ));
    }
    
    public function ajax_run_article_writer() {
        check_ajax_referer('ai-article-writer-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->create_article_from_api();
        
        if ($result) {
            wp_send_json_success('Article generated and posted successfully.');
        } else {
            $all_topics_used = get_option('ai_article_writer_all_topics_used', false);
            if ($all_topics_used) {
                wp_send_json_error('All topics have been used. Please add new topics and reset the used topics list.');
            } else {
                wp_send_json_error('Failed to generate and post article. Please check the error log for details.');
            }
        }
    }
}

// Initialize the plugin
new AI_Article_Writer();