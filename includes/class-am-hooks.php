<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AM_Hooks {

	public static function init() {
		$instance = new self();

		// NOTE (v2.0 port): wp_login / wp_login_failed / wp_logout / user_register /
		// profile_update / delete_user / set_user_role / add_user_to_blog are now
		// owned by AM_Logger_Users (see includes/loggers/class-am-logger-users.php)
		// — registrations removed here to avoid duplicate logging.
		// on_authenticate() (auth.error on failed WP_Error auth, distinct from a
		// plain failed-login) has not been ported yet and still runs here.
		add_filter( 'authenticate',          array( $instance, 'on_authenticate' ), 30, 3 );
		// NOTE (v2.0 port): post_updated / transition_post_status / before_delete_post /
		// wp_trash_post / untrash_post are now owned by AM_Logger_Posts (see
		// includes/loggers/class-am-logger-posts.php) — registrations removed here
		// to avoid duplicate logging. Remove this whole class once all other event
		// types are ported per activity-monitor-v2-spec.md §9 item 2.
		// NOTE (v2.0 port): attachment_updated / add_attachment / delete_attachment
		// are now owned by AM_Logger_Media (see
		// includes/loggers/class-am-logger-media.php) — registrations removed
		// here to avoid duplicate logging.
		// NOTE (v2.0 port): wp_insert_comment / edit_comment / delete_comment /
		// transition_comment_status are now owned by AM_Logger_Comments (see
		// includes/loggers/class-am-logger-comments.php) — registrations
		// removed here to avoid duplicate logging.
		// NOTE (v2.0 port): activated_plugin / deactivated_plugin / delete_plugin
		// are now owned by AM_Logger_Plugins (see
		// includes/loggers/class-am-logger-plugins.php) — registrations
		// removed here to avoid duplicate logging.
		// NOTE (v2.0 port): upgrader_process_complete's three branches are now
		// fully split across AM_Logger_Plugins (plugin), AM_Logger_Themes
		// (theme), and AM_Logger_Core (core) — the legacy
		// on_upgrader_complete() below has no branches left to run, so its
		// registration is removed. The method body stays as dead code for
		// reference only.
		// (delete_plugin now owned by AM_Logger_Plugins — see note above.)
		// NOTE (v2.0 port): switch_theme / customize_save_after are now owned
		// by AM_Logger_Themes (see includes/loggers/class-am-logger-themes.php)
		// — registrations removed here to avoid duplicate logging.
		// NOTE (v2.0 port): created_term / edited_term / delete_term are now
		// owned by AM_Logger_Terms (see includes/loggers/class-am-logger-terms.php),
		// wp_update_nav_menu / wp_delete_nav_menu by AM_Logger_Menus (see
		// includes/loggers/class-am-logger-menus.php), and sidebar_admin_setup
		// by AM_Logger_Widgets (see includes/loggers/class-am-logger-widgets.php)
		// — all removed here to avoid duplicate logging.
		// NOTE (v2.0 port): password_reset / retrieve_password / wp_set_password
		// are now owned by AM_Logger_Passwords (see
		// includes/loggers/class-am-logger-passwords.php), and
		// wpmu_new_blog / delete_blog by AM_Logger_Sites (see
		// includes/loggers/class-am-logger-sites.php) — all removed here to
		// avoid duplicate logging. This is the last of the ported callbacks;
		// only on_authenticate() and on_admin_access() below still run from
		// this class.
		// (wpmu_new_blog / delete_blog now owned by AM_Logger_Sites — see note above.)
		// NOTE (v2.0 port): admin_init/on_admin_access is now owned by
		// AM_Logger_Security (see includes/loggers/class-am-logger-security.php)
		// -- caught this callback while confirming nothing else remained
		// registered here besides on_authenticate. AM_Hooks now has exactly
		// one active registration (on_authenticate) — everything else is
		// fully ported.
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
	// NOTE (v2.0 port): the 'plugin' branch of this method has been removed
	// -- plugin updates are now handled by
	// AM_Logger_Plugins::on_upgrader_complete() (see
	// includes/loggers/class-am-logger-plugins.php). This method (and its
	// upgrader_process_complete registration below) stays active for its
	// theme and core branches only, until AM_Logger_Themes is ported.
	public function on_upgrader_complete( $upgrader, array $data ) { if ( $this->is_automated_context() ) return; if ( empty( $data['type'] ) ) return; if ( $data['type'] === 'theme' && ! empty( $data['themes'] ) ) { foreach ( (array) $data['themes'] as $t ) { AM_Logger::log( 'theme.update', sprintf( 'Theme "%s" updated.', $t ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'theme', 'object_name' => $t ) ); } } elseif ( $data['type'] === 'core' ) { global $wp_version; AM_Logger::log( 'core.update', sprintf( 'WordPress core updated to %s.', $wp_version ), array( 'severity' => AM_Logger::NOTICE, 'object_type' => 'core', 'object_name' => 'WordPress' ) ); } }
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
