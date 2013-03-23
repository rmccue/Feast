<?php

class Feast_Feed extends Feast_Post {
	const TYPE = 'feast-feed';

	/**
	 * Cached SimplePie object
	 *
	 * This can be set if updating from a different feed (e.g. PuSH partial
	 * updates), but otherwise should be set internally
	 *
	 * @var SimplePie
	 */
	public $sp = null;

	/**
	 * Update a feed from its source
	 */
	public function update() {
		if (empty($this->sp)) {
			$feed = $this->_feast_feed_url;
			$this->sp = fetch_feed($feed);
		}

		if (is_wp_error($this->sp))
			return false;

		// Setup the timezone object
		$this->timezone = Feast::getTimezoneObject();

		add_filter('feast_pre_insert_item', array(&$this, 'localiseTime'), 10, 3);

		foreach ($this->sp->get_items() as $item) {
			Feast_Item::create($item, $this);
		}

		remove_filter('feast_pre_insert_item', array(&$this, 'localiseTime'), 10, 3);

		update_post_meta($this->ID, '_feast_last_updated', time());

		return true;
	}

	public function localiseTime($data, $item, $feed) {
		$publish = $item->get_date('U');
		if ( $publish ) {
			$publish = new DateTime( '@' . $publish );
			$publish->setTimezone( $this->timezone );
			$data['post_date'] = $publish->format('Y-m-d H:i:s');
		}

		$updated = $item->get_updated_date('U');
		if ($updated) {
			$updated = new DateTime( '@' . $updated );
			$updated->setTimezone( $this->timezone );
			$data['post_modified'] = $updated->format('Y-m-d H:i:s');
		}

		return $data;
	}

	public function get_items($args = array()) {
		$default = array(
			'post_type' => Feast_Item::TYPE,
			'post_parent' => $this->ID,
		);
		$args = array_merge($default, $args);
		$query = new WP_Query($args);

		return $query;
	}

	/**
	 * Create a new feed in the database
	 *
	 * @param array $feed Feed data to add, see description for keys
	 * @return Feast_Feed New feed object
	 */
	public static function create($feed) {
		$data = array(
			'post_type' => Feast_Feed::TYPE,
			'post_title' => $feed['name'],
			'post_content' => $feed['content'],
			'post_excerpt' => $feed['excerpt'],
			'post_status' => 'publish',
		);

		$data = apply_filters('feast_pre_insert_feed', $data, $feed);
		if (is_wp_error($data))
			throw Feast::error_to_exception($data);

		$success = wp_insert_post($data, true);
		if (is_wp_error($success)) {
			throw Feast::error_to_exception($success);
		}

		$feed_obj = new Feast_Feed($success);

		do_action('feast_create_feed', $feed_obj, $feed);

		return $feed_obj;
	}

	/**
	 * Prepare internal feed data
	 *
	 * This runs on {@wp-action feast_pre_insert_feed} to prepare the internal
	 * data for use by {@see addFeedData}
	 *
	 * @param array $data Data to insert into the database
	 * @param array $feed Supplied feed data
	 * @return array|WP_Error Filtered feed data
	 */
	public static function prepareFeedData($data, $feed) {
		if (empty($feed['feast_feed_url']))
			return new WP_Error('feast.feed.no_url', __('No feed URL was supplied', 'feast'));

		$data['feast_sp'] = fetch_feed($feed['feast_feed_url']);

		// TODO: Check again here

		if (empty($feed['name']))
			$data['name'] = $data['feast_sp']->get_title();

		return $data;
	}

	/**
	 * Add internal feed data
	 *
	 * This runs on {@wp-action feast_create_feed} and adds the internal data
	 * for the feed.
	 *
	 * @param Feast_Feed $feed Feed object
	 * @param array $data Supplied feed data
	 */
	public static function addFeedData($feed, $data) {
		add_post_meta( $feed->ID, '_feast_feed_url', $data['feast_sp']->subscribe_url() );
		add_post_meta( $feed->ID, '_feast_icon', Feast_Feed::getDefaultIcon() );

		$feed->sp = $data['feast_sp'];

		// Fetch the favicon if we can
		$favicon = self::findFavicon($feed->sp);
		if ($favicon) {
			$done = self::sideloadFavicon($favicon, $feed);
			if (is_wp_error($done)) {
				// Handle error here
			}
			else {
				update_post_meta( $feed->ID, '_feast_icon', $done );
			}
		}

		// Run initial feed update
		$feed->update();
	}

	/**
	 * Add internal feed data
	 *
	 * This runs on {@wp-action feast_update_feed} and adds the internal data
	 * for the feed.
	 *
	 * @param Feast_Feed $feed Feed object
	 * @param array $data Supplied feed data
	 * @param array $old Previous feed data
	 */
	public static function updateFeedData($feed, $data, $old) {
		$needs_update = ( $data['feast_feed_url'] === $old['feast_feed_url'] );

		if (!$needs_update)
			return;

		var_dump('updating');
		self::addFeedData($feed, $data);
	}

	/**
	 * Find a favicon image for the given feed
	 *
	 * @param SimplePie $feed SimplePie feed object
	 * @return string|boolean URL of the favicon if found, false otherwise
	 */
	protected static function findFavicon($feed) {
		if ($return = $feed->get_channel_tags(SIMPLEPIE_NAMESPACE_ATOM_10, 'icon')) {
			return SimplePie_Misc::absolutize_url($return[0]['data'], $feed->get_base($return[0]));
		}

		if (($url = $feed->get_link()) !== null && preg_match('/^http(s)?:\/\//i', $url)) {
			return SimplePie_Misc::absolutize_url('/favicon.ico', $url);
		}

		return false;
	}

	/**
	 * Sideload the favicon image
	 *
	 * Based on {@see media_sideload_image()}, but returns an ID.
	 *
	 * @param string $file The URL of the image to download
	 * @param Feast_Feed $feed Feed object the attachment is related to
	 * @return int|WP_Error Attachment ID on success
	 */
	protected static function sideloadFavicon($file, $feed) {
		// Download file to temp location
		$tmp = download_url( $file );

		// Set variables for storage
		// fix file filename for query strings
		preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png|ico)\b/i', $file, $matches );
		$file_array['name'] = 'favicon-' . $feed->ID . '.' . $matches[1];
		$file_array['tmp_name'] = $tmp;

		// If error storing temporarily, unlink
		if ( is_wp_error( $tmp ) ) {
			@unlink($file_array['tmp_name']);
			return $tmp;
		}

		// do the validation and storage stuff
		$desc = sprintf( _x( 'Favicon for %s', 'media description', 'feast' ), $feed->post_title );
		$id = media_handle_sideload( $file_array, $feed->ID, $desc );
		// If error storing permanently, unlink
		if ( is_wp_error($id) ) {
			@unlink($file_array['tmp_name']);
		}

		return $id;
	}

	/**
	 * Get the default icon ID
	 *
	 * @return int Attachment ID for the default icon
	 */
	public static function getDefaultIcon() {
		return 0;
	}
}