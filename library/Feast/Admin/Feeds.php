<?php

class Feast_Admin_Feeds extends Feast_Autohooker {
	public static function bootstrap() {
		self::register_hooks();
		add_filter('manage_' . Feast_Feed::TYPE . '_posts_columns', array(__CLASS__, 'post_columns'));
		add_filter('manage_' . Feast_Feed::TYPE . '_posts_custom_column', array(__CLASS__, 'post_column_data'), 10, 2);
	}

	/**
	 * Register our metaboxes
	 *
	 * @wp-action admin_init
	 */
	public static function register_metaboxes() {
		add_meta_box('feast-feed-description', 'Description', array(__CLASS__, 'description'), 'feast-feed', 'normal', 'high');
		add_meta_box('feast-feed-data', 'Feast Feed Data', array(__CLASS__, 'feed_data'), 'feast-feed', 'normal', 'high');
	}

	public static function post_columns($columns) {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'title' => __('Title'),
			'feast-feed-url' => __('Feed URL', 'feast'),
			'feast-last-updated' => __('Last Updated', 'feast'),
			'date' => __('Date')
		);
		return $columns;
	}

	public static function post_column_data($column, $ID) {
		switch ($column) {
			case 'feast-feed-url':
				echo '<code>' . get_post_meta( $ID, '_feast_feed_url', true) . '</code>';
				break;
			case 'feast-last-updated':
				$update_time = get_post_meta($ID, '_feast_last_updated', true);

				if ( ! $update_time ) {
					_e('Never', 'feast');
					break;
				}
				if ( ( abs( time() - $update_time ) ) < DAY_IN_SECONDS )
					$human_time = sprintf( __( '%s ago' ), human_time_diff( $update_time ) );
				else
					$human_time = mysql2date( __( 'Y/m/d \a\t g:i A' ), $update_time );
				echo '<time datetime="' . date( 'c', $update_time ) . '">' . $human_time . '</time>';
				break;
		}
	}

	public static function description($post) {
?>
		<textarea name="post_content" class="large-text" cols="60" rows="10"><?php echo esc_html($post->post_content) ?></textarea>
<?php
	}

	public static function feed_data($post) {
		#wp_nonce_field
?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="feast_feed_url"><?php _e('Feed URL', 'feast') ?></label></th>
				<td><input type="url"
					id="feast_feed_url" name="feast_feed_url"
					class="regular-text code"
					value="<?php echo esc_attr($post->_feast_feed_url) ?>" /></td>
			</tr>
		</table>
<?php
	}

	/**
	 * Save our post data
	 *
	 * @wp-action save_post
	 *
	 * @param int $id Post ID
	 * @param WP_Post $post Post object
	 */
	public static function save_post($id, $post) {
		global $current_user;

		if ($post->post_type !== Feast_Feed::TYPE)
			return;

		$feed = new Feast_Feed($post);

		// Are we updating?
		if ( get_post_meta($feed->ID, '_feast_feed_url', true) )
			return;

		try {
			if (empty($_POST['feast_feed_url']))
				throw new Feast_Exception(__('No feed URL was supplied', 'feast'), 'feast.feed.no_url');

			$data = array(
				'name' => $feed->post_title,
				'content' => $feed->post_content,
				'excerpt' => $feed->post_excerpt,
				'feast_feed_url' => $_POST['feast_feed_url'],
				'feast_sp' => fetch_feed($_POST['feast_feed_url']),
			);

			do_action('feast_create_feed', $feed, $data);
		}
		catch (Feast_Exception $e) {
			set_transient( 'feast_feed_error-' . $id . '-' . $current_user->ID, $e->getMessage(), 60 );
		}
	}

	/**
	 * Update feed post data
	 *
	 * @wp-action post_updated
	 *
	 * @param int $id Post ID
	 * @param WP_Post $new Updated post
	 * @param WP_Post $old Old post data
	 */
	public static function update_post($id, $new, $old) {
		global $current_user;

		if ($new->post_type !== Feast_Feed::TYPE)
			return;

		try {
			if (empty($_POST['feast_feed_url']))
				throw new Feast_Exception(__('No feed URL was supplied', 'feast'), 'feast.feed.no_url');

			$feed = new Feast_Feed($new);
			$data = array(
				'name' => $new->post_title,
				'content' => $new->post_content,
				'excerpt' => $new->post_excerpt,
				'feast_feed_url' => $_POST['feast_feed_url'],
				'feast_sp' => fetch_feed($_POST['feast_feed_url']),
			);
			$old = array(
				'name' => $old->post_title,
				'content' => $old->post_content,
				'excerpt' => $old->post_excerpt,
				'feast_feed_url' => $old->_feast_feed_url,
			);

			do_action('feast_update_feed', $feed, $data, $old);
		}
		catch (Feast_Exception $e) {
			set_transient( 'feast_feed_error-' . $id . '-' . $current_user->ID, $e->getMessage(), 60 );
		}
	}

	/**
	 * Show any errors for the current post
	 *
	 * @wp-action all_admin_notices
	 */
	public static function show_errors() {
		global $post, $current_user;
		if (empty($post) || empty($post->ID) || $post->post_type !== 'feast-feed')
			return;

		if (!empty($_REQUEST['feast-updated']) && !empty($_REQUEST['ids'])) {
			$updated = (int) $_REQUEST['feast-updated'];
			$message = sprintf( _n( '%s feed updated from source.', '%s feeds updated from source.', $updated, 'feast' ), number_format_i18n( $updated ) );
			echo '<div id="message" class="updated"><p>' . $message . '</p></div>';
			return;
		}

		$error = get_transient( 'feast_feed_error-' . $post->ID . '-' . $current_user->ID );
		if (!$error)
			return;

		echo '<div id="message" class="updated"><p>' . sprintf(__('Unable to save post: %s', 'feast'), $error) . '</p></div>';
		delete_transient('feast_feed_error-' . $post->ID . '-' . $current_user->ID);
	}

	/**
	 * Add extra row actions
	 *
	 * @wp-filter post_row_actions
	 *
	 * @param array $actions Actions to output in the row
	 * @param WP_Post $post Post context
	 * @return array Updated actions
	 */
	public static function row_actions($actions, $post) {
		if ($post->post_type !== Feast_Feed::TYPE)
			return $actions;

		$post_type_object = get_post_type_object( $post->post_type );

		$update_url = self::get_action_url($post, 'update');
		$actions['wtf'] = '<a href="' . $update_url . '">' . _x('Update', 'feed row action', 'feast') . '</a>';
		return $actions;
	}

	protected static function get_action_url($post, $action) {
		$post_type_object = get_post_type_object( $post->post_type );

		$action = 'feast-' . $action;
		$url = add_query_arg( 'action', $action, admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) ) );
		return wp_nonce_url( $url, $action . '-feed_' . $post->ID );
	}

	/**
	 * Handle any bulk actions from the edit screen
	 *
	 * This only exists because wp-admin/post.php doesn't have a filter.
	 * Ridiculous.
	 *
	 * @wp-action admin_action_feast-update
	 */
	public static function handle_actions() {
		if ( isset( $_GET['post'] ) )
		 	$post_id = $post_ID = (int) $_GET['post'];
		elseif ( isset( $_POST['post_ID'] ) )
		 	$post_id = $post_ID = (int) $_POST['post_ID'];
		else
		 	$post_id = $post_ID = 0;

		$post = $post_type = $post_type_object = null;

		if ( $post_id )
			$post = get_post( $post_id );

		if ( $post ) {
			$post_type = $post->post_type;
			$post_type_object = get_post_type_object( $post_type );
		}

		if ($post->post_type !== Feast_Feed::TYPE)
			return;

		$sendback = wp_get_referer();
		if ( ! $sendback ||
		     strpos( $sendback, 'post.php' ) !== false ||
		     strpos( $sendback, 'post-new.php' ) !== false ) {
			$sendback = admin_url( 'edit.php' );
			$sendback .= ( ! empty( $post_type ) ) ? '?post_type=' . $post_type : '';
		} else {
			$sendback = remove_query_arg( array('trashed', 'untrashed', 'deleted', 'ids', 'feast-updated'), $sendback );
		}

		$action = str_replace('admin_action_feast-', '', current_filter());
		$nonce_action = 'feast-' . $action;

		switch ($action) {
			case 'update':
				check_admin_referer($nonce_action . '-feed_' . $post_id);
				$feed = new Feast_Feed($post_id);

				if ( ! $feed->update() )
					wp_die( __('Error in updating feed.', 'feast') );

				wp_redirect( add_query_arg( array('feast-updated' => 1, 'ids' => $post_id), $sendback ) );

				die();

			default:
				do_action('feast_admin_action-' . $action);
				wp_redirect($sendback);

				die();
		}
	}
}