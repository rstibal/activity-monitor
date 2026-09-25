<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AM_Logger_Widgets — widgets added to, removed from, or moved between
 * sidebars, and widget settings saved.
 *
 * Watches the two options every widget editor writes, not any one editor's
 * requests. Through 2.9.20 this inspected the classic Widgets screen's own
 * POST (savewidget/removefromwidget on sidebar_admin_setup), which never
 * fires under the block-based widget editor -- the default since
 * WordPress 5.8, saving through the REST API instead -- so on most sites
 * no widget change was logged at all. Both editors and the Customizer
 * end up in the same two places:
 *
 *   sidebars_widgets      which widget IDs sit in which sidebar
 *   widget_{id_base}      each widget type's settings, keyed by instance
 *                         number (text-2 is instance 2 of widget_text)
 *
 * One row per sidebars_widgets write, listing every change in it, rather
 * than one per widget: a theme switch or a drag-and-drop rearrangement
 * rewrites many placements at once, and a row per widget would bury the
 * log.
 */
class AM_Logger_Widgets extends AM_Logger_Base {

	public function register_hooks() {
		add_action( 'update_option_sidebars_widgets', array( $this, 'on_sidebars_updated' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_sidebars_updated( $old_value, $value ) {
		$old = self::placements( $old_value );
		$new = self::placements( $value );

		$changes  = array();
		$sidebars = array();
		foreach ( array_keys( $old + $new ) as $sidebar ) {
			$before = $old[ $sidebar ] ?? array();
			$after  = $new[ $sidebar ] ?? array();

			$added   = array_diff( $after, $before );
			$removed = array_diff( $before, $after );
			if ( ! $added && ! $removed ) {
				continue;
			}

			$sidebars[] = $sidebar;
			$label      = self::sidebar_label( $sidebar );
			if ( $added ) {
				/* translators: 1: comma-separated widget names, 2: sidebar name */
				$changes[] = sprintf( __( 'added %1$s to "%2$s"', 'activity-monitor' ), self::widget_labels( $added ), $label );
			}
			if ( $removed ) {
				/* translators: 1: comma-separated widget names, 2: sidebar name */
				$changes[] = sprintf( __( 'removed %1$s from "%2$s"', 'activity-monitor' ), self::widget_labels( $removed ), $label );
			}
		}

		if ( ! $changes ) {
			return;
		}

		$this->log(
			'widget',
			'placement_changed',
			sprintf(
				/* translators: %s: semicolon-separated list of widget placement changes */
				__( 'Widgets changed: %s.', 'activity-monitor' ),
				implode( '; ', $changes )
			),
			array(
				'level'       => AM_Log_Levels::INFO,
				'object_type' => 'widget',
				'object_name' => implode( ', ', $sidebars ),
				'group'       => false,
			)
		);
	}

	/**
	 * Settings saved on an existing widget instance. Scoped to the option
	 * names of widget types actually registered on this site, since other
	 * plugins' options can start with "widget_" too.
	 *
	 * Instances that are new or deleted in this write are skipped: adding
	 * or removing a widget also writes sidebars_widgets, which
	 * on_sidebars_updated() already logs, so only a change to an instance
	 * that existed before and still exists is a settings save.
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option_updated( string $option, $old_value, $value ) {
		if ( 0 !== strpos( $option, 'widget_' ) || ! is_array( $old_value ) || ! is_array( $value ) ) {
			return;
		}
		$id_base = substr( $option, strlen( 'widget_' ) );
		if ( ! in_array( $id_base, self::registered_id_bases(), true ) ) {
			return;
		}

		foreach ( $value as $number => $instance ) {
			// '_multiwidget' and any other non-numeric key is bookkeeping.
			if ( ! is_int( $number ) || ! array_key_exists( $number, $old_value ) ) {
				continue;
			}
			if ( $old_value[ $number ] === $instance ) {
				continue;
			}

			$widget_id = $id_base . '-' . $number;
			$this->log(
				'widget',
				'saved',
				sprintf(
					/* translators: %s: widget name */
					__( 'Widget %s settings saved.', 'activity-monitor' ),
					self::widget_labels( array( $widget_id ) )
				),
				array(
					'level'       => AM_Log_Levels::INFO,
					'object_type' => 'widget',
					'object_name' => $widget_id,
					// group defaults to true — a burst of saves to the same
					// widget (the Customizer saving as you type) collapses.
				)
			);
		}
	}

	/**
	 * sidebar ID => widget IDs, from a raw sidebars_widgets value.
	 * 'array_version' is the option's own format marker, not a sidebar.
	 *
	 * @param mixed $raw
	 * @return array<string, string[]>
	 */
	private static function placements( $raw ): array {
		$out = array();
		foreach ( (array) $raw as $sidebar => $widgets ) {
			if ( 'array_version' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			$out[ (string) $sidebar ] = array_map( 'strval', $widgets );
		}
		return $out;
	}

	private static function sidebar_label( string $sidebar ): string {
		global $wp_registered_sidebars;
		if ( 'wp_inactive_widgets' === $sidebar ) {
			return __( 'Inactive Widgets', 'activity-monitor' );
		}
		return (string) ( $wp_registered_sidebars[ $sidebar ]['name'] ?? $sidebar );
	}

	/** "Text (text-2), Block (block-3)", falling back to the bare ID for an unregistered widget. */
	private static function widget_labels( array $widget_ids ): string {
		global $wp_registered_widgets;
		$labels = array();
		foreach ( $widget_ids as $widget_id ) {
			$name     = $wp_registered_widgets[ $widget_id ]['name'] ?? '';
			$labels[] = '' !== $name ? "{$name} ({$widget_id})" : $widget_id;
		}
		return implode( ', ', $labels );
	}

	/** @return string[] */
	private static function registered_id_bases(): array {
		global $wp_widget_factory;
		$bases = array();
		if ( $wp_widget_factory instanceof WP_Widget_Factory ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				$bases[] = (string) $widget->id_base;
			}
		}
		return $bases;
	}
}
