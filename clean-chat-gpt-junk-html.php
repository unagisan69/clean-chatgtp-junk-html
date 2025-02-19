<?php
/*
Plugin Name: Clean ChatGPT Junk HTML
Description: Scans and removes unwanted data-start and data-end attributes from specified HTML tags across all posts, pages, and registered custom post types. Includes dry run mode and selective page cleaning with compatibility for Elementor, WPBakery, and standard WordPress editors.
Version: 2.4
Author: Asheville Web Design
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Clean_ChatGPT_Junk_HTML {
    private $option_name = 'clean_chatgpt_junk_html_options';
    private $default_tags = 'h1,h2,h3,h4,h5,ol,li,p,ul,strong';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_post_clean_page', [$this, 'handle_clean_page_request']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Clean ChatGPT Junk HTML',
            'Clean Junk HTML',
            'manage_options',
            'clean_chatgpt_junk_html',
            [$this, 'options_page']
        );
    }

    public function settings_init() {
        register_setting('clean_chatgpt_junk_html', $this->option_name, [
            'default' => [
                'dry_run' => 1,
                'tags' => $this->default_tags
            ]
        ]);

        add_settings_section(
            'clean_chatgpt_junk_html_section',
            'Settings',
            null,
            'clean_chatgpt_junk_html'
        );

        add_settings_field(
            'dry_run',
            'Dry Run Mode',
            [$this, 'dry_run_render'],
            'clean_chatgpt_junk_html',
            'clean_chatgpt_junk_html_section'
        );

        add_settings_field(
            'tags',
            'Tags to Clean (comma separated)',
            [$this, 'tags_render'],
            'clean_chatgpt_junk_html',
            'clean_chatgpt_junk_html_section'
        );
    }

    public function dry_run_render() {
        $options = get_option($this->option_name);
        ?>
        <input type="checkbox" name="<?php echo $this->option_name; ?>[dry_run]" value="1" <?php checked(1, $options['dry_run'] ?? 0); ?> /> Enable Dry Run Mode
        <?php
    }

    public function tags_render() {
        $options = get_option($this->option_name);
        $tags = $options['tags'] ?? $this->default_tags;
        ?>
        <input type="text" name="<?php echo $this->option_name; ?>[tags]" value="<?php echo esc_attr($tags); ?>" placeholder="h1,h2,h3,h4,h5,ol,li,p,ul,strong" />
        <?php
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Clean ChatGPT Junk HTML</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('clean_chatgpt_junk_html');
                do_settings_sections('clean_chatgpt_junk_html');
                submit_button('Save Settings');
                ?>
            </form>
            <hr>
            <h2>Scan Content (Posts, Pages, and Custom Post Types)</h2>
            <form method="post">
                <input type="submit" name="scan_pages" class="button button-primary" value="Scan Site" />
            </form>
            <?php
            if (isset($_POST['scan_pages'])) {
                $this->scan_pages();
            }
            ?>
        </div>
        <?php
    }

    private function scan_pages() {
        $options = get_option($this->option_name);
        $tags = array_filter(array_map('trim', explode(',', $options['tags'] ?? $this->default_tags)));

        if (empty($tags)) {
            echo '<p style="color: red;">Please specify tags to clean.</p>';
            return;
        }

        $post_types = get_post_types(['public' => true], 'names');

        $args = [
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];

        $query = new WP_Query($args);

        echo '<table class="widefat fixed"><thead><tr><th>Post Type</th><th>Title</th><th>Found Instances</th><th>Action</th></tr></thead><tbody>';

        while ($query->have_posts()) {
            $query->the_post();
            $content = $this->get_complete_content(get_the_ID());
            $matches = $this->find_junk_html($content, $tags);

            if (!empty($matches)) {
                echo '<tr>';
                echo '<td>' . get_post_type() . '</td>';
                echo '<td><a href="' . get_edit_post_link() . '" target="_blank">' . get_the_title() . '</a></td>';
                echo '<td>' . count($matches) . '</td>';
                echo '<td>
                    <form method="post" action="' . admin_url('admin-post.php') . '">
                        <input type="hidden" name="action" value="clean_page" />
                        <input type="hidden" name="post_id" value="' . get_the_ID() . '" />
                        ' . wp_nonce_field('clean_page_action', '_wpnonce', true, false) . '
                        <input type="submit" class="button" value="Clean This Post" />
                    </form>
                </td>';
                echo '</tr>';
            }
        }

        wp_reset_postdata();
        echo '</tbody></table>';
    }

    private function find_junk_html($content, $tags) {
        if (!$content) return [];

        $tag_pattern = implode('|', array_map('preg_quote', $tags));
        $regex = '/<(' . $tag_pattern . ')[^>]*?(\sdata-start="[^"]*"|\sdata-end="[^"]*")[^>]*?>/i';

        preg_match_all($regex, $content, $matches, PREG_SET_ORDER);

        return $matches;
    }

    private function get_complete_content($post_id) {
        $content = get_post_field('post_content', $post_id);

        // Elementor content extraction
        if (did_action('elementor/loaded') && get_post_meta($post_id, '_elementor_data', true)) {
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            $content .= $this->extract_elementor_content($elementor_data);
        }

        return $content;
    }

    private function extract_elementor_content($data) {
        $content = '';
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (is_array($data)) {
            foreach ($data as $element) {
                if (isset($element['settings'])) {
                    foreach ($element['settings'] as $value) {
                        if (is_string($value)) {
                            $content .= $value . ' ';
                        }
                    }
                }

                if (isset($element['elements'])) {
                    $content .= $this->extract_elementor_content($element['elements']);
                }
            }
        }
        return $content;
    }

    public function handle_clean_page_request() {
        if (!current_user_can('manage_options') || !isset($_POST['post_id']) || !wp_verify_nonce($_POST['_wpnonce'], 'clean_page_action')) {
            wp_die('Unauthorized request.');
        }

        $post_id = intval($_POST['post_id']);
        $options = get_option($this->option_name);
        $tags = array_filter(array_map('trim', explode(',', $options['tags'] ?? $this->default_tags)));

        if (empty($tags)) {
            wp_redirect(admin_url('tools.php?page=clean_chatgpt_junk_html&cleaned=0'));
            exit;
        }

        $content = get_post_field('post_content', $post_id);
        $cleaned_content = $this->clean_junk_attributes($content, $tags);

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $cleaned_content,
        ]);

        // Clean Elementor data
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if ($elementor_data) {
            $cleaned_elementor_data = $this->clean_elementor_data($elementor_data, $tags);
            update_post_meta($post_id, '_elementor_data', $cleaned_elementor_data);
        }

        wp_redirect(admin_url('tools.php?page=clean_chatgpt_junk_html&cleaned=1'));
        exit;
    }

    private function clean_junk_attributes($content, $tags) {
        $tag_pattern = implode('|', array_map('preg_quote', $tags));
        return preg_replace_callback('/<(' . $tag_pattern . ')[^>]*>/i', function ($matches) {
            return preg_replace('/\s(data-start|data-end)="[^"]*"/', '', $matches[0]);
        }, $content);
    }

    private function clean_elementor_data($data, $tags) {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (is_array($data)) {
            foreach ($data as &$element) {
                if (isset($element['settings'])) {
                    foreach ($element['settings'] as $key => $value) {
                        if (is_string($value)) {
                            $element['settings'][$key] = $this->clean_junk_attributes($value, $tags);
                        }
                    }
                }

                if (isset($element['elements'])) {
                    $element['elements'] = $this->clean_elementor_data($element['elements'], $tags);
                }
            }
        }

        return $data;
    }
}

new Clean_ChatGPT_Junk_HTML();
