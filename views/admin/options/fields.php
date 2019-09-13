<?php namespace DataSync;

use DataSync\Controllers\Auth;
use DataSync\Controllers\ConnectedSites;
use DataSync\Controllers\Receiver;
use WP_User_Query;
use DataSync\Controllers\Logs;

/**
 *
 */
function display_source_input() { ?>
  <input type="radio" name="source_site" id="source_site"
         value="1" <?php checked( '1', get_option( 'source_site' ) ); ?>/> Source
  <br>
  <input type="radio" name="source_site" id="source_site"
         value="0" <?php checked( '0', get_option( 'source_site' ) ); ?>/> Receiver
	<?php
}


/**
 *
 */
function display_push_enabled_post_types() {
	$args = array(
		'public' => true,
		// '_builtin' => false
	);

	$output   = 'names'; // Names or objects, note names is the default.
	$operator = 'and';

	$registered_post_types = get_post_types( $args, $output, $operator );
	?>
  <select name="push_enabled_post_types[]" multiple id="push_enabled_post_types">
	  <?php
	  foreach ( $registered_post_types as $key => $post_type ) {
		  if ( ( 'page' === $post_type ) || ( 'attachment' === $post_type ) ) {
			  continue;
		  }
		  $post_type_object = get_post_type_object( $post_type );
		  // DO NOT SEPARATE OUT OPTION CODE INTO DIFFERENT LINES. IT MAKES THE DATA SAVE WITH LINE BREAKS.
		  ?>
        <option value="<?php echo esc_html( $post_type_object->name ); ?>" <?php echo selected( in_array( trim( $post_type_object->name ), get_option( 'push_enabled_post_types' ) ) ); ?>><?php echo esc_html( $post_type_object->label ); ?></option>
		  <?php
	  }
	  ?>
  </select>
	<?php
}


function display_auto_add_cpt_checkbox() {
	?>
  <input name='enable_new_cpts' type="checkbox" value="1" <?php checked( get_option( 'enable_new_cpts' ), 1 ); ?>>
	<?php
}



function display_secret_key() {

	$auth = new Auth();
	$secret_key = $auth->generate_key();
	$saved_secret_key = get_option( 'secret_key' );
	if ( $saved_secret_key ) {
		$secret_key = $saved_secret_key;
	}
	?>
	<input type="text" name="secret_key" value="<?php echo $secret_key; ?>" id="secret_key"/>
	<?php
}

/**
 *
 */
function display_connected_sites() {
	$connected_sites_obj = new ConnectedSites();
	$connected_sites     = $connected_sites_obj->get_all()->data;
	?>
  <table id="connected_sites">
    <thead>
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>URL</th>
      <th>Connected</th>
      <th>Remove</th>
    </tr>
    </thead>

    <tbody>
	<?php
	if ( is_array( $connected_sites ) ) {
		foreach ( $connected_sites as $site ) {
			$time = strtotime( $site->date_connected );

			?>
          <tr id="site-<?php echo esc_html( $site->id ); ?>">
            <td id="id"><?php echo esc_html( $site->id ); ?></td>
            <td id="name"><?php echo esc_html( $site->name ); ?></td>
            <td id="url"><?php echo esc_url( $site->url ); ?></td>
            <td id="date_connected"><?php echo esc_html( date( 'g:i a - F j, Y', $time ) ); ?></td>
            <td id="site-<?php echo esc_html( $site->id ); ?>"><span class="dashicons dashicons-trash remove_site"></span></td>
          </tr>
			<?php
		}
	}
	?>
    </tbody>
  </table>
    <button class="btn button-primary" id="add_site">Add Site</button>
  <input type="hidden" name="connected_sites[]" value="<?php echo esc_html( $connected_sites ); ?>"/>
	<?php

	display_connected_sites_modal();
}



/**
 *
 */
function display_connected_sites_modal() {
	// TODO: Improve modal design
	?>
  <div class="lightbox_wrap">
    <div class="add_site_modal">
      <a id="close">X</a>
      <h2>Add New Site</h2>
      <form>
        <div class="input_wrap">
          <label for="name">Site Name</label>
          <input type="text" name="name" value="" id="site_name"/>
        </div>

        <div class="input_wrap">
          <label for="url">Site URL</label>
          <input type="text" name="url" value="" id="site_url"/>
        </div>
	      <div class="input_wrap">
		      <label for="url">Secret key</label>
		      <input name="secret_key" id="site_secret_key" value=""/>
	      </div>

        <p class="submit"><input type="submit" name="submit_site" id="submit_site" class="button button-primary"
                                 value="Add Site"></p>
      </form>
    </div>
  </div>
	<?php
}

/**
 *
 */
function display_notified_users() {
	$users_query = new WP_User_Query(
		array(
			// 'role' => 'administrator',
			'orderby' => 'display_name',
		)
	);  // query to get admin users

	$users = $users_query->get_results();

	$notified_users = get_option( 'notified_users' );

	if ( ( false === $notified_users ) || ( '' === $notified_users ) ) {
		$notified_users = array();
	}

	?>
  <select name="notified_users[]" multiple>
	  <?php

	  foreach ( $users as $user ) {
		  ?>
        <option
            value="<?php echo $user->ID; ?>" <?php selected( in_array( $user->ID, $notified_users ) ); ?>><?php echo $user->user_nicename; ?></option>
		  <?php
	  }
	  ?>
  </select>
	<?php
}


function display_awareness_messages() {

	if ( ! is_plugin_active( 'advanced-custom-fields-pro/acf.php' ) ) {
		?>
		<span style="color: red">ACF needs to be installed or activated on this site.</span><br>
		<?php
	}

	if ( ! is_plugin_active( 'custom-post-type-ui/custom-post-type-ui.php' ) ) {
		?>
		<span style="color: red">CPT UI needs to be installed or activated on this site.</span>
		<?php
	}

	if ( '1' === get_option( 'source_site' ) ) {
		$plugins = get_plugins();

		$source_acf_version = $plugins['advanced-custom-fields-pro/acf.php']['Version'];
		$source_cptui_version = $plugins['custom-post-type-ui/custom-post-type-ui.php']['Version'];

		$receiver_plugin_versions = Receiver::get_receiver_plugin_versions();

		foreach ( $receiver_plugin_versions as $site_plugin_data ) {
			if ( $source_acf_version !== $site_plugin_data['versions']->acf ) {
				?>
                <span style="color: red">ACF's plugin version is different on <a target="_blank" href="<?php echo $site_plugin_data['site_admin_url']; ?>"><?php echo $site_plugin_data['site_name']?></a>.</span><br>
				<?php
			}

			if ( $source_cptui_version !== $site_plugin_data['versions']->cptui ) {
				?>
                <span style="color: red">CPT UI's plugin version is different on <a target="_blank" href="<?php echo $site_plugin_data['site_admin_url']; ?>"><?php echo $site_plugin_data['site_name']?></a>.</span><br>
				<?php
			}
		}
    }

}

function display_debug_checkbox() {
	?>
	<input type="checkbox" value="1" name="debug" <?php checked( '1', get_option( 'debug' ) ); ?>/>
	<?php
}

function display_overwrite_yoast_checkbox() {
	?>
	<input type="checkbox" value="1" name="overwrite_yoast" <?php checked( '1', get_option( 'overwrite_yoast' ) ); ?>/>
	<?php
}

function display_overwrite_receiver_post_checkbox() {
	?>
	<input type="checkbox" value="1" name="overwrite_receiver_post_on_conflict" <?php checked( '1', get_option( 'overwrite_receiver_post_on_conflict' ) ); ?>/>
	<?php
}

function display_show_body_responses_checkbox() {
	?>
	<span>This will break the dashboard widget's functionality.</span><br>
	<span>Only use if you're debugging the <a href="/wp-json/data-sync/v1/source_data/bulk_push" target="_blank">push page</a></span><br>
	<input type="checkbox" value="1" name="show_body_responses" <?php checked( '1', get_option( 'show_body_responses' ) ); ?>/>
	<?php
}

function display_start_fresh_link() {
    ?>
    <span><a href="/wp-json/data-sync/v1/source_data/start_fresh" target="_blank">Starting fresh</a> will truncate these tables on each receiver site:</span>
    <ol>
        <li><code>data_sync_custom_post_types</code></li>
        <li><code>data_sync_custom_taxonomies</code></li>
        <li><code>data_sync_log</code></li>
        <li><code>data_sync_posts</code></li>
        <li><code>posts</code></li>
        <li><code>postmeta</code></li>
        <li><code>terms</code></li>
        <li><code>termmeta</code></li>
        <li><code>term_taxonomy</code></li>
        <li><code>term_relationships</code></li>
    </ol>
    <?php
}

function display_post_types_to_accept() {
	$args     = array(
		'public' => true,
		// '_builtin' => false
	);
	$output   = 'names'; // names or objects, note names is the default
	$operator = 'and'; // 'and' or 'or'

	$registered_post_types = get_post_types( $args, $output, $operator );

	$allowed_post_types = get_option( 'enabled_post_types' );
	if ( ! $allowed_post_types ) {
	    $allowed_post_types = array();
    }

	?>
  <select name="enabled_post_types[]" multiple id="enabled_post_types">
	  <?php

	  foreach ( $registered_post_types as $key => $post_type ) {
		  if ( ( 'page' === $post_type ) || ( 'attachment' === $post_type ) ) {
			  continue;
		  }
		  $post_type_object = get_post_type_object( $post_type );
		  ?>
          <option value="<?php echo esc_html( $post_type_object->name ); ?>" <?php echo selected( in_array( trim( $post_type_object->name ), $allowed_post_types ) ); ?>><?php echo esc_html( $post_type_object->label ); ?></option>
		  <?php
	  }
	  ?>
  </select>
	<?php
}

/**
 * @param $post_type_object
 */
function display_post_type_permissions_options( $post_type_object ) {
	$post_type_object = $post_type_object[0];
	?>
  <select name="<?php echo $post_type_object->name . '_perms[]'; ?>" multiple>
    <option
        value="create_posts" <?php selected( in_array( 'create_posts', get_option( $post_type_object->name . '_perms' ) ) ); ?>>
      Create Posts<br>
    <option
        value="create_terms" <?php selected( in_array( 'create_terms', get_option( $post_type_object->name . '_perms' ) ) ); ?>>
      Create Terms<br>
    <option
        value="edit_content" <?php selected( in_array( 'edit_content', get_option( $post_type_object->name . '_perms' ) ) ); ?>>
      Edit Content<br>
    <option
        value="edit_status" <?php selected( in_array( 'edit_status', get_option( $post_type_object->name . '_perms' ) ) ); ?>>
      Edit Status & Visibility<br>
  </select>
	<?php
}
