<?php

/**
 * Plugin Name:       Import the WordCamp Schedule to The Events Calendar
 * Plugin URI:        https://github.com/bamadesigner/wordcamp-importer-tec
 * Description:       Imports the WordCamp schedule as events for The Events Calendar WordPress plugin.
 * Version:           1.0.0
 * Author:            Rachel Carden
 * Author URI:        https://bamadesigner.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wordcamp-importer-tec
 * Domain Path:       /languages
 */

// @TODO setup process to check each event to see if it needs to be deleted

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// If you define them, will they be used?
define( 'WORDCAMP_IMPORTER_TEC_VERSION', '1.0.0' );

class WordCamp_Importer_TEC {

	/**
	 * Holds the class instance.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @var		WordCamp_Importer_TEC
	 */
	private static $instance;

	/**
	 * Returns the instance of this class.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @return	WordCamp_Importer_TEC
	 */
	public static function instance() {
		if ( ! isset( static::$instance ) ) {
			$className = __CLASS__;
			static::$instance = new $className;
		}
		return static::$instance;
	}

	/**
	 * Warming up the engines.
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	protected function __construct() {

		// Load our textdomain
		add_action( 'init', array( $this, 'textdomain' ) );

		// Runs on install
		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Runs when the plugin is upgraded
		add_action( 'upgrader_process_complete', array( $this, 'upgrader_process_complete' ), 1, 2 );

		// Check to see if we need to import the WordCamp schedule
		add_action( 'admin_init', array( $this, 'check_import_wordcamp_schedule' ) );

	}

	/**
	 * Method to keep our instance from being cloned.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @return	void
	 */
	private function __clone() {}

	/**
	 * Method to keep our instance from being unserialized.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @return	void
	 */
	private function __wakeup() {}

	/**
	 * Runs when the plugin is installed.
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	public function install() {}

	/**
	 * Runs when the plugin is upgraded.
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	public function upgrader_process_complete() {}

	/**
	 * Internationalization FTW.
	 * Load our textdomain.
	 *
	 * @TODO Add language files
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	public function textdomain() {
		load_plugin_textdomain( 'wordcamp-importer-tec', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Runs a check in the admin to
	 * import the schedule once every 24 hours.
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	public function check_import_wordcamp_schedule() {

		// See if we need to check the import
		$check_import_transient = 'wordcamp_importer_tec_check_import';
		$check_import = get_transient( $check_import_transient );
		if ( $check_import === false ) {

			// Import the schedule
			$this->import_wordcamp_schedule();

			// Only check the schedule once a day
			set_transient( $check_import_transient, time(), DAY_IN_SECONDS );

		}

	}

	/**
	 * Imports the schedule.
	 *
	 * @access  public
	 * @since   1.0.0
	 */
	public function import_wordcamp_schedule() {
		global $wpdb;

		// There's no point if TEC doesn't exist
		if ( ! function_exists( 'tribe_create_event' ) ) {
			return false;
		}

		// Get the schedule
		if ( ( $response = wp_remote_get( 'https://central.wordcamp.org/wp-json/posts?type=wordcamp&filter[posts_per_page]=30' ) )
			&& ( $body = wp_remote_retrieve_body( $response ) )
			&& ( $schedule = json_decode( $body ) ) ) {

			// Get current WordCamp IDs so we don't duplicate events
			$wordcamp_ids = $wpdb->get_col( "SELECT meta.meta_value FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id AND posts.post_type = 'tribe_events' WHERE meta.meta_key = '_wordcamp_id'" );

			// Set datetime format
			$date_format = 'Y-m-d'; // H:i:s';

			foreach( $schedule as $event ) {

				// Get post ID
				$event_post_id = in_array( $event->ID, $wordcamp_ids ) ? $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wordcamp_id' AND meta_value = '{$event->ID}'" ) : false;

				// Setup event args
				$event_args = array(
					'post_title' 	=> $event->title,
					'post_content' 	=> $event->content,
					'post_status'	=> 'publish',
					'EventAllDay'	=> true,
					'EventTimezone'	=> $event->date_tz,
				);

				// Set timezone
				$event_timezone = new DateTimeZone( $event->date_tz );

				// Will hold venue info
				$event_venue = array();

				// Process all the meta
				foreach( $event->post_meta as $meta ) {

					// Get meta value
					$meta_value = $meta->value;

					// Get start date
					if ( preg_match( '/^Start\sDate/i', $meta->key ) ) {

						// We must have a value
						if ( $meta_value ) {

							// Create DateTime
							$start_date = new DateTime( date( 'Y-m-d', $meta_value ), $event_timezone );

							// Store for event
							$event_args[ 'EventStartDate' ] = $start_date->format( $date_format );

						}

						// If no start date...
						else {

							// Change status to draft since no start date
							$event_args[ 'post_status' ] = 'draft';

							// No start date
							$event_args[ 'EventStartDate' ] = null;

						}

					}

					// Get end date
					else if ( preg_match( '/^End\sDate/i', $meta->key ) ) {

						// We must have a value
						if ( $meta_value ) {

							// Create DateTime
							$end_date = new DateTime( date( 'Y-m-d', $meta_value ), $event_timezone );

							// Store for event
							$event_args[ 'EventEndDate' ] = $end_date->format( $date_format );

						}

					}

					// If we have a value
					else if ( $meta_value ) {

						switch ( $meta->key ) {

							case 'URL':
								$event_args[ '_EventURL' ] = $meta_value;
								break;

							case 'Venue Name':
								$event_venue[ 'Venue' ] = $meta_value;
								break;

						}

					}

				}

				// If we have event info
				if ( ! empty( $event_venue ) ) {
					$event_args[ 'Venue' ] = $event_venue;
				}

				// If we have event info...
				if ( ! empty( $event_args ) ) {

					// Make sure we have an end date
					if ( ! $event_args[ 'EventEndDate' ] ) {
						$event_args[ 'EventEndDate' ] = $event_args[ 'EventStartDate' ];
					}

					// Update the event
					if ( in_array( $event->ID, $wordcamp_ids ) ) {

						// Update the event
						if ( $event_post_id > 0 ) {
							tribe_update_event( $event_post_id, $event_args );
						}

					}

					// Create the event
					else if ( $event_post_id = tribe_create_event( $event_args ) ) {

						// Store the WordCamp ID
						if ( $event_post_id > 0 ) {
							add_post_meta( $event_post_id, '_wordcamp_id', $event->ID, true );
						}

					}

					// Make sure the category is set
					if ( $event_post_id > 0 ) {
						wp_set_object_terms( $event_post_id, 'wordcamps', 'tribe_events_cat', true );
					}

				}

			}

		}

	}

}

/**
 * Returns the instance of our main WordCamp_Importer_TEC class.
 *
 * Will come in handy when we need to access the
 * class to retrieve data throughout the plugin.
 *
 * @since	1.0.0
 * @access	public
 * @return	WordCamp_Importer_TEC
 */
function wordcamp_importer_tec() {
	return WordCamp_Importer_TEC::instance();
}

// Let's get this show on the road
wordcamp_importer_tec();