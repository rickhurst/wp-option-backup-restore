<?php
/**
 * Plugin Name: WP Options Backup Restore
 * Description: Backs up specified wordpress options daily, and allows restore of options via CLI
 * Author: Rick Hurst
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

namespace OptionsBackupRestore;

/** 
 * no. of backups to retain for each option
 */
define( 'OBR_BACKUP_LENGTH', 3 ); // 

/** 
 * OBR_OPTIONS
 */
if(!defined('OBR_OPTIONS')){
	define( 'OBR_OPTIONS', ['siteurl','sidebars_widgets'] ); 
}

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	add_action( 'wp_loaded', __NAMESPACE__ . '\option_backup_scheduling' );
}
add_action( __NAMESPACE__ . '-backup_options', __NAMESPACE__ . '\do_options_backup' );


/**
 * Schedule the backup event
 */
function option_backup_scheduling() {
	if ( ! wp_next_scheduled( __NAMESPACE__ . '-backup_options' ) ) {
		wp_schedule_event( time(), 'daily', __NAMESPACE__ . '-backup_options' );
	}
}

/**
 * Run the backup event
 */
function do_options_backup() {
	global $wpdb;

	// for each specified option, get current and backup array
	foreach( OBR_OPTIONS as $option ){

		$current_option = get_option( $option );

		if( false !== $current_option ){
			$backup_options  = get_option( 'obr_backup_'.$option, [] );

			$backup_options[ time() ] = $current_option; // adds to end of array
			$backup_options           = array_slice( $backup_options, ( OBR_BACKUP_LENGTH * -1 ), null, true ); // retain last 3 backups

			update_option( 'obr_backup_'.$option, $backup_options, 'no' );
		}
	}
}

/**
 * Restore options CLI
 * @package wp-cli
 */
class OBR_Restore_Options_CLI {

	/**
	 * List Backed-Up Options
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : The serialization format for the value. total_bytes displays the total size of matching options in bytes.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 *   - yaml
	 *
	 * @subcommand list
	 */
	public function list_options( $args, $assoc_args ) {

		$data = [];

		foreach( OBR_OPTIONS as $option ){

			$current_option = get_option( $option );
	
			if( false !== $current_option ){

				$backup_option = get_option('obr_backup_' . $option );

				$backup_count = is_array($backup_option) ? count($backup_option) : 0;

				$time_keys = [];

				if(is_array($backup_option)){
					$time_keys = array_keys($backup_option);
				}

				$data[] = [
					'option_name' => $option,
					'option_backup_name' => 'obr_backup_'.$option,
					'backup_count' => $backup_count,
					'time_keys' => implode(', ' , $time_keys)
				];
			}
		}

		$formatter = new \WP_CLI\Formatter( $assoc_args, [ 'option_name', 'option_backup_name', 'backup_count', 'time_keys' ], 'option_backups' );
		$formatter->display_items( $data );

	}

	/**
	 * View Specific Backup
	 *
	 * ## OPTIONS
	 *
	 * [<option_name>]
	 * : The name of the option to view
	 * 
	 * [<time_key>]
	 * : See `vip option-backup list`. Defaults to latest
	 *
	 * [--format=<format>]
	 * : Get value in a particular format.
	 * ---
	 * default: var_export
	 * options:
	 *   - var_export
	 *   - json
	 *   - yaml
	 * ---
	 *
	 */
	public function view( $args, $assoc_args ) {

		if(!isset($args[0])){
			\WP_CLI::error( 'Option name not specified.' );
		}

		$option = $args[0];

		$key = $args[1] ?? 'latest';

		if( false === get_option( 'obr_backup_' . $option) ){
			\WP_CLI::error( 'Option '.$option.' not specified.' );
		}

		$backup_values = get_option( 'obr_backup_' . $option, [] );

		if ( 'latest' === $key ) {
			$backup = array_pop( $backup_values );
		} else {
			if ( isset( $backup_values[ $key ] ) ) {
				$backup = $backup_values[ $key ];
			} else {
				\WP_CLI::error( 'Specified backup time_key not found.' );
			}
		}

		\WP_CLI::print_value( $backup, $assoc_args );

	}

	/**
	 * Restore A Backup
	 *
	 * ## OPTIONS
	 * 
	 * [<option_name>]
	 * : The name of the option to restore
	 *
	 * [<time_key>]
	 * : See `vip option-backup list`. Defaults to latest
	 *
	 * [--yes]
	 * : Skip confirmation prompt
	 */
	public function restore( $args, $assoc_args ) {

		if(!isset($args[0])){
			\WP_CLI::error( 'Option name not specified.' );
		}

		$option = $args[0];

		$key = $args[1] ?? 'latest';

		$backup_values = get_option( 'obr_backup_' . $option, [] );

		if ( 'latest' === $key ) {
			$date   = gmdate( 'Y-m-d H:i:s', array_key_last( $backup_values ) );
			$backup = array_pop( $backup_values );
		} else {
			if ( isset( $backup_values[ $key ] ) ) {
				$date   = gmdate( 'Y-m-d H:i:s', $key );
				$backup = $backup_values[ $key ];
			} else {
				\WP_CLI::error( 'Specified backup time_key not found.' );
			}
		}

		$current_value = get_option( $option );

		if ( $current_value === $backup ) {
			\WP_CLI::log( 'Selected backup matches existing value.' );
			exit;
		}

		$data[] = [
			'current_value' => $current_value,
			'backup_value' => $backup
		];


		$formatter = new \WP_CLI\Formatter( $assoc_args, [ 'current_value', 'backup_value' ], 'option_backups' );
		$formatter->display_items( $data );


		\WP_CLI::confirm( 'Okay to proceed with restoration?', $assoc_args );

		update_option( $option, $backup );

		\WP_CLI::success( sprintf( 'Restored %s option from backup', $option ) );

	}




	/**
	 * Backup Current Roles Now
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt
	 */
	public function now( $args, $assoc_args ) {

		foreach( OBR_OPTIONS as $option ){

			$backup_options = get_option( 'obr_backup_' . $option, [] );
			if ( count( $backup_options ) >= OBR_BACKUP_LENGTH ) {
				\WP_CLI::confirm( 'This will remove the oldest ' . $option . 'backup. Ok?', $assoc_args );
			}

			\OptionsBackupRestore\do_options_backup();

			\WP_CLI::success( 'Current option:'.$option.' backed up.' );
		}

	}

}

\WP_CLI::add_command( 'option-backup', __NAMESPACE__ . '\OBR_Restore_Options_CLI' );