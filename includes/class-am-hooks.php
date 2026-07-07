<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AM_Hooks {

	public static function init() {
		$instance = new self();

		add_action( 'wp_login',              array( $instance, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed',       array( $instance, 'on_login_failed' ) );
		add_action( 'wp_logout',             array( $instance, 'on_logout' ) );
		add_filter( 'authenticate',          array( $instance, 'on_authenticate' ), 30, 3 );
		add_action( 'user_register',         array( $instance, 'on_user_register' ) );
		add_action( 'profile_update',        array( $instance, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user',           array( $instance, 'on_user_delete' ) );
		add_action( 'set_user_role',         array( $instance, 'on_role_change' ), 10, 3 );
		add_action( 'add_user_to_blog',      array( $instance, 'on_add_user_to_blog' ), 10, 3 );
		add_action( 'post_updated',          array( $instance, 'on_post_updated' ), 10, 3 );
		add_action( 'transition_post_status',array( $instance, 'on_post_status_change' ), 10, 3 );
		add_action( 'before_delete_post',    array( $instance, 'on_post_delete' ) );
		add_action( 'wp_trash_post',         array( $instance, 'on_post_trash' ) );
		add_action( 'untrash_post',          array( $instance, 'on_post_untrash' ) );
		add_action( 'attachment_updated',    array( $instance, 'on_attachment_updated' ), 10, 3 );
		add_action( 'add_attachment',        array( $instance, 'on_attachment_add' ) );
		add_action( 'delete_attachment',     array( $instance, 'on_attachment_delete' ) );
		add_action( 'wp_insert_comment',     array( $instance, 'on_comment_insert' ), 10, 2 );
		add_action( 'edit_comment',          array( $instance, 'on_comment_edit' ) );
		add_action( 'delete_comment',        array( $instance, 'on_comment_delete' ) );
		add_action( 'transition_comment_status', array( $instance, 'on_comment_status' ), 10, 3 );
		add_action( 'activated_plugin',      array( $instance, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin',    array( $instance, 'on_plugin_deactivated' ) );
		add_action( 'upgrader_process_complete', array( $instance, 'on_upgrader_complete' ), 10, 2 );
		add_action( 'delete_plugin',         array( $instance, 'on_plugin_delete' ) );
		add_action( 'switch_theme',          array( $instance, 'on_theme_switch' ), 10, 3 );
		add_action( 'customize_save_after',  array( $instance, 'on_customizer_save' ) );
		add_action( 'created_term',          array( $instance, 'on_term_created' ), 10, 3 );
		add_action( 'edited_term',           array( $instance, 'on_term_edited' ), 10, 3 );
		add_action( 'delete_term',           array( $instance, 'on_term_deleted' ), 10, 4 );
		add_action( 'wp_update_nav_menu',    array( $instance, 'on_menu_update' ) );
		add_action( 'wp_delete_nav_menu',    array( $instance, 'on_menu_delete' ) );
		add_action( 'sidebar_admin_setup',   array( $instance, 'on_widget_save' ) );
		add_action( 'password_reset',        array( $instance, 'on_password_reset' ), 10, 2 );
		add_action( 'retrieve_password',     array( $instance, 'on_password_retrieve' ) );
		add_action( 'wp_set_password',       array( $instance, 'on_password_set' ), 10, 2 );
		if ( is_multisite() ) {
			add_action( 'wpmu_new_blog',     array( $instance, 'on_site_created' ), 10, 6 );
			add_action( 'delete_blog',       array( $instance, 'on_site_deleted' ) );
		}
		add_action( 'admin_init',            array( $instance, 'on_admin_access' ) );
	}

	/**
	 * True when the current request is WordPress acting on its own (WP-Cron)
	 * rather than a person clicking something in wp-admin or on the front end.
	 * Covers: scheduled post publishing, scheduled trash auto-deletion,
	 * cron-based thumbnail regeneration, and background (non-interactive)
	 * plugin/theme/core auto-updates — all of which run through wp-cron.php,
	 * not a browser request from a person.
	 */
	private function is_automated_context(): bool {
		return wp_doing_cron();
	}

	public function on_login( string $user_login, WP_User $user ) {
		AM_Logger::log( 'auth.login', sprintf( 'User "%s" logged in.', $user_login ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $user_login, 'user_id' => $user->ID, 'user_name' => $user_login, 'user_role' => implode( ', ', $user->roles ) ) );
	}
	public function on_login_failed( string $username ) {
		AM_Logger::log( 'auth.login_failed', sprintf( 'Failed login attempt for username "%s".', $username ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_name' => $username ) );
	}
	public function on_authenticate( $user, string $username, string $password ) {
		if ( empty( $_POST['log'] ) ) return $user;
		if ( is_wp_error( $user ) ) {
			AM_Logger::log( 'auth.error', sprintf( 'Authentication error for "%s": %s', $username, $user->get_error_message() ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_name' => $username ) );
		}
		return $user;
	}
	public function on_logout() {
		$user = wp_get_current_user();
		AM_Logger::log( 'auth.logout', sprintf( 'User "%s" logged out.', $user->user_login ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $user->user_login ) );
	}
	public function on_user_register( int $user_id ) {
		$user = get_userdata( $user_id );
		AM_Logger::log( 'user.register', sprintf( 'New user registered: "%s".', $user->user_login ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user->user_login ) );
	}
	public function on_profile_update( int $user_id, WP_User $old_data ) {
		$new_data = get_userdata( $user_id ); $changes = array();
		if ( $old_data->user_email !== $new_data->user_email ) $changes[] = sprintf( 'email: %s → %s', $old_data->user_email, $new_data->user_email );
		if ( $old_data->display_name !== $new_data->display_name ) $changes[] = 'display name changed';
		$detail = $changes ? implode( '; ', $changes ) : 'profile data updated';
		AM_Logger::log( 'user.update', sprintf( 'User "%s" profile updated — %s.', $new_data->user_login, $detail ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $new_data->user_login, 'meta' => array( 'changes' => $changes ) ) );
	}
	public function on_user_delete( int $user_id ) {
		$user = get_userdata( $user_id );
		AM_Logger::log( 'user.delete', sprintf( 'User "%s" (ID %d) deleted.', $user ? $user->user_login : 'unknown', $user_id ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user ? $user->user_login : "user-{$user_id}" ) );
	}
	public function on_role_change( int $user_id, string $role, array $old_roles ) {
		$user = get_userdata( $user_id );
		AM_Logger::log( 'user.role_change', sprintf( 'User "%s" role changed from "%s" to "%s".', $user->user_login, implode( ', ', $old_roles ), $role ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user->user_login, 'meta' => array( 'old_roles' => $old_roles, 'new_role' => $role ) ) );
	}
	public function on_add_user_to_blog( int $user_id, string $role, int $blog_id ) {
		$user = get_userdata( $user_id );
		AM_Logger::log( 'user.added_to_site', sprintf( 'User "%s" added to site ID %d with role "%s".', $user->user_login, $blog_id, $role ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user->user_login ) );
	}
	private function skip_post( WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) || $post->post_type === 'revision';
	}
	public function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) {
		if ( $this->skip_post( $post_after ) ) return;
		$changes = array();
		if ( $post_before->post_title   !== $post_after->post_title   ) $changes[] = 'title';
		if ( $post_before->post_content !== $post_after->post_content ) $changes[] = 'content';
		if ( $post_before->post_status  !== $post_after->post_status  ) $changes[] = "status ({$post_before->post_status} → {$post_after->post_status})";
		if ( $post_before->post_name    !== $post_after->post_name    ) $changes[] = 'slug';
		if ( empty( $changes ) ) return;
		AM_Logger::log( 'post.update', sprintf( '"%s" (%s) updated — %s.', $post_after->post_title, $post_after->post_type, implode( ', ', $changes ) ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => $post_after->post_type, 'object_id' => $post_id, 'object_name' => $post_after->post_title, 'meta' => array( 'fields_changed' => $changes ) ) );
	}
	public function on_post_status_change( string $new, string $old, WP_Post $post ) {
		if ( $this->skip_post( $post ) || $new === $old ) return;
		if ( $new === 'publish' && $old !== 'publish' ) {
			if ( $this->is_automated_context() ) return; // WP-Cron publishing a scheduled post, not a person clicking Publish.
			AM_Logger::log( 'post.publish', sprintf( '"%s" (%s) published.', $post->post_title, $post->post_type ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => $post->post_type, 'object_id' => $post->ID, 'object_name' => $post->post_title ) );
		}
	}
	public function on_post_delete( int $post_id ) { if ( $this->is_automated_context() ) return; $post = get_post( $post_id ); if ( ! $post || $this->skip_post( $post ) ) return; AM_Logger::log( 'post.delete', sprintf( '"%s" (%s, ID %d) permanently deleted.', $post->post_title, $post->post_type, $post_id ), array( 'severity' => AM_Logger::WARNING, 'object_type' => $post->post_type, 'object_id' => $post_id, 'object_name' => $post->post_title ) ); }
	public function on_post_trash( int $post_id ) { if ( $this->is_automated_context() ) return; $post = get_post( $post_id ); if ( ! $post || $this->skip_post( $post ) ) return; AM_Logger::log( 'post.trash', sprintf( '"%s" (%s) moved to Trash.', $post->post_title, $post->post_type ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => $post->post_type, 'object_id' => $post_id, 'object_name' => $post->post_title ) ); }
	private function parse_user_agent( $ua ): string { return sanitize_text_field( $ua ); }
	public function on_post_untrash( int $post_id ) { $post = get_post( $post_id ); if ( ! $post ) return; AM_Logger::log( 'post.untrash', sprintf( '"%s" (%s) restored from Trash.', $post->post_title, $post->post_type ), array( 'severity' => AM_Logger::INFO, 'object_type' => $post->post_type, 'object_id' => $post_id, 'object_name' => $post->post_title ) ); }
	public function on_attachment_add( int $post_id ) { $post = get_post( $post_id ); AM_Logger::log( 'media.upload', sprintf( 'File "%s" uploaded.', $post->post_title ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'media', 'object_id' => $post_id, 'object_name' => $post->post_title ) ); }
	public function on_attachment_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) { if ( $this->is_automated_context() ) return; AM_Logger::log( 'media.update', sprintf( 'Media "%s" updated.', $post_after->post_title ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'media', 'object_id' => $post_id, 'object_name' => $post_after->post_title ) ); }
	public function on_attachment_delete( int $post_id ) { $post = get_post( $post_id ); AM_Logger::log( 'media.delete', sprintf( 'Media "%s" (ID %d) permanently deleted.', $post ? $post->post_title : 'unknown', $post_id ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'media', 'object_id' => $post_id, 'object_name' => $post ? $post->post_title : "attachment-{$post_id}" ) ); }
	public function on_comment_insert( int $id, WP_Comment $comment ) { AM_Logger::log( 'comment.create', sprintf( 'New comment on post ID %d by "%s".', $comment->comment_post_ID, $comment->comment_author ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'comment', 'object_id' => $id, 'object_name' => 'Comment on post ' . $comment->comment_post_ID ) ); }
	public function on_comment_edit( int $id ) { $c = get_comment( $id ); AM_Logger::log( 'comment.update', sprintf( 'Comment (ID %d) on post ID %d edited.', $id, $c->comment_post_ID ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'comment', 'object_id' => $id, 'object_name' => 'Comment on post ' . $c->comment_post_ID ) ); }
	public function on_comment_delete( int $id ) { AM_Logger::log( 'comment.delete', sprintf( 'Comment (ID %d) permanently deleted.', $id ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'comment', 'object_id' => $id, 'object_name' => "comment-{$id}" ) ); }
	public function on_comment_status( string $new, string $old, WP_Comment $comment ) { if ( $new === $old ) return; AM_Logger::log( 'comment.status_change', sprintf( 'Comment (ID %d) status changed from "%s" to "%s".', $comment->comment_ID, $old, $new ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'comment', 'object_id' => (int) $comment->comment_ID, 'object_name' => "comment-{$comment->comment_ID}" ) ); }
	public function on_plugin_activated( string $plugin ) { AM_Logger::log( 'plugin.activate', sprintf( 'Plugin "%s" activated.', $plugin ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'plugin', 'object_name' => $plugin ) ); }
	public function on_plugin_deactivated( string $plugin ) { AM_Logger::log( 'plugin.deactivate', sprintf( 'Plugin "%s" deactivated.', $plugin ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'plugin', 'object_name' => $plugin ) ); }
	public function on_plugin_delete( string $plugin ) { AM_Logger::log( 'plugin.delete', sprintf( 'Plugin "%s" deleted.', $plugin ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'plugin', 'object_name' => $plugin ) ); }
	public function on_upgrader_complete( $upgrader, array $data ) { if ( $this->is_automated_context() ) return; if ( empty( $data['type'] ) ) return; if ( $data['type'] === 'plugin' && ! empty( $data['plugins'] ) ) { foreach ( (array) $data['plugins'] as $p ) { AM_Logger::log( 'plugin.update', sprintf( 'Plugin "%s" updated.', $p ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'plugin', 'object_name' => $p ) ); } } elseif ( $data['type'] === 'theme' && ! empty( $data['themes'] ) ) { foreach ( (array) $data['themes'] as $t ) { AM_Logger::log( 'theme.update', sprintf( 'Theme "%s" updated.', $t ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'theme', 'object_name' => $t ) ); } } elseif ( $data['type'] === 'core' ) { global $wp_version; AM_Logger::log( 'core.update', sprintf( 'WordPress core updated to %s.', $wp_version ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'core', 'object_name' => 'WordPress' ) ); } }
	public function on_theme_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ) { AM_Logger::log( 'theme.switch', sprintf( 'Theme switched from "%s" to "%s".', $old_theme->get( 'Name' ), $new_name ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'theme', 'object_name' => $new_name ) ); }
	public function on_customizer_save( $manager ) { AM_Logger::log( 'theme.customize', 'Customizer settings saved.', array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'theme', 'object_name' => get_stylesheet() ) ); }
	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ) { $term = get_term( $term_id, $taxonomy ); AM_Logger::log( 'term.create', sprintf( 'Term "%s" created in "%s".', $term->name, $taxonomy ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'term', 'object_id' => $term_id, 'object_name' => $term->name ) ); }
	public function on_term_edited( int $term_id, int $tt_id, string $taxonomy ) { $term = get_term( $term_id, $taxonomy ); AM_Logger::log( 'term.update', sprintf( 'Term "%s" in "%s" updated.', $term->name, $taxonomy ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'term', 'object_id' => $term_id, 'object_name' => $term->name ) ); }
	public function on_term_deleted( int $term_id, int $tt_id, string $taxonomy, $deleted_term ) { $name = is_object( $deleted_term ) ? $deleted_term->name : "term-{$term_id}"; AM_Logger::log( 'term.delete', sprintf( 'Term "%s" deleted from "%s".', $name, $taxonomy ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'term', 'object_id' => $term_id, 'object_name' => $name ) ); }
	public function on_menu_update( int $menu_id ) { $menu = wp_get_nav_menu_object( $menu_id ); AM_Logger::log( 'menu.update', sprintf( 'Navigation menu "%s" updated.', $menu ? $menu->name : $menu_id ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'menu', 'object_id' => $menu_id, 'object_name' => $menu ? $menu->name : "menu-{$menu_id}" ) ); }
	public function on_menu_delete( int $menu_id ) { AM_Logger::log( 'menu.delete', sprintf( 'Navigation menu (ID %d) deleted.', $menu_id ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'menu', 'object_id' => $menu_id, 'object_name' => "menu-{$menu_id}" ) ); }
	public function on_widget_save() { if ( isset( $_POST['savewidget'] ) || isset( $_POST['removefromwidget'] ) ) { $action = isset( $_POST['removefromwidget'] ) ? 'removed from' : 'saved to'; $sidebar = isset( $_POST['sidebar'] ) ? sanitize_text_field( wp_unslash( $_POST['sidebar'] ) ) : 'unknown'; AM_Logger::log( 'widget.save', sprintf( 'Widget %s sidebar "%s".', $action, $sidebar ), array( 'severity' => AM_Logger::INFO, 'object_type' => 'widget', 'object_name' => $sidebar ) ); } }
	public function on_password_reset( WP_User $user, string $new_password ) { AM_Logger::log( 'user.password_reset', sprintf( 'Password reset for user "%s".', $user->user_login ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $user->user_login ) ); }
	public function on_password_retrieve( string $user_login ) { AM_Logger::log( 'user.password_retrieve', sprintf( 'Password reset email requested for "%s".', $user_login ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_name' => $user_login ) ); }
	public function on_password_set( string $password, int $user_id ) { $user = get_userdata( $user_id ); AM_Logger::log( 'user.password_set', sprintf( 'Password manually set for user "%s".', $user ? $user->user_login : $user_id ), array( 'severity' => AM_Logger::WARNING, 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $user ? $user->user_login : "user-{$user_id}" ) ); }
	public function on_site_created( int $blog_id, int $user_id, string $domain ) { AM_Logger::log( 'multisite.site_created', sprintf( 'New site created: %s (ID %d).', $domain, $blog_id ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'site', 'object_id' => $blog_id, 'object_name' => $domain ) ); }
	public function on_site_deleted( int $blog_id ) { AM_Logger::log( 'multisite.site_deleted', sprintf( 'Site ID %d deleted.', $blog_id ), array( 'severity' => AM_Logger::CRITICAL, 'object_type' => 'site', 'object_id' => $blog_id, 'object_name' => "site-{$blog_id}" ) ); }
	public function on_admin_access() { if ( is_admin() && ! current_user_can( 'manage_options' ) && ! wp_doing_ajax() ) { $pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : ''; $restricted = array( 'options-general.php', 'options.php', 'users.php', 'user-new.php', 'plugins.php' ); if ( in_array( $pagenow, $restricted, true ) ) { AM_Logger::log( 'security.access_denied', sprintf( 'Unauthorized access attempt to "%s".', $pagenow ), array( 'severity' => AM_Logger::CRITICAL, 'object_type' => 'security', 'object_name' => $pagenow ) ); } } }
}
