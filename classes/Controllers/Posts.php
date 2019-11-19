<?php


namespace DataSync\Controllers;

use DataSync\Models\ConnectedSite;
use DataSync\Models\SyncedPost;
use WP_Query;
use stdClass;
use WP_REST_Server;
use WP_REST_Request;
use WPSEO_Meta;
use DataSync\Helpers;


class Posts {
    public $view_namespace = 'DataSync';

    public function __construct() {
        if ( '1' === get_option( 'source_site' ) ) {
            add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
            add_action( 'save_post', [ $this, 'save_custom_values' ] );
            require_once DATA_SYNC_PATH . 'views/admin/post/meta-boxes.php';
            add_filter( 'cptui_pre_register_post_type', [ $this, 'add_meta_boxes_into_cpts' ], 1 );
            add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
        } elseif ( '0' === get_option( 'source_site' ) ) {
            add_filter( 'pre_render_block', [ $this, 'update_block_id_attrs' ], 10, 2 );
        }

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function display_admin_notices() {
        $errors = get_option( 'my_admin_errors' );

        if ( $errors ) {
            echo '<div class="error"><p>' . $errors . '</p></div>';
        }
    }

    public function add_meta_boxes_into_cpts( $args ) {
        $args['register_meta_box_cb'] = [ $this, 'add_meta_boxes' ];

        return $args;
    }

    public function add_meta_boxes() {
        $registered_post_types = get_post_types( array( 'public' => true ), 'names', 'and' );
        unset( $registered_post_types['attachment'] );

        add_meta_box( 'override_post_yoast', __( 'Override Receiver Yoast Settings', 'textdomain' ), $this->view_namespace . '\add_override_post_yoast_checkbox', $registered_post_types, 'side', );

        add_meta_box( 'canonical_site', __( 'Canonical Site', 'textdomain' ), $this->view_namespace . '\add_canonical_radio_inputs', $registered_post_types, 'side', );

        add_meta_box( 'excluded_site_meta', __( 'Sites Excluded From Sync', 'textdomain' ), $this->view_namespace . '\add_excluded_sites_select_field', $registered_post_types, 'side', );
    }

    public function save_custom_values( int $post_id ) {

        // To keep the errors in
        $errors = false;

        /*
         * We need to verify this came from the our screen and with proper authorization,
         * because save_post can be triggered at other times.
         */

        // Check if our nonce is set.
        if ( ! isset( $_POST['data_sync_post_meta_box_nonce'] ) ) {
            return $post_id;
        }

        $nonce = wp_unslash( $_POST['data_sync_post_meta_box_nonce'] );

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $nonce, 'data_sync_post_meta_box' ) ) {
            return $post_id;
        }

        /*
         * If this is an autosave, our form has not been submitted,
         * so we don't want to do anything.
         */
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $post_id;
        }

        // Check the user's permissions.
        if ( 'page' == $_POST['post_type'] ) {
            if ( ! current_user_can( 'edit_page', $post_id ) ) {
                return $post_id;
            }
        } else {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return $post_id;
            }
        }

        /* OK, it's safe for us to save the data now. */
        if ( isset( $_POST['canonical_site'] ) ) {
            $data = sanitize_text_field( wp_unslash( $_POST['canonical_site'] ) );
            update_post_meta( $post_id, '_canonical_site', $data );
        } else {
            // Add an error here.
            $errors .= "A canonical site is required in post $post_id before proper post syndication to connected sites.";
        }

        if ( isset( $_POST['excluded_sites'] ) ) {
            $data           = $_POST['excluded_sites'];
            $sanitized_data = array_map( 'absint', $data );
            update_post_meta( $post_id, '_excluded_sites', $sanitized_data );
        } else {
            update_post_meta( $post_id, '_excluded_sites', 0 );
        }

        if ( isset( $_POST['override_post_yoast'] ) ) {
            update_post_meta( $post_id, '_override_post_yoast', $_POST['override_post_yoast'] );
        } else {
            update_post_meta( $post_id, '_override_post_yoast', 0 );
        }

        return true;
    }

    public static function get_all( array $types ) {
        $posts = new stdClass();

        foreach ( $types as $type ) {

            // Must convert single string to array.
            $posts->$type = self::get_wp_posts( [ $type ] );

            foreach ( $posts->$type as $post ) {
                $post->source_url = get_site_url();
                $post->post_meta  = get_post_meta( $post->ID );
                $post->taxonomies = array();

                foreach ( get_taxonomies() as $taxonomy ) {
                    $post->taxonomies[ $taxonomy ] = get_the_terms( $post->ID, $taxonomy );
                }

                $post->media = self::get_media( $post->ID );
            }
        }

        return $posts;
    }

    public static function get_single( int $id ) {
        $post             = get_post( $id );
        $post->source_url = get_site_url();
        $post->post_meta  = get_post_meta( $post->ID );
        $post->taxonomies = array();

        foreach ( get_taxonomies() as $taxonomy ) {
            $post->taxonomies[ $taxonomy ] = get_the_terms( $post->ID, $taxonomy );
        }

        $post->media = self::get_media( $id );

        return $post;
    }

    public static function get_wp_posts( array $type, $get_trashed = false ) {
        if ( $get_trashed ) {
            $statuses = array( 'publish', 'trash' );
        } else {
            $statuses = array( 'publish' );
        }

        $args = array(
            'post_type'      => $type,
            'post_status'    => $statuses,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
            'posts_per_page' => - 1, // show all posts.
        );

        $loop = new WP_Query( $args );

        return $loop->posts;
    }

    private static function get_media( int $post_id ) {
        $media = new stdClass();

        $media->image = Posts::get_images( $post_id );

        if ( has_post_thumbnail( $post_id ) ) {
            $media->featured_image              = get_post( get_post_thumbnail_id( $post_id ) );
            $media->featured_image->featured    = true;
            $media->featured_image->type        = 'image';
            $media->featured_image->post_parent = $post_id;
        }

        $media->audio = Posts::get_audio( $post_id );
        $media->video = Posts::get_video( $post_id );

        return $media;
    }

    private static function get_images( $post_id ) {
        $images          = [];
        $image_ids       = [];
        $attached_images = get_attached_media( 'image', $post_id );

        foreach ( $attached_images as $attached_image ) {
            $image_ids[] = $attached_image->ID;
        }

        // get all the galleries in the post
        $gallery_image_ids = [];
        if ( $galleries = get_post_galleries( $post_id, false ) ) {
            foreach ( $galleries as $gallery ) {

                // pull the ids from each gallery
                if ( ! empty( $gallery['ids'] ) ) {

                    // merge into our final list
                    $image_ids = array_merge( $gallery_image_ids, explode( ',', $gallery['ids'] ) );
                }
            }
        }


        $post = get_post( $post_id );

        // GET UNATTACHED POST IMAGES THAT LIVE IN BLOCKS.
        if ( has_blocks( $post->post_content ) ) {
            $blocks = parse_blocks( $post->post_content );

            foreach ( $blocks as $block ) {
                if ( ! empty( $block['attrs'] ) ) {
                    if ( 'core/image' === $block['blockName'] ) {
                        $image_ids[] = $block['attrs']['id'];
                    }
                }
            }
        }

        $unique_image_ids = array_unique( $image_ids );

        foreach ( $unique_image_ids as $image_id ) {
            $post              = get_post( $image_id );
            $post->post_parent = $post_id;
            $images[]          = $post;
        }

        return $images;
    }


    private static function get_audio( $post_id ) {
        $audio          = [];
        $audio_ids      = [];
        $attached_audio = get_attached_media( 'audio', $post_id );

        foreach ( $attached_audio as $attached_a ) {
            $audio_ids[] = $attached_a->ID;
        }

        $post = get_post( $post_id );

        // GET UNATTACHED POST IMAGES THAT LIVE IN BLOCKS.
        if ( has_blocks( $post->post_content ) ) {
            $blocks = parse_blocks( $post->post_content );

            foreach ( $blocks as $block ) {
                if ( ! empty( $block['attrs'] ) ) {
                    if ( 'core/audio' === $block['blockName'] ) {
                        $audio_ids[] = $block['attrs']['id'];
                    }
                }
            }
        }

        $unique_audio_ids = array_unique( $audio_ids );

        foreach ( $unique_audio_ids as $audio_id ) {
            $post              = get_post( $audio_id );
            $post->post_parent = $post_id;
            $audio[]           = $post;
        }

        return $audio;
    }


    private static function get_video( $post_id ) {
        $video          = [];
        $video_ids      = [];
        $attached_video = get_attached_media( 'video', $post_id );

        foreach ( $attached_video as $attached_a ) {
            $video_ids[] = $attached_a->ID;
        }

        $post = get_post( $post_id );

        // GET UNATTACHED POST IMAGES THAT LIVE IN BLOCKS.
        if ( has_blocks( $post->post_content ) ) {
            $blocks = parse_blocks( $post->post_content );

            foreach ( $blocks as $block ) {
                if ( ! empty( $block['attrs'] ) ) {
                    if ( 'core/video' === $block['blockName'] ) {
                        $video_ids[] = $block['attrs']['id'];
                    }
                }
            }
        }

        $unique_video_ids = array_unique( $video_ids );

        foreach ( $unique_video_ids as $video_id ) {
            $post              = get_post( $video_id );
            $post->post_parent = $post_id;
            $video[]           = $post;
        }

        return $video;
    }


    public function get_custom_post_meta( WP_REST_Request $request ) {
        $post_id = $request->get_param( 'id' );

        $postmeta = get_post_meta( $post_id );

        foreach ( $postmeta as $key => $meta ) {
            $postmeta[ $key ] = $meta[0];
        }

        $postmeta['_excluded_sites'] = unserialize( $postmeta['_excluded_sites'] );

        return $postmeta;
    }


    public static function get_syndication_info_of_post( $post, $connected_sites, $receiver_data ) {
        $syndication_info                             = new stdClass();
        $syndication_info->status                     = 'unsynced';
        $syndication_info->trash_class                = "";
        $syndication_info->receiver_version_edited[0] = false;
        $syndication_info->source_version_edited      = false;

        $number_of_sites_connected       = count( $connected_sites );
        $post_meta                       = get_post_meta( $post->ID );
        $excluded_sites                  = unserialize( $post_meta['_excluded_sites'][0] );
        $synced_post_result              = SyncedPost::get_where( [ 'source_post_id' => (int) filter_var( $post->ID, FILTER_SANITIZE_NUMBER_INT ) ] );
        $number_of_synced_posts_returned = count( $synced_post_result );

        // CHECK EXCLUDED SITES' DEFAULT VALUE OF 0 FOR NO EXCLUDED SITES.
        if ( 0 === (int) $excluded_sites[0] ) {
            $sites_syndicating = $number_of_sites_connected;
        } else {
            $sites_syndicating = $number_of_sites_connected - count( $excluded_sites );
        }

        if ( $number_of_synced_posts_returned ) {
            $syndication_info->source_message = '';
            $syndication_info->icon           = '';

            if ( $sites_syndicating <= $number_of_synced_posts_returned ) {
                $statuses = array(); // WILL HOLD DATA OF WHAT IS DIVERGED, UNSYNCED, OR SYNCED FROM RELATED RECEIVER POST AND SYNCED POST DATA.

                // APPEARS SYNCED, BUT CHECK MODIFIED DATE/TIME.
                foreach ( $synced_post_result as $synced_post ) {
                    $synced_post_modified_time     = strtotime( $synced_post->date_modified );
                    $source_post_modified_time     = strtotime( $post->post_modified_gmt );
                    $receiver_post                 = Posts::find_receiver_post( (array) $receiver_data, $synced_post->receiver_site_id, $synced_post->receiver_post_id );
                    $receiver_modified_time        = strtotime( $receiver_post->post_modified_gmt );
                    $syndication_info->synced_post = $synced_post;

                    if ( null !== $receiver_post ) {
                        $receiver_modified_time = strtotime( $receiver_post->post_modified_gmt );
                        if ( $receiver_modified_time > $synced_post_modified_time ) {
                            $syndication_info->receiver_version_edited = [ true, $synced_post->receiver_site_id ];
                            $statuses[]                                = 'diverged';
                        } else if ( $source_post_modified_time > $synced_post_modified_time ) {
                            $syndication_info->source_version_edited = true;
                            $statuses[]                              = 'diverged';
                        } else if ( $synced_post_modified_time >= $receiver_modified_time ) {
                            $statuses[] = 'synced';
                        } else if ( 0 === $receiver_modified_time ) {
                            $statuses[] = 'unsynced';
                        }
                    } else {
                        if ( $source_post_modified_time > $synced_post_modified_time ) {
                            $syndication_info->source_version_edited = true;
                            $statuses[]                              = 'diverged';
                        } else {
                            $statuses[] = 'unsynced';
                        }
                    }
                }

                if ( ( in_array( 'diverged', $statuses ) ) && ( ! in_array( 'unsynced', $statuses ) ) && ( ! in_array( 'synced', $statuses ) ) ) {
                    // ALL POSTS ARE DIVERGED.
                    $syndication_info->status = 'diverged';
                } elseif ( ( ! in_array( 'diverged', $statuses ) ) && ( ! in_array( 'unsynced', $statuses ) ) && ( in_array( 'synced', $statuses ) ) ) {
                    // ALL POSTS SYNCED.
                    $syndication_info->status = 'synced';
                } elseif ( ( ! in_array( 'diverged', $statuses ) ) && ( in_array( 'unsynced', $statuses ) ) && ( ! in_array( 'synced', $statuses ) ) ) {
                    // ALL POSTS UNSYNCED.
                    $syndication_info->status = 'unsynced';
                } elseif ( ( in_array( 'diverged', $statuses ) ) && ( in_array( 'unsynced', $statuses ) ) && ( in_array( 'synced', $statuses ) ) ) {
                    // SOME POSTS ARE DIVERGED, UNSYNCED, AND SYNCED.
                    $syndication_info->status = 'partial';
                } elseif ( ( in_array( 'diverged', $statuses ) ) && ( ! in_array( 'unsynced', $statuses ) ) && ( in_array( 'synced', $statuses ) ) ) {
                    // SOME POSTS ARE DIVERGED AND SYNCED.
                    $syndication_info->status = 'partial';
                } elseif ( ( in_array( 'diverged', $statuses ) ) && ( in_array( 'unsynced', $statuses ) ) && ( ! in_array( 'synced', $statuses ) ) ) {
                    // SOME POSTS ARE DIVERGED AND UNSYNCED.
                    $syndication_info->status = 'diverged';
                }
            } elseif ( 0 === $sites_syndicating ) {
                $syndication_info->status = 'unsynced';
            } elseif ( ( 0 < $sites_syndicating ) && ( $number_of_synced_posts_returned < $sites_syndicating ) ) {
                $syndication_info->status = 'partial';
            }
        }

        if ( 'trash' === $post->post_status ) {
            $syndication_info->status      = 'trashed';
            $syndication_info->trash_class = "trashed";
        }

        if ( 'synced' === $syndication_info->status ) {
            $syndication_info->icon           = '<i class="dashicons dashicons-yes" title="Synced on all connected sites."></i>';
            $syndication_info->source_message = '<span class="success">All good here!</span>';
        } elseif ( 'diverged' === $syndication_info->status ) {
            if ( ( $source_post_modified_time > $synced_post_modified_time ) && ( $number_of_synced_posts_returned ) ) {
                $syndication_info->source_message = '<span class="warning">Source updated since last sync.</span>';
            } elseif ( $receiver_modified_time > $synced_post_modified_time ) {
                $syndication_info->source_message = '<span class="warning">A receiver post was updated after the last sync.</span>';
            }

            $syndication_info->icon           = '<i class="dashicons dashicons-editor-unlink" title="A receiver post was updated after the last sync. Click to overwrite with source post." data-receiver-site-id="' . $synced_post->receiver_site_id . '" data-source-post-id="' . $post->ID . '"></i>';
            $syndication_info->source_message .= '<button class="button danger_button push_post_now" id="push_post_now_' . $post->ID . '" data-source-post-id="' . $post->ID . '">Overwrite all receivers</button></span>';
        } elseif ( 'partial' === $syndication_info->status ) {
            $syndication_info->icon           = '<i class="dashicons dashicons-info" title="Partially synced."></i>';
            $syndication_info->source_message = '<span class="warning">Partially syndicated. Some posts may have failed to syndicate with or were updated more recently on a connected site. Please check connected site info and logs for more details.</span>';
            $syndication_info->source_message .= '<button class="button danger_button push_post_now" id="push_post_now_' . $post->ID . '" data-source-post-id="' . $post->ID . '">Overwrite all receivers</button></span>';
        } elseif ( 'unsynced' === $syndication_info->status ) {
            $syndication_info->icon           = '<i class="dashicons dashicons-warning warning" title="Not synced. Sync now or check error log if problem persists."></i>';
            $syndication_info->source_message = '<span class="warning">Unsynced. Please check connected site info or logs for more details.</span>';
            $syndication_info->source_message .= '<button class="button danger_button push_post_now" id="push_post_now_' . $post->ID . '" data-source-post-id="' . $post->ID . '">Overwrite all receivers</button></span>';
        } elseif ( 'trashed' === $syndication_info->status ) {
            $syndication_info->icon           = '<i class="dashicons dashicons-trash" title="Trashed at source but still live on receivers. To delete on receivers, delete permanently at source."></i>';
            $syndication_info->source_message = '<span class="warning">This post is in the trash, but is still on receiver sites (if it was previously synced). Please delete from trash/permanent delete) to remove from receiver sites.</span>';
        }


        return $syndication_info;
    }


    public static function find_receiver_post( array $receiver_data, $site_id, $receiver_post_id ) {
        foreach ( $receiver_data as $receiver ) {
            if ( (int) $site_id === $receiver->site_id ) {
                foreach ( $receiver->posts as $receiver_post ) {
                    if ( (int) $receiver_post_id === $receiver_post->ID ) {
                        return $receiver_post;
                    }
                }
            }
        }

    }


    public static function get_all_receiver_posts( $connected_sites ) {
        $receiver_posts = array();
        $index          = 0;

        foreach ( $connected_sites as $site ) {
            $url      = Helpers::format_url( trailingslashit( $site->url ) . 'wp-json/' . DATA_SYNC_API_BASE_URL . '/posts/all' );
            $response = wp_remote_get( $url );

            if ( is_wp_error( $response ) ) {
                $logs = new Logs();
                $logs->set( 'Error in Post::get_receiver_post received from ' . get_site_url() . '. ' . $response->get_error_message(), true );

                return $response;
            } else {
                $receiver_posts[ $index ]          = new stdClass();
                $receiver_posts[ $index ]->site_id = (int) $site->id;
                $receiver_posts[ $index ]->posts   = json_decode( wp_remote_retrieve_body( $response ) ); // Receiver post object.
                $index ++;
            }
        }

        return $receiver_posts;
    }


    public static function get_receiver_post( $receiver_post_id, $site_id ) {
        $connected_site = ConnectedSite::get( $site_id )[0];
        $url            = Helpers::format_url( trailingslashit( $connected_site->url ) . 'wp-json/' . DATA_SYNC_API_BASE_URL . '/posts/' . $receiver_post_id );
        $response       = wp_remote_get( $url );

        if ( is_wp_error( $response ) ) {
            $logs = new Logs();
            $logs->set( 'Error in Post::get_receiver_post received from ' . get_site_url() . '. ' . $response->get_error_message(), true );

            return $response;
        } else {
            return (object) json_decode( wp_remote_retrieve_body( $response ) ); // Receiver post object.
        }
    }

    public function get_post( WP_REST_Request $request ) {
        return get_post( $request->get_param( 'id' ) );
    }

    public function get_all_posts() {
        $statuses = array( 'publish', 'trash' );

        $args = array(
            'post_type'      => 'any',
            'post_status'    => $statuses,
            'orderby'        => 'post_date',
            'order'          => 'DESC',
            'posts_per_page' => - 1, // show all posts.
        );

        $loop = new WP_Query( $args );

        return $loop->posts;
    }


    public static function save( object $post, array $synced_posts ) {
        $post_array = (array) $post; // must convert to array to use wp_insert_post.

        // MUST UNSET ID TO INSERT. PROVIDE ID TO UPDATE.
        unset( $post_array['ID'] );

        if ( $post->synced ) {
            $post_array['ID'] = SyncedPosts::get_receiver_post_id( $post, $synced_posts );
        }

        unset( $post_array['post_meta'] );
        unset( $post_array['taxonomies'] );
        unset( $post_array['media'] );

        // Don't change URLs of media that needs to be migrated.
        if ( 'attachment' !== $post->post_type ) {
            unset( $post_array['guid'] );
            // FIX ANY URLS THAT WOULD POSSIBLY BE INCORRECT.
            $upload_dir = wp_get_upload_dir();
            $post_array['post_content'] = str_replace( $post_array['source_url'] . '/wp-content/uploads', $upload_dir['baseurl'], $post_array['post_content'] );
        }

        $receiver_post_id = wp_insert_post( $post_array );

        if ( is_wp_error( $receiver_post_id ) ) {
            $logs = new Logs();
            $logs->set( $receiver_post_id->get_error_message(), true );

            return false;
        } elseif ( $receiver_post_id ) {
            $receiver_post_id = (int) $receiver_post_id;

            if ( 'attachment' !== $post->post_type ) {
                $override_post_yoast = (bool) $post->post_meta->_override_post_yoast[0];
                $yoast_meta_prefix   = WPSEO_Meta::$meta_prefix;

                // Yoast and ACF data will be in here.
                foreach ( $post->post_meta as $meta_key => $meta_value ) {

                    // IF POST IS ALREADY SYNCED AND THE POST-LEVEL SETTING DOES NOT ALLOW OVERWRITING OF YOAST DATA, UNSET/DELETE SOURCE YOAST DATA SO IT DOESN'T OVERWRITE RECEIVER YOAST DATA.
                    if ( ( ! $override_post_yoast ) && ( false !== strpos( $meta_key, $yoast_meta_prefix ) ) && ( $post->synced ) ) {
                        unset( $post->post_meta->$meta_key ); // DELETES SOURCE POST'S META DATA RELATED TO YOAST TO NOT OVERWRITE.
                        continue;
                    }

                    foreach ( $meta_value as $value ) {
                        $updated = update_post_meta( $receiver_post_id, $meta_key, $value );
                    }
                }
            }

            SyncedTerms::save_to_wp( $receiver_post_id, $post->taxonomies );

            return $receiver_post_id;
        }
    }


    public function update_block_id_attrs( $null_block, $block ) {
        if ( ! get_option( 'source_site' ) ) {
            // UPDATE BLOCK ID
            if ( ( ! empty( $block['attrs'] ) ) && ( ! empty( $block['attrs']['id'] ) ) ) {
                $args        = array(
                    'receiver_site_id' => (int) get_option( 'data_sync_receiver_site_id' ),
                    'source_post_id'   => (int) $block['attrs']['id'],
                );
                $synced_post = SyncedPost::get_where( $args );
                if ( ! empty( $synced_post ) ) {
                    if ( (int) $block['attrs']['id'] !== (int) $synced_post[0]->receiver_post_id ) {
                        $block['attrs']['id'] = (int) $synced_post[0]->receiver_post_id;
                        //					$guid = get_post( (int) $synced_post[0]->receiver_post_id )->guid;
                        //					preg_replace("~src=[']([^']+)[']~", 'src="' . $guid . '"', $block['innerHTML']);

                        return render_block( $block );
                    }
                }
            }
        }
    }

    public function register_routes() {
        $registered = register_rest_route( DATA_SYNC_API_BASE_URL, '/post_meta/(?P<id>\d+)', array(
            array(
                'methods'  => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_custom_post_meta' ),
                'args'     => array(
                    'id' => array(
                        'description'       => 'ID of post',
                        'type'              => 'int',
                        'validate_callback' => 'is_numeric',
                    ),
                ),
            ),
        ) );
        $registered = register_rest_route( DATA_SYNC_API_BASE_URL, '/posts/(?P<id>\d+)', array(
            array(
                'methods'  => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_post' ),
                'args'     => array(
                    'id' => array(
                        'description' => 'ID of post',
                        'type'        => 'int',
                    ),
                ),
            ),
        ) );
        $registered = register_rest_route( DATA_SYNC_API_BASE_URL, '/posts/all', array(
            array(
                'methods'  => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_all_posts' ),
            ),
        ) );
        $registered = register_rest_route( DATA_SYNC_API_BASE_URL, '/status_data/(?P<post_id>\d+)', array(
            array(
                'methods'  => WP_REST_Server::EDITABLE,
                'callback' => array( $this, 'get_syndicated_post_status_data' ),
                'args'     => array(
                    'post_id' => array(
                        'description' => 'ID of post',
                        'type'        => 'int',
                    ),
                ),
            ),
        ) );

    }
}
