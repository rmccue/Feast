<?php
/**
 * OPML-to-Lilina importer
 *
 * @author Ryan McCue <cubegames@gmail.com>
 * @package Lilina
 * @version 1.0
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * OPML-to-Lilina importer
 *
 * @package Lilina
*/
class Feast_Import_OPML extends WP_Importer {
	public static function bootstrap() {
		$importer = new Feast_Import_OPML();
		register_importer( 'feast-opml', 'Feast - OPML', __('Import <strong>feeds</strong> from an OPML export file.', 'feast'), array( $importer, 'dispatch' ) );
	}

	/**
	 * Registered callback function for the WordPress Importer
	 *
	 * Manages the three separate stages of the WXR import process
	 */
	public function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch($step) {
			case 0:
				$this->introduction();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				if ( $this->handle_upload() ) {
					$file = get_attached_file( $this->id );
					set_time_limit(0);
					$this->import( $file );
				}
				break;
		}

		$this->footer();
	}

	protected function introduction() {
		echo '<div class="narrow">';
		echo '<p>'.__( 'Howdy! Upload your OPML file and we&#8217;ll import your feeds into this site.', 'feast' ).'</p>';
		echo '<p>'.__( 'Choose an OPML (.xml) file to upload, then click Upload file and import.', 'feast' ).'</p>';
		wp_import_upload_form( 'admin.php?import=feast-opml&amp;step=1' );
		echo '</div>';
	}

	/**
	 * Handles the WXR upload and initial parsing of the file to prepare for
	 * displaying author import options
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	protected function handle_upload() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'feast' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'feast' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'feast' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$this->id = (int) $file['id'];
		return true;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	protected function import( $file ) {
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start( $file );

		wp_suspend_cache_invalidation( true );
		$this->process_feeds();
		wp_suspend_cache_invalidation( false );

		$this->import_end();
	}

	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	protected function import_start( $file ) {
		if ( ! is_file( $file ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'feast' ) . '</strong><br />';
			echo __( 'The file does not exist, please try again.', 'feast' ) . '</p>';
			$this->footer();
			die();
		}

		$import_data = $this->parse( file_get_contents( $file ) );

		if ( is_wp_error( $import_data ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'feast' ) . '</strong><br />';
			echo esc_html( $import_data->get_error_message() ) . '</p>';
			$this->footer();
			die();
		}

		$this->feeds = $import_data;

		do_action( 'feast_import_start' );
	}

	/**
	 * Parse an OPML file
	 *
	 * @param string $file Path to OPML file for parsing
	 * @return array Information gathered from the OPML file
	 */
	protected function parse( $data ) {
		$parsed = new Feast_OPMLParser( $data );

		if ( ! empty( $parsed->error ) || empty( $parsed->data ) ) {
			return new WP_Error( 'feast_import_opml_failedparse', sprintf( __( 'The OPML file could not be read. The parser said: %s', 'feast' ), $parsed->error ) );
		}

		return $this->massage_data( $parsed->data );
	}

	/**
	 * Transform the result of the parsed OPML into corrected data
	 *
	 * @return array
	 */
	protected function massage_data( $feeds, $category = array() ) {
		$parsed = array();

		foreach ( $feeds as $name => $feed ) {
			if ( ! isset( $feed['xmlurl'] ) ) {
				if ( ! empty( $name ) )
					array_push( $category, $name );

				$feed = $this->massage_data($feed, $new_cat);
				$parsed = array_merge($parsed, $feed);
			}
			else {
				$parsed[] = array(
					'url' => $feed['xmlurl'],
					'title' => $feed['title'],
					'category' => $category
				);
			}
		}

		return $parsed;
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	protected function process_feeds() {
		foreach ( $this->feeds as $feed ) {
			$data = array(
				'name' => $feed['title'],
				'content' => '',
				'excerpt' => '',
				'feast_feed_url' => $feed['url'],
				'feast_sp' => fetch_feed($feed['url']),
			);
			$feed_obj = Feast_Feed::create($data);
		}

		unset( $this->feeds );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	protected function import_end() {
		wp_import_cleanup( $this->id );

		wp_cache_flush();

		echo '<p>' . __( 'All done.', 'feast' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'feast' ) . '</a>' . '</p>';

		do_action( 'feast_import_end' );
	}

	/**
	 * Display import page title
	 */
	protected function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Import OPML', 'feast' ) . '</h2>';
	}

	/**
	 * Close div.wrap
	 */
	protected function footer() {
		echo '</div>';
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	public function bump_request_timeout($val) {
		return 60;
	}
}
