<?php namespace DataSync;

use DataSync\Controllers\Logs;

/**
 * Outputs HTML for settings page
 */
function data_sync_options_page() {
	?>
	<div id="feedback"></div>
	<div class="wrap">
		<h2>DATA SYNC</h2>
		<h3>Settings</h3>
		<form method="POST" action="options.php">
			<?php
			settings_fields( 'data_sync_options' );
			do_settings_sections( 'data-sync-options' );
			submit_button();
			if ( get_option( 'source_site' ) ) {
				if ( '1' === get_option ( 'debug' ) ) {
					?>
					<h2>Log</h2>
					<span id="refresh_error_log">Refresh log</span>
					<div id="error_log">
						<?php include_once 'log.php'; ?>
						<?php echo display_log(); ?>
					</div>
					<?php
				}
			}
			?>
		</form>
	</div>
	<?php
}
