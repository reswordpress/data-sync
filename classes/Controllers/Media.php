<?php


namespace DataSync\Controllers;

use DataSync\Controllers\File;
use DataSync\Models\DB;
use DataSync\Models\SyncedPost;
use DataSync\Routes\MediaRoutes;
use stdClass;
use DataSync\Models\ConnectedSite;
use DataSync\Models\Log;

/**
 * Class Media
 *
 * @package DataSync\Controllers
 */
class Media {

	/**
	 * Media constructor.
	 *
	 * @param null $all_posts
	 */
	public function __construct() {
		new MediaRoutes( $this );
		// add_action( 'updated_postmeta', array( $this, 'update_post_modified' ), 4, 10 );
	}

	public function update_post_modified( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( ( $object_id ) && ( '_featured_image' === $meta_key ) ) {
			// Get the current time
			$time = current_time( 'mysql' );
			// Form an array of data to be updated
			$post_data = array(
				'ID'                => $object_id,
				'post_modified'     => $time,
				'post_modified_gmt' => get_gmt_from_date( $time ),
			);
			// Update the post
			wp_update_post( $post_data );
		}
	}


	public function prep( \WP_REST_Request $request ) {

		$source_data  = json_decode( $request->get_body() );
		$all_posts    = $source_data->posts;
		$site         = $source_data->site;
		$synced_posts = new SyncedPosts();
		$synced_posts = (array) $synced_posts->get_all()->get_data();

		$this->media = array();

		foreach ( $all_posts as $post_type ) {
			foreach ( $post_type as $post ) {
				$image_attachments = (array) $post->media->image;
				foreach ( $image_attachments as $key => $image ) {
					foreach ( $synced_posts as $synced_post ) {
						if ( ( (int) $image->post_parent === (int) $synced_post->source_post_id ) && ( (int) $site->id === (int) $synced_post->receiver_site_id ) ) {
							$image->receiver_post_id = $synced_post->receiver_post_id;
							$image->featured         = false;
							$image->type             = 'image';
							$this->media[]           = $image;
						}
					}
				}

				if ( has_post_thumbnail( $post->ID ) ) {
					$featured_image = $post->media->featured_image;
					foreach ( $synced_posts as $synced_post ) {
						if ( ( (int) $featured_image->post_parent === (int) $synced_post->source_post_id ) && ( (int) $site->id === (int) $synced_post->receiver_site_id ) ) {
							$featured_image->receiver_post_id = $synced_post->receiver_post_id;
							$this->media[]                    = $featured_image;
						}
					}
				}

				$audio_attachments = (array) $post->media->audio;
				foreach ( $audio_attachments as $key => $audio ) {
					foreach ( $synced_posts as $synced_post ) {
						if ( ( (int) $audio->post_parent === (int) $synced_post->source_post_id ) && ( (int) $site->id === (int) $synced_post->receiver_site_id ) ) {
							$audio->receiver_post_id = $synced_post->receiver_post_id;
							$audio->featured         = false;
							$audio->type             = 'audio';
							$this->media[]           = $audio;
						}
					}
				}

				$video_attachments = (array) $post->media->video;
				foreach ( $video_attachments as $key => $video ) {
					foreach ( $synced_posts as $synced_post ) {
						if ( ( (int) $video->post_parent === (int) $synced_post->source_post_id ) && ( (int) $site->id === (int) $synced_post->receiver_site_id ) ) {
							$video->receiver_post_id = $synced_post->receiver_post_id;
							$video->featured         = false;
							$video->type             = 'video';
							$this->media[]           = $video;
						}
					}
				}
			}
		}

		$this->json = array();
		$url        = get_site_url();
		$options    = Options::source();

		foreach ( $this->media as $media ) {
			$path                            = wp_parse_url( $media->guid ); // ['host'], ['scheme'], and ['path'].
			$data                            = new stdClass();
			$data->media                     = $media;
			$data->media_package             = true;
			$data->options                   = $options;
			$data->receiver_parent_post_type = get_post_type( (int) $media->post_parent );
			$data->filename                  = basename( $path['path'] );
			$data->receiver_site_id          = (int) $site->id;
			$data->receiver_site_url         = $site->url;
			$data->url                       = $url;
			$data->start_time                = (string) current_time( 'mysql', 1 );

			$excluded = $this->check_parent_isnt_excluded( $data, $site );

			if ( $excluded ) {
				continue;
			}

			$auth         = new Auth();
			$this->json[] = $auth->prepare( $data, $site->secret_key );

		}

		return $this->json;

	}


	public function check_parent_isnt_excluded( $data, $site ) {
		$parent_post_meta = get_post_meta( $data->media->post_parent );
		$excluded_sites   = unserialize( $parent_post_meta['_excluded_sites'][0] );

		if ( in_array( (int) $site->id, $excluded_sites ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 *
	 */
	public function update( $media ) {
		$receiver_options = (object) Options::receiver();

		// CHECK IF PARENT POST TYPE MATCHES ENABLED POST TYPES ON RECEIVER.
		if ( in_array( $media->receiver_parent_post_type, $receiver_options->enabled_post_types ) ) {

			$args        = array(
				'receiver_site_id' => (int) get_option( 'data_sync_receiver_site_id' ),
				'source_post_id'   => (int) $media->media->post_parent,
			);
			$synced_post = SyncedPost::get_where( $args );

			// Check if image's parent is diverged and shouldn't be synced.
			if ( ! empty( $synced_post ) ) {
				if ( ( '1' !== $synced_post[0]->diverged ) || ( true === $media->options->overwrite_receiver_post_on_conflict ) ) {
					$this->insert_into_wp( $media );
				}
			}
		}

	}


	/**
	 */
	public function insert_into_wp( object $source_data ) {
		$upload_dir = wp_get_upload_dir();
		$subfolder  = explode( 'wp-content/uploads', dirname( $source_data->media->guid ) )[1];
		$file_path  = $upload_dir['basedir'] . $subfolder . '/' . $source_data->filename;
		$file_url   = $upload_dir['baseurl'] . $subfolder . '/' . $source_data->filename;

		$result = File::copy( $source_data );

		if ( $result ) {
			$wp_filetype = wp_check_filetype( $source_data->filename, null );

			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_parent'    => (int) $source_data->media->receiver_post_id,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $source_data->filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => (string) $file_url,
			);

			$args              = array(
				'receiver_site_id' => (int) get_option( 'data_sync_receiver_site_id' ),
				'source_post_id'   => (int) $source_data->media->ID,
			);
			$synced_media_post = SyncedPost::get_where( $args );

			// SET DIVERGED TO FALSE TO OVERWRITE EVERY TIME.
			$source_data->media->diverged = false;

			if ( count( $synced_media_post ) ) {
				$source_data->media->diverged = false;
				$attachment_id                = $synced_media_post[0]->receiver_post_id;
			} else {
				$attachment_id = wp_insert_attachment( $attachment, $file_path, (int) $source_data->media->receiver_post_id );
			}

			if ( ! is_wp_error( $attachment_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				if ( ( 'audio' !== $source_data->media->type ) && ( 'video' !== $source_data->media->type ) ) {
					$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
					$updated_meta    = wp_update_attachment_metadata( $attachment_id, $attachment_data );
				}
				$source_data->media->diverged = false;
				SyncedPosts::save_to_receiver( $attachment_id, $source_data->media );
				if ( $source_data->media->featured ) {
					$this->update_thumbnail_id( $source_data->media, (int) $attachment_id );
				}
			} else {
				$logs = new Logs();
				$logs->set( 'Post not uploaded and attached to ' . $source_data->media->post_title, true );
			}
		}
	}


	/**
	 * This makes sure the parent's thumbnail id to the attached image (featured image) is updated.
	 */
	private function update_thumbnail_id( $post, $attachment_id ) {

		$args                     = array(
			'receiver_site_id' => (int) get_option( 'data_sync_receiver_site_id' ),
			'source_post_id'   => $post->post_parent,
		);
		$synced_media_post_parent = SyncedPost::get_where( $args );
		if ( $synced_media_post_parent ) {
			$updated = update_post_meta( (int) $synced_media_post_parent[0]->receiver_post_id, '_thumbnail_id', $attachment_id );
		} else {
			$logs = new Logs();
			$logs->set( 'Post thumbnail not updated for ' . $post->post_title, true );
		}
	}

	public function repair_acf_media_ids() {

		$post_types = Options::receiver()->enabled_post_types;
		$posts      = Posts::get_all( $post_types );

		foreach ( $posts as $post_types ) {
			foreach ( $post_types as $post ) {

				$orphaned_media = get_post_meta( $post->ID, 'orphaned_media' );

				if ( ! empty( $orphaned_media ) ) {
					$orphaned_media = $orphaned_media[0];
					foreach ( $orphaned_media as $orphan ) {
						$args = array(
							'receiver_site_id' => (int) get_option( 'data_sync_receiver_site_id' ),
							'source_post_id'   => $orphan['source_post_id'],
						);

						$synced_post = SyncedPost::get_where( $args );

						if ( $synced_post ) {
							$updated = update_post_meta( $post->ID, $orphan['meta_key'], (int) $synced_post[0]->receiver_post_id );
						}
					}
					$deleted = delete_post_meta( $post->ID, 'orphaned_media' );
				}
			}
		}

	}

}
