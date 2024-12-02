<?php
/**
 * Plugin Name: WP Backup
 * Description: This plugin and script is used to backup data on your wordpress site using rest api.
 * Version: 1.7
 * Author: polisek.io
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Backup_Logger {
    private $api_key_option = 'wp_backup_api_key';
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/backup-log.txt';

        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_routes() {
        register_rest_route('backup/v1', '/download', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_backup_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function register_admin_pages() {
        add_menu_page(
            'Backup Logs',
            'Backup Logs',
            'manage_options',
            'backup-logs',
            [$this, 'display_logs'],
            'dashicons-clipboard',
            100
        );

        add_submenu_page(
            'backup-logs',
            'Upload Backup',
            'Upload Backup',
            'manage_options',
            'upload-backup',
            [$this, 'display_upload_backup']
        );

        add_options_page(
            'Backup Settings',
            'Backup Settings',
            'manage_options',
            'backup-settings',
            [$this, 'display_settings']
        );
    }

    public function register_settings() {
        register_setting('wp_backup_settings', $this->api_key_option);
    }

    public function handle_backup_request($request) {
        $provided_key = $request->get_param('key');
        $stored_key = get_option($this->api_key_option, '');

        if ($provided_key !== $stored_key) {
            $this->log('Unauthorized access attempt with invalid API key.');
            return rest_ensure_response([
                'success' => false,
                'error' => 'Invalid API key'
            ]);
        }

        $this->log('Starting backup process...');
        $this->delete_old_backups();

        try {
            $results = [
                'database' => $this->export_database(),
                'theme' => $this->export_active_theme(),
                'plugins' => $this->export_active_plugins(),
            ];

            $this->log('Backup process completed.');
            return rest_ensure_response($results);

        } catch (Exception $e) {
            $this->log('Exception encountered: ' . $e->getMessage());
            return rest_ensure_response([
                'success' => false,
                'error' => 'An exception occurred during the backup process.',
                'details' => $e->getMessage()
            ]);
        }
    }

    private function delete_old_backups() {
        $upload_dir = wp_upload_dir()['basedir'];
        $backup_files = [
            $upload_dir . '/database-backup.sql',
            $upload_dir . '/theme-backup.zip',
            $upload_dir . '/plugins-backup.zip',
        ];

        foreach ($backup_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function export_database() {
        global $wpdb;
        $backup_file = wp_upload_dir()['basedir'] . '/database-backup.sql';

        $tables = $wpdb->get_col('SHOW TABLES');
        $sql = "-- WordPress Database Backup\n-- Exported on: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N)[1];
            $sql .= "$create;\n\n";
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            foreach ($rows as $row) {
                $values = implode("','", array_map([$wpdb, '_real_escape'], array_values($row)));
                $sql .= "INSERT INTO `$table` VALUES ('$values');\n";
            }
        }

        file_put_contents($backup_file, $sql);
        return ['success' => true, 'file' => wp_upload_dir()['baseurl'] . '/database-backup.sql'];
    }

    private function export_active_theme() {
        $theme_dir = get_template_directory();
        $zip_file = wp_upload_dir()['basedir'] . '/theme-backup.zip';
        $this->zip_folder($theme_dir, $zip_file);
        return ['success' => true, 'file' => wp_upload_dir()['baseurl'] . '/theme-backup.zip'];
    }

    private function export_active_plugins() {
        $plugin_dir = WP_PLUGIN_DIR;
        $zip_file = wp_upload_dir()['basedir'] . '/plugins-backup.zip';
        $this->zip_folder($plugin_dir, $zip_file);
        return ['success' => true, 'file' => wp_upload_dir()['baseurl'] . '/plugins-backup.zip'];
    }

    private function zip_folder($folder, $zip_file) {
        $zip = new ZipArchive();
        $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $relative_path = substr($file->getRealPath(), strlen($folder) + 1);
                $zip->addFile($file->getRealPath(), $relative_path);
            }
        }
        $zip->close();
    }

    public function display_upload_backup() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_files'])) {
            $this->handle_backup_upload($_FILES['backup_files']);
        }
        ?>
        <div class="wrap">
            <h1>Upload Backup</h1>
            <form method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th scope="row">Upload database-backup.sql</th>
                        <td><input type="file" name="backup_files[]" accept=".sql"></td>
                    </tr>
                    <tr>
                        <th scope="row">Upload theme-backup.zip</th>
                        <td><input type="file" name="backup_files[]" accept=".zip"></td>
                    </tr>
                    <tr>
                        <th scope="row">Upload plugins-backup.zip</th>
                        <td><input type="file" name="backup_files[]" accept=".zip"></td>
                    </tr>
                </table>
                <?php submit_button('Upload & Restore'); ?>
            </form>
        </div>
        <?php
    }

    private function handle_backup_upload($files) {
        $upload_dir = wp_upload_dir()['basedir'];
        foreach ($files['name'] as $index => $file_name) {
            $destination = $upload_dir . '/' . $file_name;
            move_uploaded_file($files['tmp_name'][$index], $destination);

            if (strpos($file_name, '.sql') !== false) {
                $this->restore_database($destination);
            } elseif (strpos($file_name, 'theme-backup.zip') !== false) {
                $this->restore_theme($destination);
            } elseif (strpos($file_name, 'plugins-backup.zip') !== false) {
                $this->restore_zip($destination, WP_PLUGIN_DIR);
                $this->activate_plugins($destination);
            }
        }
    }

    private function restore_database($file) {
        global $wpdb;
        $sql = file_get_contents($file);
        $queries = explode(';', $sql);
        foreach ($queries as $query) {
            if (!empty(trim($query))) {
                $wpdb->query($query);
            }
        }
    }

    private function restore_theme($file) {
        $theme_root = get_theme_root();
        $theme_backup_dir = $theme_root . '/backup-theme';
        if (!file_exists($theme_backup_dir)) {
            mkdir($theme_backup_dir, 0755, true);
        }
        $this->restore_zip($file, $theme_backup_dir);
        $this->activate_theme('backup-theme');
    }

    private function restore_zip($file, $destination) {
        $zip = new ZipArchive();
        if ($zip->open($file) === true) {
            $zip->extractTo($destination);
            $zip->close();
        }
    }

    private function activate_theme($theme_name) {
        switch_theme($theme_name);
        $this->log("Activated theme: $theme_name");
    }

    private function activate_plugins($plugins_dir) {
        $plugin_files = glob($plugins_dir . '/*.php');
        foreach ($plugin_files as $plugin_file) {
            activate_plugin(plugin_basename($plugin_file));
        }
        $this->log("Activated all plugins.");
    }

    public function display_logs() {
        echo '<div class="wrap"><h1>Backup Logs</h1><pre>';
        echo file_exists($this->log_file) ? file_get_contents($this->log_file) : 'No logs available.';
        echo '</pre></div>';
    }

    public function display_settings() {
        ?>
        <div class="wrap">
            <h1>Backup Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wp_backup_settings'); ?>
                <input type="text" name="<?php echo esc_attr($this->api_key_option); ?>" value="<?php echo esc_attr(get_option($this->api_key_option, '')); ?>" />
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function log($message) {
        $timestamp = current_time('mysql');
        file_put_contents($this->log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

new WP_Backup_Logger();
