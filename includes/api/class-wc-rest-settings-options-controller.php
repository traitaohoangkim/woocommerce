<?php
/**
 * REST API Settings Options controller
 *
 * Handles requests to the /settings/$group/$setting endpoints.
 *
 * @author   WooThemes
 * @category API
 * @package  WooCommerce/API
 * @since    3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Settings Options controller class.
 *
 * @package WooCommerce/API
 * @extends WC_REST_Controller
 */
class WC_REST_Settings_Options_Controller extends WC_REST_Controller {

	/**
	 * WP REST API namespace/version.
	 */
	protected $namespace = 'wc/v2';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<group>[\w-]+)', array(
			'args' => array(
				'group' => array(
					'description' => __( 'Settings group ID.', 'woocommerce' ),
					'type'        => 'string',
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<group>[\w-]+)/batch', array(
			'args' => array(
				'group' => array(
					'description' => __( 'Settings group ID.', 'woocommerce' ),
					'type'        => 'string',
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'batch_items' ),
				'permission_callback' => array( $this, 'update_items_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
			),
			'schema' => array( $this, 'get_public_batch_schema' ),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<group>[\w-]+)/(?P<id>[\w-]+)', array(
			'args' => array(
				'group' => array(
					'description' => __( 'Settings group ID.', 'woocommerce' ),
					'type'        => 'string',
				),
				'id' => array(
					'description' => __( 'Unique identifier for the resource.', 'woocommerce' ),
					'type'        => 'string',
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_items_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );
	}

	/**
	 * Return a single setting.
	 *
	 * @since  3.0.0
	 * @param  WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {
		$setting = $this->get_setting( $request['group'], $request['id'] );

		if ( is_wp_error( $setting ) ) {
			return $setting;
		}

		$response = $this->prepare_item_for_response( $setting, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Return all settings in a group.
	 *
	 * @since  3.0.0
	 * @param  WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$settings = $this->get_group_settings( $request['group'] );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$data = array();

		foreach ( $settings as $setting_obj ) {
			$setting = $this->prepare_item_for_response( $setting_obj, $request );
			$setting = $this->prepare_response_for_collection( $setting );
			if ( $this->is_setting_type_valid( $setting['type'] ) ) {
				$data[]  = $setting;
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get all settings in a group.
	 *
	 * @since  3.0.0
	 * @param string $group_id Group ID.
	 * @return array|WP_Error
	 */
	public function get_group_settings( $group_id ) {
		if ( empty( $group_id ) ) {
			return new WP_Error( 'rest_setting_setting_group_invalid', __( 'Invalid setting group.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		$settings = apply_filters( 'woocommerce_settings-' . $group_id, array() );

		if ( empty( $settings ) ) {
			return new WP_Error( 'rest_setting_setting_group_invalid', __( 'Invalid setting group.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		$filtered_settings = array();
		foreach ( $settings as $setting ) {
			$option_key = $setting['option_key'];
			$setting    = $this->filter_setting( $setting );
			$default    = isset( $setting['default'] ) ? $setting['default'] : '';
			// Get the option value
			if ( is_array( $option_key ) ) {
				$option           = get_option( $option_key[0] );
				$setting['value'] = isset( $option[ $option_key[1] ] ) ? $option[ $option_key[1] ] : $default;
			} else {
				$admin_setting_value = WC_Admin_Settings::get_option( $option_key );
				$setting['value']    = empty( $admin_setting_value ) ? $default : $admin_setting_value;
			}

			if ( 'multi_select_countries' === $setting['type'] ) {
				$setting['options'] = WC()->countries->get_countries();
				$setting['type']    = 'multiselect';
			}

			$filtered_settings[] = $setting;
		}

		return $filtered_settings;
	}

	/**
	 * Get setting data.
	 *
	 * @since  3.0.0
	 * @param string $group_id Group ID.
	 * @param string $setting_id Setting ID.
	 * @return stdClass|WP_Error
	 */
	public function get_setting( $group_id, $setting_id ) {
		if ( empty( $setting_id ) ) {
			return new WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		$settings = $this->get_group_settings( $group_id );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$array_key = array_keys( wp_list_pluck( $settings, 'id' ), $setting_id );

		if ( empty( $array_key ) ) {
			return new WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		$setting = $settings[ $array_key[0] ];

		if ( ! $this->is_setting_type_valid( $setting['type'] ) ) {
			return new WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		return $setting;
	}

	/**
	 * Bulk create, update and delete items.
	 *
	 * @since  3.0.0
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Of WP_Error or WP_REST_Response.
	 */
	public function batch_items( $request ) {
		// Get the request params.
		$items = array_filter( $request->get_params() );

		/*
		 * Since our batch settings update is group-specific and matches based on the route,
		 * we inject the URL parameters (containing group) into the batch items
		 */
		if ( ! empty( $items['update'] ) ) {
			$to_update = array();
			foreach ( $items['update'] as $item ) {
				$to_update[] = array_merge( $request->get_url_params(), $item );
			}
			$request = new WP_REST_Request( $request->get_method() );
			$request->set_body_params( array( 'update' => $to_update ) );
		}

		return parent::batch_items( $request );
	}

	/**
	 * Update a single setting in a group.
	 *
	 * @since  3.0.0
	 * @param  WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		$setting = $this->get_setting( $request['group'], $request['id'] );

		if ( is_wp_error( $setting ) ) {
			return $setting;
		}

		if ( is_callable( array( $this, 'validate_setting_' . $setting['type'] . '_field' ) ) ) {
			$value = $this->{'validate_setting_' . $setting['type'] . '_field'}( $request['value'], $setting );
		} else {
			$value = $this->validate_setting_text_field( $request['value'], $setting );
		}

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( is_array( $setting['option_key'] ) ) {
			$setting['value']       = $value;
			$option_key             = $setting['option_key'];
			$prev                   = get_option( $option_key[0] );
			$prev[ $option_key[1] ] = $request['value'];
			update_option( $option_key[0], $prev );
		} else {
			$update_data = array();
			$update_data[ $setting['option_key'] ] = $value;
			$setting['value']                      = $value;
			WC_Admin_Settings::save_fields( array( $setting ), $update_data );
		}

		$response = $this->prepare_item_for_response( $setting, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Prepare a single setting object for response.
	 *
	 * @since  3.0.0
	 * @param object $item Setting object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response $response Response data.
	 */
	public function prepare_item_for_response( $item, $request ) {
		unset( $item['option_key'] );
		$data     = $this->filter_setting( $item );
		$data     = $this->add_additional_fields_to_object( $data, $request );
		$data     = $this->filter_response_by_context( $data, empty( $request['context'] ) ? 'view' : $request['context'] );
		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $data['id'], $request['group'] ) );
		return $response;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @since  3.0.0
	 * @param string $setting_id Setting ID.
	 * @param string $group_id Group ID.
	 * @return array Links for the given setting.
	 */
	protected function prepare_links( $setting_id, $group_id ) {
		$base  = '/' . $this->namespace . '/' . $this->rest_base . '/' . $group_id;
		$links = array(
			'self' => array(
				'href' => rest_url( trailingslashit( $base ) . $setting_id ),
			),
			'collection' => array(
				'href' => rest_url( $base ),
			),
		);

		return $links;
	}

	/**
	 * Makes sure the current user has access to READ the settings APIs.
	 *
	 * @since  3.0.0
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! wc_rest_check_manager_permissions( 'settings', 'read' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Makes sure the current user has access to WRITE the settings APIs.
	 *
	 * @since  3.0.0
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function update_items_permissions_check( $request ) {
		if ( ! wc_rest_check_manager_permissions( 'settings', 'edit' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_edit', __( 'Sorry, you cannot edit this resource.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Filters out bad values from the settings array/filter so we
	 * only return known values via the API.
	 *
	 * @since 3.0.0
	 * @param  array $setting
	 * @return array
	 */
	public function filter_setting( $setting ) {
		$setting = array_intersect_key(
			$setting,
			array_flip( array_filter( array_keys( $setting ), array( $this, 'allowed_setting_keys' ) ) )
		);

		if ( empty( $setting['options'] ) ) {
			unset( $setting['options'] );
		}

		if ( 'image_width' === $setting['type'] ) {
			$setting = $this->cast_image_width( $setting );
		}

		return $setting;
	}

	/**
	 * For image_width, Crop can return "0" instead of false -- so we want
	 * to make sure we return these consistently the same we accept them.
	 *
	 * @since 3.0.0
	 * @param  array $setting
	 * @return array
	 */
	public function cast_image_width( $setting ) {
		foreach ( array( 'default', 'value' ) as $key ) {
			if ( isset( $setting[ $key ] ) ) {
				$setting[ $key ]['width']  = intval( $setting[ $key ]['width'] );
				$setting[ $key ]['height'] = intval( $setting[ $key ]['height'] );
				$setting[ $key ]['crop']   = (bool) $setting[ $key ]['crop'];
			}
		}
		return $setting;
	}

	/**
	 * Callback for allowed keys for each setting response.
	 *
	 * @since  3.0.0
	 * @param  string $key Key to check
	 * @return boolean
	 */
	public function allowed_setting_keys( $key ) {
		return in_array( $key, array(
			'id',
			'label',
			'description',
			'default',
			'tip',
			'placeholder',
			'type',
			'options',
			'value',
			'option_key',
		) );
	}

	/**
	 * Boolean for if a setting type is a valid supported setting type.
	 *
	 * @since  3.0.0
	 * @param  string $type
	 * @return bool
	 */
	public function is_setting_type_valid( $type ) {
		return in_array( $type, array(
			'text',         // Validates with validate_setting_text_field.
			'email',        // Validates with validate_setting_text_field.
			'number',       // Validates with validate_setting_text_field.
			'color',        // Validates with validate_setting_text_field.
			'password',     // Validates with validate_setting_text_field.
			'textarea',     // Validates with validate_setting_textarea_field.
			'select',       // Validates with validate_setting_select_field.
			'multiselect',  // Validates with validate_setting_multiselect_field.
			'radio',        // Validates with validate_setting_radio_field (-> validate_setting_select_field).
			'checkbox',     // Validates with validate_setting_checkbox_field.
			'image_width',  // Validates with validate_setting_image_width_field.
		) );
	}

	/**
	 * Get the settings schema, conforming to JSON Schema.
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'setting',
			'type'                 => 'object',
			'properties'           => array(
				'id'               => array(
					'description'  => __( 'A unique identifier for the setting.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_title',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'label'            => array(
					'description'  => __( 'A human readable translation wrapped label. Meant to be used in interfaces.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'description'      => array(
					'description'  => __( 'A human readable translation wrapped description. Meant to be used in interfaces.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'value'          => array(
					'description'  => __( 'Setting value.', 'woocommerce' ),
					'type'         => 'mixed',
					'context'      => array( 'view', 'edit' ),
				),
				'default'          => array(
					'description'  => __( 'Default value for the setting.', 'woocommerce' ),
					'type'         => 'mixed',
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'tip'              => array(
					'description'  => __( 'Extra help text explaining the setting.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'placeholder'      => array(
					'description'  => __( 'Placeholder text to be displayed in text inputs.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'type'             => array(
					'description'  => __( 'Type of setting. Allowed values: text, email, number, color, password, textarea, select, multiselect, radio, image_width, checkbox.', 'woocommerce' ),
					'type'         => 'string',
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'      => array( 'view', 'edit' ),
					'readonly'     => true,
				),
				'options'          => array(
					'description' => __( 'Array of options (key value pairs) for inputs such as select, multiselect, and radio buttons.', 'woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}
