<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Registration lives in Plugin::register_widget() on widgets_init — nothing in
// this file may run at include time.

// Creating the widget
//phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class EventsCalendarSearchAddonWidget extends WP_Widget {

	// this function registers widget with WordPress
	function __construct() {
		parent::__construct(
		// Base ID — IMMUTABLE. Saved widget instances are keyed on it, so
		// changing it orphans every live 1.3.6 instance.
			'EventsCalendarSearchAddonWidget',
			// Widget name will appear in UI
			__( 'Events Search (Legacy)', 'events-search-addon-for-the-events-calendar' ),
			// Widget description. Kept registered so saved instances keep
			// rendering; steered toward the shortcode for new placements.
			array( 'description' => __( 'Legacy — use the [events-calendar-search] shortcode instead. Existing widgets keep working.', 'events-search-addon-for-the-events-calendar' ) )
		);
	}


	// Creating widget front-end
	public function widget( $args, $instance ) {
		$show_events         = ( ! empty( $instance['show_events'] ) ) ? ( $instance['show_events'] ) : '5';
		$disable_past_events = ( isset( $instance['disable_past_events'] ) ) ? $instance['disable_past_events'] : 'false';
		$layout              = ( isset( $instance['layout'] ) ) ? ( $instance['layout'] ) : 'small';
		$title               = isset( $instance['title'] ) ? apply_filters( 'widget_title', sanitize_text_field( $instance['title'] ) ) : '';
		$placeholder         = ( ! empty( $instance['placeholder'] ) ) ? ( $instance['placeholder'] ) : 'Search Events';
		$style_full          = ( ! empty( $instance['content_type'] ) ) ? ( $instance['content_type'] ) : 'advance';
		// before and after widget arguments are defined by themes
		echo wp_kses_post( $args['before_widget'] );
		if ( ! empty( $title ) ) {
			echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] );
		}

		/*
		 * TEC gate: render nothing rather than a dead search box on a site where
		 * The Events Calendar is inactive — same as the shortcode. class_exists-
		 * guarded so a partial deploy degrades quietly.
		 */
		if ( class_exists( '\CoolPlugins\EventsSearch\Tec\Tec' ) && ! \CoolPlugins\EventsSearch\Tec\Tec::available() ) {
			echo wp_kses_post( $args['after_widget'] );
			return;
		}

		/*
		 * v2 is the only engine: render through the SAME v2 path as the shortcode
		 * (Shortcode::render owns assets/config/SSR and returns escaped markup), so
		 * the widget can never drift from the shortcode. A sidebar wants a compact
		 * search navigator, so force a search-only typeahead bar; the saved legacy
		 * instance keys supply its placeholder/count/time defaults, mapped to v2
		 * config through Compat\Legacy_Map inside the shortcode.
		 */
		if ( class_exists( '\CoolPlugins\EventsSearch\Shortcode\Shortcode' ) ) {
			echo \CoolPlugins\EventsSearch\Shortcode\Shortcode::render( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode::render() returns escaped SSR markup.
				'mode'                => 'bar',
				'typeahead'           => 'dropdown',
				'facets'              => 'search',
				'placeholder'         => $placeholder,
				'show-events'         => $show_events,
				'disable-past-events' => $disable_past_events,
				'content-type'        => $style_full,
				'layout'              => $layout,
			) );
		}

		echo wp_kses_post( $args['after_widget'] );
	}

	// Widget Backend
	public function form( $instance ) {
		// Load css for widget only
		wp_enqueue_style( 'ecsa-widget-style', ECSA_URL . 'assets/css/ecsa-widgets.css',array(), ECSA_VERSION );

		if ( ! isset( $instance['placeholder'] ) || empty( $instance['placeholder'] ) ) {
			$placeholder = 'Search Events';
		} else {
			$placeholder = $instance['placeholder'];
		}
		if ( ! isset( $instance['show_events'] ) || empty( $instance['show_events'] ) ) {
			$show_events = '5';
		} else {
			$show_events = $instance['show_events'];
		}

		if ( ! isset( $instance['disable_past_events'] ) ) {
			$instance['disable_past_events'] = 'false'; } else {
			$instance['disable_past_events'] = $instance['disable_past_events'];
			}

			if ( ! isset( $instance['layout'] ) ) {
				$instance['layout'] = 'small'; } else {
				$instance['layout'] = $instance['layout'];
				}
				if ( ! isset( $instance['content_type'] ) ) {
					$instance['content_type'] = 'advance'; } else {
					$instance['content_type'] = $instance['content_type'];
					}

				if ( isset( $instance['title'] ) ) {
					$title = $instance['title'];
				} else {
					$title = __( 'Events Search', 'events-search-addon-for-the-events-calendar' );
				}

				// Widget admin form
				?>

		<p>
			<label class="ecsa-label" for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title :', 'events-search-addon-for-the-events-calendar' ); ?></label> 
			<input class="ecsa-input" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>

		<p>
			<label class="ecsa-label" for="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>"><?php esc_html_e( 'Placeholder :', 'events-search-addon-for-the-events-calendar' ); ?></label>
			<input class="ecsa-input" type="text" id="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'placeholder' ) ); ?>" value="<?php echo esc_attr( $placeholder ); ?>">
		</p>
		   
		<p>
			<label class="ecsa-label" for="<?php echo esc_attr( $this->get_field_id( 'show_events' ) ); ?>"><?php esc_html_e( 'Show Events :', 'events-search-addon-for-the-events-calendar' ); ?></label>
			<input class="ecsa-input"  type="text" id="<?php echo esc_attr( $this->get_field_id( 'show_events' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_events' ) ); ?>" value="<?php echo esc_attr( $show_events ); ?>" >		
		</p> 

		<p>
			<label class="ecsa-label" for="<?php echo esc_attr( $this->get_field_id( 'disable_past_events' ) ); ?>"><?php esc_attr_e( 'Disable Past Events :', 'events-search-addon-for-the-events-calendar'); ?></label>
			<select class="ecsa-input" id="<?php echo esc_attr( $this->get_field_id( 'disable_past_events' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'disable_past_events' ) ); ?>" >
				<option <?php selected( $instance['disable_past_events'], 'false' ); ?> value="false">False</option>
				<option <?php selected( $instance['disable_past_events'], 'true' ); ?> value="true">True</option>	
			</select>
		</p>

		<p>
			<label class="ecsa-label" for="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>"><?php esc_attr_e( 'Layout :', 'events-search-addon-for-the-events-calendar' ); ?></label>
			<select class="ecsa-input" id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>" >
				<option <?php selected( $instance['layout'], 'small' ); ?> value="small">Small</option>
				<option <?php selected( $instance['layout'], 'medium' ); ?> value="medium">Medium</option>
				<option <?php selected( $instance['layout'], 'large' ); ?> value="large">Large</option>
			</select>
		</p>
				
		<?php
	}

	// Updating widget replacing old instances with new
	public function update( $new_instance, $old_instance ) {
		$instance = array();
	
		$instance['title']               = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['placeholder']         = isset( $new_instance['placeholder'] ) && '' !== $new_instance['placeholder'] ? sanitize_text_field( $new_instance['placeholder'] ) : 'Search Events';
		$instance['show_events']         = isset( $new_instance['show_events'] ) ? absint( $new_instance['show_events'] ) : 5;
		$instance['disable_past_events'] = isset( $new_instance['disable_past_events'] ) ? sanitize_key( $new_instance['disable_past_events'] ) : 'false';
		$instance['layout']              = isset( $new_instance['layout'] ) && in_array( $new_instance['layout'], array( 'small', 'medium', 'large' ), true ) ? sanitize_key( $new_instance['layout'] ) : 'small';
		$instance['content_type']        = isset( $new_instance['content_type'] ) && in_array( $new_instance['content_type'], array( 'basic', 'advance' ), true ) ? $new_instance['content_type'] : 'advance';
	
		return $instance;
	}


} // Class wpb_widget ends here

