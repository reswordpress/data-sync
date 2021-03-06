<?php


namespace DataSync\Controllers;

use DataSync\Controllers\File;
use DataSync\Routes\TemplateSyncRoutes;
use stdClass;
use DataSync\Controllers\Logs;
use DataSync\Models\ConnectedSite;

class TemplateSync
{
    public function __construct()
    {
        new TemplateSyncRoutes($this);
    }

    public static function get_template_files()
    {
        $template_dir = DATA_SYNC_PATH . 'templates';

        return scandir($template_dir);
    }


    public function initiate()
    {
        $template_dir    = DATA_SYNC_PATH . 'templates';
        $files           = self::get_template_files();
        $connected_sites = (array) ConnectedSite::get_all();

        foreach ($files as $file) {
            if (('.' === $file) || ('..' === $file) || ('index.php' === $file)) {
                continue;
            }

            foreach ($connected_sites as $connected_site) {
                $source_data                     = new stdClass();
                $source_data->media              = new stdClass();
                $source_data->filename           = $file;
                $source_data->file_contents      = file_get_contents($template_dir . '/' . $file);
                $source_data->source_upload_path = $template_dir;
                $source_data->source_upload_url  = DATA_SYNC_URL . 'templates/';
                $source_data->media->guid        = DATA_SYNC_URL . 'templates/' . $file;
                $source_data->receiver_site_id   = (int) $connected_site->id;
                $source_data->start_time         = (string) current_time('mysql', 1);

                $this->push($connected_site, $source_data);
            }
        }

        $receiver_logs = Logs::retrieve_receiver_logs($source_data->start_time);
        Logs::save_to_source($receiver_logs);

        wp_send_json_success();
    }

    public function push($connected_site, $source_data)
    {
        $auth     = new Auth();
        $json     = $auth->prepare($source_data, $connected_site->secret_key);
        $url      = trailingslashit($connected_site->url) . 'wp-json/' . DATA_SYNC_API_BASE_URL . 'templates/sync';
        $response = wp_remote_post($url, [
            'body' => $json,
            'httpversion' => '1.0',
            'sslverify' => false,
            'timeout' => 10,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            $logs = new Logs();
            $logs->set('Error in TemplateSync->push() received from ' . $connected_site->url . '. ' . $response->get_error_message(), true);
            return $response;
        } else {
            if (get_option('show_body_responses')) {
                if (get_option('show_body_responses')) {
                    echo 'Template Sync';
                    print_r(wp_remote_retrieve_body($response));
                }
            }
        }
    }

    public function receive()
    {
        $source_data = (object) json_decode(file_get_contents('php://input'));

        $this->sync($source_data);

        $logs = new Logs();
        $logs->set('Template sync complete.');
    }

    public function sync($source_data)
    {
        $result = File::copy($source_data, $source_data->file_contents);
        wp_send_json($result);
    }
}
