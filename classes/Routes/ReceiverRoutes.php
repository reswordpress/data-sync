<?php


namespace DataSync\Routes;

use WP_REST_Server;

/**
 *
 */
class ReceiverRoutes {

	const AUTH = 'DataSync\Controllers\Auth';
	public $controller_class = null;

	/**
	 * Receiver constructor.
	 */
	public function __construct( $controller ) {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->controller_class = $controller;
	}

	/**
	 *
	 */
	public function register_routes() {
		$registered = register_rest_route( DATA_SYNC_API_BASE_URL, 'sync', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this->controller_class, 'sync' ),
				'permission_callback' => array( $this::AUTH, 'authorize' ),
			),
		) );

		$registered = register_rest_route( DATA_SYNC_API_BASE_URL, 'overwrite', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this->controller_class, 'sync' ),
				'permission_callback' => array( $this::AUTH, 'authorize' ),
			),
		) );

		$registered = register_rest_route( DATA_SYNC_API_BASE_URL, 'start_fresh', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this->controller_class, 'start_fresh' ),
			),
		) );

		$registered = register_rest_route( DATA_SYNC_API_BASE_URL, 'receiver/get_data', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this->controller_class, 'give_receiver_data' ),
			),
		) );

		$registered = register_rest_route( DATA_SYNC_API_BASE_URL, 'receiver/prevalidate', array(
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this->controller_class, 'prevalidate' ),
			),
		) );
	}

}
