<?php


namespace DataSync\Controllers;


use WP_REST_Server;

class Receiver {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$registered = register_rest_route(
			DATA_SYNC_API_BASE_URL,
			'/receive',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => array( __NAMESPACE__ . '\Auth', 'permissions' ),
				),
			)
		);
	}

	public function receive() {


		if ( isset( $_POST ) ) {
			$verified        = Auth::verify_request( wp_unslash( $_POST['source']['nonce'] ) );
			$source_data     = wp_unslash( $_POST );
			$source_options  = $source_data['source']['options'];
			$connected_sites = $source_data['source']['connected_sites'];

			$receiver_options                            = Options::get_all_receiver()->data;
			$receiver_options['add_and_enable_new_cpts'] = true;
			$post_types_to_import                        = array();

			Posts::process_post_types( $receiver_options, $source_options );

			foreach ( $receiver_options['enabled_post_types'] as $post_type ) {
				$post_types_to_import[] = $source_data[ $post_type ];
			}

//			print_r( $receiver_options );
//			print_r( $source_data );
		}

	}

}