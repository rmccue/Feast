<?php

class Feast_Admin_Items extends Feast_Autohooker {
	public static function bootstrap() {
		self::register_hooks();
		add_filter('manage_' . Feast_Item::TYPE . '_posts_columns', array(__CLASS__, 'post_columns'));
		add_filter('manage_' . Feast_Item::TYPE . '_posts_custom_column', array(__CLASS__, 'post_column_data'), 10, 2);
	}

	public static function post_columns($columns) {
		unset($columns['date']);
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'title' => __('Title'),
			'feast-feed-name' => __('Feed', 'feast'),
			'comments' => '<span class="vers"><div title="' . esc_attr__( 'Comments' ) . '" class="comment-grey-bubble"></div></span>',
			'date' => __('Date')
		);
		return $columns;
	}

	public static function post_column_data($column, $ID) {
		switch ($column) {
			case 'feast-feed-name':
				$parent = wp_get_post_parent_id( $ID );
				echo '<a href="' . get_permalink( $parent) . '">' . get_the_title( $parent ) . '</a>';
				echo '<div class="row-actions">';
				echo '<span class="edit"><a href="' . get_edit_post_link($parent) . '">' . __('Edit', 'feast') . '</a></span>';
				echo '</div>';
				break;
		}
	}
}