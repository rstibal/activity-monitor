<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Menus — navigation menu update/delete.
 *
 * Ported from v1.x AM_Hooks::on_menu_update / on_menu_delete.
 *
 * See class-am-logger-posts.php for the template this follows.
 */
class AM_Logger_Menus extends AM_Logger_Base {

	public function slug(): string {
		return 'menus';
	}

	public function label(): string {
		return __( 'Navigation menus', 'activity-monitor' );
	}

	public function register_hooks() {
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu_update' ) );
		add_action( 'wp_delete_nav_menu', array( $this, 'on_menu_delete' ) );
	}

	public function on_menu_update( int $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		$name = $menu ? $menu->name : "menu-{$menu_id}";

		$this->log(
			'menu',
			'updated',
			sprintf(
				/* translators: %s: menu name */
				__( 'Navigation menu "%s" updated.', 'activity-monitor' ),
				$name
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'menu',
				'object_id'   => $menu_id,
				'object_name' => $name,
				'group'       => false,
			)
		);
	}

	public function on_menu_delete( int $menu_id ) {
		$this->log(
			'menu',
			'deleted',
			sprintf(
				/* translators: %d: menu ID */
				__( 'Navigation menu (ID %d) deleted.', 'activity-monitor' ),
				$menu_id
			),
			array(
				'level'       => AM_Log_Levels::NOTICE,
				'object_type' => 'menu',
				'object_id'   => $menu_id,
				'object_name' => "menu-{$menu_id}",
			)
		);
	}
}
