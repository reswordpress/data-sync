<?php

namespace DataSync;

use DataSync\Controllers\Logs;
use WP_Error;

/**
 * Class Helpers
 * @package DataSync
 */
class Helpers
{

    /**
     * @param $url
     *
     * Format URL to make sure https is used
     *
     * @return string|string[]|WP_Error|null
     */
    public static function format_url($url)
    {
        $parsed_url = wp_parse_url($url);
        if (! isset($parsed_url['scheme'])) {
            $url = 'https://' . $url;
        }

        $url = preg_replace("/^http:/i", "https:", $url);

        $exploded_url = explode('.', $url);

        if (! isset($exploded_url[1])) {
            $logs = new Logs('ERROR: Connected site url could not be processed.', true);

            return new WP_Error('database_error', 'DB Logs: Connected site was not saved.', array( 'status' => 501 ));
        }

        return $url;
    }

    /**
     * @param $obj
     *
     * Recursively convert an object into an array
     *
     * @return array
     */
    public static function object_to_array($obj)
    {
        if (is_object($obj)) {
            $obj = (array) $obj;
        }
        if (is_array($obj)) {
            $new = array();
            foreach ($obj as $key => $val) {
                $new[ $key ] = self::object_to_array($val);
            }
        } else {
            $new = $obj;
        }

        return $new;
    }
}
