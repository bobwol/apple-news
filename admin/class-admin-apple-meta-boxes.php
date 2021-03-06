<?php

/**
 * This class provides a meta box to publish posts to Apple News from the edit screen.
 *
 * @since 0.9.0
 */
class Admin_Apple_Meta_Boxes extends Apple_News {

	/**
	 * Current settings.
	 *
	 * @since 0.9.0
	 * @var array
	 * @access private
	 */
	private $settings;

	/**
	 * Publish action.
	 *
	 * @since 0.9.0
	 * @var array
	 * @access private
	 */
	private $publish_action = 'apple_news_publish';

	/**
	 * Constructor.
	 */
	function __construct( $settings = null ) {
	    parent::__construct();
		$this->settings = $settings;

		// Register hooks if enabled
		if ( 'yes' == $settings->get( 'show_metabox' ) ) {
			// Handle a publish action and saving fields
			add_action( 'save_post', array( $this, 'do_publish' ), 10, 2 );

			// Add the custom meta boxes to each post type
			$post_types = $settings->get( 'post_types' );
			if ( ! is_array( $post_types ) ) {
				$post_types = array( $post_types );
			}

			foreach ( $post_types as $post_type ) {
				add_action( 'add_meta_boxes_' . $post_type, array( $this, 'add_meta_boxes' ) );
			}

			// Register assets used by the meta box
			add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		}
	}

	/**
	 * Check for a publish action from the meta box.
	 *
	 * @since 0.9.0
	 * @param int $post_id
	 * @param WP_Post $post
	 * @access public
	 */
	public function do_publish( $post_id, $post ) {
		// Check if the values we want are present in $_REQUEST params.
		if ( empty( $_POST['apple_news_nonce'] )
			|| empty( $_POST['post_ID'] ) ) {
			return;
		}

		// Check the nonce
		if ( ! wp_verify_nonce( $_POST['apple_news_nonce'], $this->publish_action ) ) {
			return;
		}

		// Save meta box fields
		$post_id = absint( $_POST['post_ID'] );
		self::save_post_meta( $post_id );

		// If this is set to autosync or no action is set, we're done here
		if ( 'yes' == $this->settings->get( 'api_autosync' )
			|| 'publish' != $post->post_status
			|| empty( $_POST['apple_news_publish_action'] )
			|| $this->publish_action != $_POST['apple_news_publish_action'] ) {
			return;
		}

		// Proceed with the push
		$action = new Apple_Actions\Index\Push( $this->settings, $post_id );
		try {
			$action->perform();

			// In async mode, success or failure will be displayed later
			if ( 'yes' !== $this->settings->get( 'api_async' ) ) {
				Admin_Apple_Notice::success( __( 'Your article has been pushed successfully to Apple News!', 'apple-news' ) );
			} else {
				Admin_Apple_Notice::success( __( 'Your article will be pushed shortly to Apple News.', 'apple-news' ) );
			}
		} catch ( Apple_Actions\Action_Exception $e ) {
			Admin_Apple_Notice::error( $e->getMessage() );
		}
	}

	/**
	 * Saves the Apple News meta fields associated with a post
	 *
	 * @param int $post_id
	 * @access public
	 * @static
	 */
	public static function save_post_meta( $post_id ) {

		// Determine whether to save sections.
		if ( empty( $_POST['apple_news_sections_by_taxonomy'] ) ) {
			$sections = array();
			if ( ! empty( $_POST['apple_news_sections'] )
				&& is_array( $_POST['apple_news_sections'] )
			) {
				$sections = array_map(
					'sanitize_text_field',
					$_POST['apple_news_sections']
				);
			}
			update_post_meta( $post_id, 'apple_news_sections', $sections );
		} else {
			delete_post_meta( $post_id, 'apple_news_sections' );
		}

		if ( ! empty( $_POST['apple_news_is_preview'] ) && 1 === intval( $_POST['apple_news_is_preview'] ) ) {
			$is_preview = true;
		} else {
			$is_preview = false;
		}
		update_post_meta( $post_id, 'apple_news_is_preview', $is_preview );

		if ( ! empty( $_POST['apple_news_is_sponsored'] ) && 1 === intval( $_POST['apple_news_is_sponsored'] ) ) {
			$is_sponsored = true;
		} else {
			$is_sponsored = false;
		}
		update_post_meta( $post_id, 'apple_news_is_sponsored', $is_sponsored );

		if ( ! empty( $_POST['apple_news_maturity_rating'] ) ) {
			$maturity_rating = sanitize_text_field( $_POST['apple_news_maturity_rating'] );
			if ( ! in_array( $maturity_rating, self::$maturity_ratings ) ) {
				$maturity_rating = '';
			}
		}
		if ( ! empty( $maturity_rating ) ) {
			update_post_meta( $post_id, 'apple_news_maturity_rating', $maturity_rating );
		}

		if ( ! empty( $_POST['apple_news_pullquote'] ) ) {
			$pullquote = sanitize_text_field( $_POST['apple_news_pullquote'] );
		} else {
			$pullquote = '';
		}
		update_post_meta( $post_id, 'apple_news_pullquote', $pullquote );

		if ( ! empty( $_POST['apple_news_pullquote_position'] ) ) {
			$pullquote_position = sanitize_text_field( $_POST['apple_news_pullquote_position'] );
		} else {
			$pullquote_position = 'middle';
		}
		update_post_meta( $post_id, 'apple_news_pullquote_position', $pullquote_position );

		// Save each cover art image.
		self::_save_coverart_meta( $post_id, 'apple_news_coverart_landscape' );
		self::_save_coverart_meta( $post_id, 'apple_news_coverart_portrait' );
		self::_save_coverart_meta( $post_id, 'apple_news_coverart_square' );
	}

	/**
	 * Add the Apple News meta boxes
	 *
	 * @since 0.9.0
	 * @param WP_Post $post
	 * @access public
	 */
	public function add_meta_boxes( $post ) {
		// Add the publish meta box
		add_meta_box(
			'apple_news_publish',
			__( 'Apple News', 'apple-news' ),
			array( $this, 'publish_meta_box' ),
			$post->post_type,
			apply_filters( 'apple_news_publish_meta_box_context', 'side' ),
			apply_filters( 'apple_news_publish_meta_box_priority', 'high' )
		);
	}

	/**
	 * Add the Apple News publish meta box
	 *
	 * @since 0.9.0
	 * @param WP_Post $post
	 * @access public
	 */
	public function publish_meta_box( $post ) {

		// Only show the publish feature if the user is authorized and auto sync is not enabled.
		// Also check if the post has been previously published and/or deleted.
		$api_id = get_post_meta( $post->ID, 'apple_news_api_id', true );
		$deleted = get_post_meta( $post->ID, 'apple_news_api_deleted', true );
		$pending = get_post_meta( $post->ID, 'apple_news_api_pending', true );
		$is_preview = get_post_meta( $post->ID, 'apple_news_is_preview', true );
		$maturity_rating = get_post_meta( $post->ID, 'apple_news_maturity_rating', true );
		$is_sponsored = get_post_meta( $post->ID, 'apple_news_is_sponsored', true );
		$pullquote = get_post_meta( $post->ID, 'apple_news_pullquote', true );
		$pullquote_position = get_post_meta( $post->ID, 'apple_news_pullquote_position', true );

		// Set default values.
		if ( empty( $pullquote_position ) ) {
			$pullquote_position = 'middle';
		}

		// Create local copies of values to pass into the partial.
		$publish_action = $this->publish_action;

		include plugin_dir_path( __FILE__ ) . 'partials/metabox_publish.php';
	}

	/**
	 * Builds the sections checkboxes.
	 *
	 * @param int $post_id The post ID to query sections for.
	 *
	 * @access public
	 */
	public static function build_sections_field( $post_id ) {

		// Ensure we have sections before trying to build the field.
		$sections = Admin_Apple_Sections::get_sections();
		if ( empty( $sections ) ) {
			return;
		}

		// Determine whether to print the subheading for manual selection.
		$mappings = get_option( Admin_Apple_Sections::TAXONOMY_MAPPING_KEY );
		if ( ! empty( $mappings ) ) {
			printf(
				'<h4>%s</h4>',
				esc_html__( 'Manual Section Selection', 'apple-news' )
			);
		}

		// Iterate over the list of sections and print each.
		$apple_news_sections = get_post_meta( $post_id, 'apple_news_sections', true );
		foreach ( $sections as $section ) {
			?>
			<div class="section">
				<input id="apple-news-section-<?php echo esc_attr( $section->id ) ?>" name="apple_news_sections[]" type="checkbox" value="<?php echo esc_attr( $section->links->self ) ?>" <?php checked( self::section_is_checked( $apple_news_sections, $section->links->self, $section->isDefault ) ) ?>>
				<label for="apple-news-section-<?php echo esc_attr( $section->id ) ?>"><?php echo esc_html( $section->name ) ?></label>
			</div>
			<?php
		}
	}

	/**
	 * Builds the sections override checkbox, if necessary.
	 *
	 * @param int $post_id The ID of the post to build the checkbox field for.
	 *
	 * @access public
	 */
	public static function build_sections_override( $post_id ) {

		// Determine if there are section/taxonomy mappings set.
		$mappings = get_option( Admin_Apple_Sections::TAXONOMY_MAPPING_KEY );
		if ( empty( $mappings ) ) {
			return;
		}

		// Add checkbox to allow override of automatic section assignment.
		$mapping_taxonomy = Admin_Apple_Sections::get_mapping_taxonomy();
		$sections = get_post_meta( $post_id, 'apple_news_sections', true );
		?>
		<div class="section-override">
			<label for="apple-news-sections-by-taxonomy">
			<input id="apple-news-sections-by-taxonomy" name="apple_news_sections_by_taxonomy" type="checkbox" <?php checked( ! is_array( $sections ) ); ?> />
				<?php esc_html_e( 'Assign sections by', 'apple-news' ); ?>
				<?php echo esc_html( strtolower( $mapping_taxonomy->labels->singular_name ) ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Determine if a section is checked
	 *
	 * @param array $sections
	 * @param int $section_id
	 * @param int $is_default
	 * @access public
	 * @static
	 */
	public static function section_is_checked( $sections, $section_id, $is_default ) {
		// If no sections exist, return true if this is the default.
		// If sections is an empty array, this is intentional though and nothing should be checked.
		// If sections are provided, then only use those for matching.
		if ( ( empty( $sections ) && ! is_array( $sections ) && 1 == $is_default )
			|| ( ! empty( $sections ) && is_array( $sections ) && in_array( $section_id, $sections ) ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Registers assets used by meta boxes.
	 *
	 * @param string $hook The initiator of the action hook.
	 *
	 * @access public
	 */
	public function register_assets( $hook ) {

		// Only fire on post and new post views.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Enqueue metabox stylesheet.
		wp_enqueue_style(
			$this->plugin_slug . '_meta_boxes_css',
			plugin_dir_url( __FILE__ ) .  '../assets/css/meta-boxes.css'
		);

		// Enqueue metabox script.
		wp_enqueue_script(
			$this->plugin_slug . '_meta_boxes_js',
			plugin_dir_url( __FILE__ ) .  '../assets/js/meta-boxes.js',
			array( 'jquery' ),
			self::$version,
			true
		);

		// Localize the JS file for meta boxes.
		wp_localize_script( $this->plugin_slug . '_meta_boxes_js', 'apple_news_meta_boxes', array(
			'publish_action' => $this->publish_action,
		) );
	}

	/**
	 * Saves a cover art image to meta, given a post ID and a field name.
	 *
	 * @param int $post_id The post ID to update meta for.
	 * @param string $field_name The field name to use in POST and meta_key.
	 *
	 * @access private
	 */
	private static function _save_coverart_meta( $post_id, $field_name ) {

		// Determine if there is postdata for this key.
		if ( empty( $_POST[ $field_name ] )
			|| ! is_numeric( $_POST[ $field_name ] )
		) {
			delete_post_meta( $post_id, $field_name );

			return;
		}

		// Save post meta for this key.
		update_post_meta( $post_id, $field_name, absint( $_POST[ $field_name ] ) );
	}
}
