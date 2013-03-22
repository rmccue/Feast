<?php

class Feast_Item extends Feast_Post {
	const TYPE = 'feast-item';

	/**
	 * Create a new item
	 *
	 * @param SimplePie_Item $item Item data
	 * @param Feast_Feed $feed Parent feed
	 * @param boolean $return Should we return the new item?
	 * @return Feast_Item|void The new item object, or nothing if $return is true
	 */
	public static function create($item, $feed, $return = true) {
		// Check for an existing item
		$existing = get_posts(array(
			'post_type' => Feast_Item::TYPE,
			'post_parent' => $feed->ID,
			'showposts' => 1,
			'meta_query' => array(
				// Should this use the GUID instead?
				array(
					'key' => '_feast_item_id',
					'value' => $item->get_id()
				),
			),
		));
		if (!empty($existing))
			return new Feast_Item($existing[0]);

		$data = array(
			'post_type' => Feast_Item::TYPE,
			'post_title' => $item->get_title(),
			'post_content' => $item->get_content(),
			'post_excerpt' => $item->get_description(),
			'post_date_gmt' => $item->get_date('Y-m-d H:i:s'),
			'post_modified_gmt' => $item->get_updated_date('Y-m-d H:i:s'),
			'post_author' => Feast_Author::get_proxy_ID(),
			'post_status' => 'publish',
			'post_parent' => $feed->ID,
		);

		$data = apply_filters('feast_pre_insert_item', $data, $item, $feed);

		$success = wp_insert_post($data, true);
		if (is_wp_error($success)) {
			throw Feast::error_to_exception($success);
		}

		do_action('feast_create_item', $success, $item, $feed);

		if ($return)
			return new Feast_Item($success);
	}

	/**
	 * Add internal item data to the item
	 *
	 * @param int $id Post ID
	 * @param SimplePie_Item $sp_item SimplePie feed item
	 * @param Feast_Feed $feed Feed object
	 */
	public static function addItemData($id, $sp_item, $feed) {
		add_post_meta( $id, '_feast_item_id', $sp_item->get_id() );

		$author = $sp_item->get_author();

		if ($author) {
			add_post_meta( $id, '_feast_author_name',  $author->get_name() );
			add_post_meta( $id, '_feast_author_url',   $author->get_link() );
			add_post_meta( $id, '_feast_author_email', $author->get_email() );
		}
		else {
			add_post_meta( $id, '_feast_author_name',  '' );
			add_post_meta( $id, '_feast_author_url',   '' );
			add_post_meta( $id, '_feast_author_email', '' );
		}
	}
}