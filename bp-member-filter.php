<?php
/*
Plugin Name: BP Member Filter
Plugin URI: http://wordpress.org/extend/plugins/bp-member-filter/
Description: A quick way to filter members using existing xprofile fields.
Author: John James Jacoby
Version: 1.0.3
Author URI: http://buddypress.org/developers/johnjamesjacoby
Site Wide Only: true
Tags: buddypress, members, filter, search, xprofile
*/

// If BuddyPress is active, load it up!
// If not, attach to the bp_init action
if ( defined( 'BP_VERSION' ) || did_action( 'bp_include' ) )
	bp_filter_loader();
else
	add_action( 'bp_include', 'bp_filter_loader' );

// Which version of the plugin is this?
define( 'BP_MEMBER_FILTER_VERSION', '1.0.3' );

/**
 * Safe loading around WP/BP load order
 */
function bp_filter_loader() {

/***
 * Classes
 */
	/* Extend the core class to allow filtering */
	if ( class_exists( 'BP_Core_User' ) ) {
		class BP_User_Filter extends BP_Core_User {
			function bp_user_filter( $user_id, $populate_extras = false ) {
				if ( $user_id ) {
					$this->id = $user_id;
					$this->populate();

					if ( $populate_extras )
						$this->populate_extras();
				}
			}

			/**
			 * filter_users
			 *
			 * This is where the magic happens. This function takes the existing
			 * BuddyPress members loop, and remixes it to allow for filtering
			 * results by specific xprofile field criteria.
			 *
			 * Note that the query that gets built here is pretty intense. The
			 * results are ran through as many LEFT JOIN's as you have searchable
			 * fields, so it's possible that this query could be JOIN'ing
			 * quite a few times depending on the number of fields available.
			 *
			 * @global array $wpdb Database class
			 * @global array $bp BuddyPress global array
			 * @param string $type The type of users to get
			 * @param integer $limit Maximum number to get
			 * @param integer $page Which page we're on
			 * @param integer $user_id If looking for a specific users friends
			 * @param string $search_terms Include extra random search criteria
			 * @param boolean $populate_extras Load up all the extra xprofile bits
			 * @return array
			 */
			function filter_users( $type, $limit = null, $page = 1, $user_id = false, $search_terms = false, $populate_extras = true ) {
				global $wpdb, $bp;

				if ( !function_exists('xprofile_install') )
					wp_die( 'This requires BuddyPress Extended Profiles to be turned on', 'bp-memberfilter' );

				$sql = array();

				$sql['select_main'] = "SELECT DISTINCT u.ID as id, u.user_registered, u.user_nicename, u.user_login, u.display_name, u.user_email";

				if ( 'active' == $type || 'online' == $type )
					$sql['select_active'] = ", um.meta_value as last_activity";

				if ( 'popular' == $type )
					$sql['select_popular'] = ", um.meta_value as total_friend_count";

				if ( 'alphabetical' == $type )
					$sql['select_alpha'] = ", pd.value as fullname";

				$sql['from'] = "FROM " . CUSTOM_USER_TABLE . " u LEFT JOIN " . CUSTOM_USER_META_TABLE . " um ON um.user_id = u.ID";

				// XProfile field prefix
				$field = "field_";

				// Construct pieces based on filter criteria
				foreach ( $_REQUEST as $key => $value ) {
					$i++;
					if ( strstr( $key, $field ) && !empty( $value ) ) {

						// Get ID of field to filter
						$field_id = substr( $key, 6, strlen( $key ) - 6 );
						$field_value = $value;

						$sql['join_profiledata'] .= "LEFT JOIN {$bp->profile->table_name_data} pd$i ON u.ID = pd$i.user_id ";

						if ( !$sql['where'] )
							$sql['where'] = 'WHERE ' . bp_core_get_status_sql( 'u.' );

						$sql['where_profiledata'] .= "AND pd$i.field_id = {$field_id} AND pd$i.value LIKE '%%$field_value%%' ";
					}
				}

				if ( 'active' == $type || 'online' == $type )
					$sql['where_active'] = "AND um.meta_key = 'last_activity'";

				if ( 'popular' == $type )
					$sql['where_popular'] = "AND um.meta_key = 'total_friend_count'";

				if ( 'online' == $type )
					$sql['where_online'] = "AND DATE_ADD( FROM_UNIXTIME(um.meta_value), INTERVAL 5 MINUTE ) >= NOW()";

				if ( 'alphabetical' == $type )
					$sql['where_alpha'] = "AND pd.field_id = 1";

				if ( $user_id && function_exists( 'friends_install' ) ) {
					$friend_ids = friends_get_friend_user_ids( $user_id );
					$friend_ids = $wpdb->escape( implode( ',', (array)$friend_ids ) );

					$sql['where_friends'] = "AND u.ID IN ({$friend_ids})";
				}

				if ( $search_terms && function_exists( 'xprofile_install' ) ) {
					$search_terms = like_escape( $wpdb->escape( $search_terms ) );
					$sql['where_searchterms'] = "AND pd.value LIKE '%%$search_terms%%'";
				}

				switch ( $type ) {
					case 'active': case 'online': default:
						$sql[] = "ORDER BY FROM_UNIXTIME(um.meta_value) DESC";
						break;
					case 'newest':
						$sql[] = "ORDER BY u.user_registered DESC";
						break;
					case 'alphabetical':
						$sql[] = "ORDER BY pd.value ASC";
						break;
					case 'random':
						$sql[] = "ORDER BY rand()";
						break;
					case 'popular':
						$sql[] = "ORDER BY CONVERT(um.meta_value, SIGNED) DESC";
						break;
				}

				if ( $limit && $page )
					$sql['pagination'] = $wpdb->prepare( "LIMIT %d, %d", intval( ( $page - 1 ) * $limit), intval( $limit ) );

				// Get paginated results
				$paged_users = $wpdb->get_results( $wpdb->prepare( join( ' ', (array)$sql ) ) );

				// Re-jig the SQL so we can get the total user count
				unset( $sql['select_main'] );

				if ( !empty( $sql['select_active'] ) )
					unset( $sql['select_active'] );

				if ( !empty( $sql['select_popular'] ) )
					unset( $sql['select_popular'] );

				if ( !empty( $sql['select_alpha'] ) )
					unset( $sql['select_alpha'] );

				if ( !empty( $sql['pagination'] ) )
					unset( $sql['pagination'] );

				array_unshift( $sql, "SELECT COUNT(DISTINCT u.ID)" );

				// Get total user results
				$total_users = $wpdb->get_var( $wpdb->prepare( join( ' ', (array)$sql ) ) );

				// Lets fetch some other useful data in a separate queries
				// This will be faster than querying the data for every user in a list.
				// We can't add these to the main query above since only users who have
				// this information will be returned (since the much of the data is in
				// usermeta and won't support any type of directional join)
				if ( is_array( $paged_users ) )
					foreach ( $paged_users as $user )
						$user_ids[] = $user->id;

				$user_ids = $wpdb->escape( join( ',', (array)$user_ids ) );

				// Add additional data to the returned results
				if ( $populate_extras )
					$paged_users = BP_Core_User::get_user_extras( &$paged_users, &$user_ids );

				// Return to lair
				return array( 'users' => $paged_users, 'total' => $total_users );
			}

			/**
			 * is_filtered
			 *
			 * Checks for the existence of a 'filter' argument and returns
			 * true if it exists
			 *
			 * @return boolean
			 */
			function is_filtered () {
				if ( isset( $_REQUEST['filter'] ) && '' != $_REQUEST['filter'] )
					return true;

				return false;
			}

			/**
			 * filtered_pagination
			 *
			 * Builds query argument string for pagination links. This string
			 * includes all of the fields included in the previously submitted
			 * post form used to filter xprofile member data.
			 *
			 * @return string
			 */
			function filtered_pagination() {
				// XProfile field prefix
				$field = "field_";

				// Construct pieces based on filter criteria
				foreach ( $_REQUEST as $key => $value ) {
					$i++;
					if ( strstr( $key, $field ) && !empty( $value ) )
						$query_to_build[$key] = $value;
				}

				if ( $query_to_build != null )
					$query_to_build['filter'] = 'true';

				$query_to_build['upage'] = '%#%';

				return add_query_arg( $query_to_build );
			}
		}
	}

/***
 * Template Tags
 */
	/**
	 * bp_members_filtered
	 *
	 * Checks $_REQUEST super global for 'filter' value.
	 *
	 * @return boolean
	 */
	function bp_members_filtered () {
		return BP_User_Filter::is_filtered();
	}

	/**
	 * bp_replace_members_filter
	 *
	 * Hooks into 'bp_has_members' filter and adjusts $members_template
	 * to include results filtered by xprofile field data.
	 *
	 * @global array $bp
	 * @param boolean $has_members
	 * @param object $members_template
	 * @return object
	 */
	function bp_replace_members_filter( $has_members, $members_template ) {
		global $bp;

		// Return if we're not filtering anything
		if ( !bp_members_filtered() )
			return $members_template;

		// Filter the users
		$members_template->members = BP_User_Filter::filter_users( $members_template->type, $members_template->pag_num, $members_template->pag_page, $members_template->user_id, $members_template->search_terms, $members_template->populate_extras );

		if ( !$max || $max >= (int)$members_template->members['total'] )
			$members_template->total_member_count = (int)$members_template->members['total'];
		else
			$members_template->total_member_count = (int)$max;

		$members_template->members = $members_template->members['users'];

		if ( $max ) {
			if ( $max >= count($members_template->members) )
				$members_template->member_count = count($members_template->members);
			else
				$members_template->member_count = (int)$max;
		} else {
			$members_template->member_count = count($members_template->members);
		}

		if ( (int) $members_template->total_member_count && (int) $members_template->pag_num ) {
			$members_template->pag_links = paginate_links( array(
				'base' => BP_User_Filter::filtered_pagination(),
				'format' => '',
				'total' => ceil( (int) $members_template->total_member_count / (int) $members_template->pag_num ),
				'current' => (int) $members_template->pag_page,
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
				'mid_size' => 1
			));
		} else {
			return false;
		}

		// Return filtered results
		return $members_template;
	}
	add_filter( 'bp_has_members', 'bp_replace_members_filter', 11, 2 );

	/**
	 * bp_filter_profile_field
	 *
	 * Handles the output of each type of core BuddyPress profile field.
	 *
	 * @global object $field
	 * @param string $field_name
	 */
	function bp_filter_profile_field ( $field_name ) {
		global $field;

		$field = bp_filter_get_profile_field( $field_name );
		$desc = isset( $field->description ) ? $field->description : '';

		switch ( bp_get_the_profile_field_type() ) :
			case 'textbox' : ?>
									<label for="<?php bp_the_profile_field_input_name() ?>"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>
									<input type="text" name="<?php bp_the_profile_field_input_name() ?>" id="<?php bp_the_profile_field_input_name() ?>" value="<?php bp_filter_the_profile_field_filter_value() ?>" />

<?php			break; ?>
<?php		case 'textarea' : ?>
									<label for="<?php bp_the_profile_field_input_name() ?>"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>
									<?php if ( trim( $desc ) != '' ) : ?><p class="description top"><?php echo $desc ?></p><?php endif; ?>
									<textarea rows="5" cols="50" name="<?php bp_the_profile_field_input_name() ?>" id="<?php bp_the_profile_field_input_name() ?>"><?php bp_filter_the_profile_field_filter_value() ?></textarea>

<?php			break; ?>
<?php		case 'selectbox' : ?>
									<label for="<?php bp_the_profile_field_input_name() ?>"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>
									<select name="<?php bp_the_profile_field_input_name() ?>" id="<?php bp_the_profile_field_input_name() ?>">
										<?php bp_filter_the_profile_field_filter_options() ?>
									</select>
									<?php if ( trim( $desc ) != '' ) : ?><p class="description"><?php echo $desc ?></p><?php endif; ?>

<?php			break; ?>
<?php		case 'multiselectbox' : ?>
									<label for="<?php bp_the_profile_field_input_name() ?>"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>
									<select name="<?php bp_the_profile_field_input_name() ?>" id="<?php bp_the_profile_field_input_name() ?>" multiple="multiple">
										<?php bp_filter_the_profile_field_filter_options() ?>
									</select>
									<?php if ( trim( $desc ) != '' ) : ?><p class="description"><?php echo $desc ?></p><?php endif; ?>

<?php			break; ?>
<?php		case 'radio' : ?>
									<label><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>
									<div class="radio">
										<?php bp_filter_the_profile_field_filter_options() ?>
										<a class="clear-value" href="javascript:clear( '<?php bp_the_profile_field_input_name() ?>' );"><?php _e( 'Clear', 'bp-filter' ) ?></a>
									</div>

<?php			break; ?>
<?php		case 'checkbox' : ?>
									<div class="checkbox">
										<span class="label"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></span>
									</div>

<?php			break; ?>
<?php	case 'datebox' : ?>
									<div class="datebox">
										<label for="<?php bp_the_profile_field_input_name() ?>_day"><?php _e( bp_get_the_profile_field_name(), 'bp-filter' ) ?></label>

										<select name="<?php bp_the_profile_field_input_name() ?>_day" id="<?php bp_the_profile_field_input_name() ?>_day">
											<?php bp_filter_the_profile_field_filter_options( 'type=day' ) ?>
										</select>

										<select name="<?php bp_the_profile_field_input_name() ?>_month" id="<?php bp_the_profile_field_input_name() ?>_month">
											<?php bp_filter_the_profile_field_filter_options( 'type=month' ) ?>
										</select>

										<select name="<?php bp_the_profile_field_input_name() ?>_year" id="<?php bp_the_profile_field_input_name() ?>_year">
											<?php bp_filter_the_profile_field_filter_options( 'type=year' ) ?>
										</select>
									</div>

<?php	endswitch;
	}

		/**
		 * bp_filter_get_profile_field(
		 *
		 * Retreives profile field from global $field object
		 *
		 * @global object $field Global object that holds field data in loop
		 * @param string $field_name The name or ID of field to get
		 * @return object
		 */
		function bp_filter_get_profile_field( $field_name ) {
			global $field;

			if ( is_numeric( $field_name ) )
				$field_id = $field_name;
			else
				$field_id = xprofile_get_field_id_from_name( $field_name );

			if ( !$field_id )
				return false;

			$field = xprofile_get_field( $field_id );

			return $field;
		}

	/* Check value of GET key and return it */
	function bp_filter_the_profile_field_filter_value() {
		echo bp_filter_get_the_profile_field_filter_value();
	}
		function bp_filter_get_the_profile_field_filter_value() {
			global $field;

			// Check to see if the posted value is different, if it is re-display
			if ( isset( $_REQUEST['field_' . $field->id] ) ) {
				if ( !empty( $_REQUEST['field_' . $field->id] ) ) {
					$field->data->value = $_REQUEST['field_' . $field->id];
					$field->data->value = bp_unserialize_profile_field( $field->data->value );
				} else {
					$field->data->value = '';
				}
			} else {
				$field->data->value = '';
			}

			return apply_filters( 'bp_filter_get_the_profile_field_filter_value', $field->data->value );
		}

	/* Helper function for fields with multiple options */
	function bp_filter_the_profile_field_filter_options( $args = '' ) {
		echo bp_filter_get_the_profile_field_filter_options( $args );
	}
		function bp_filter_get_the_profile_field_filter_options( $args = '' ) {
			global $field;

			$defaults = array (
				'type' => false
			);

			$r = wp_parse_args( $args, $defaults );
			extract( $r, EXTR_SKIP );

			$options = $field->get_children();

			$option_value = bp_filter_get_the_profile_field_filter_value();

			switch ( $field->type ) {
				case 'selectbox': case 'multiselectbox':
					if ( 'multiselectbox' != $field->type )
						$html .= '<option value="">--------</option>';

					for ( $k = 0; $k < count($options); $k++ ) {
						if ( $option_value == $options[$k]->name || $options[$k]->is_default_option ) {
							$selected = ' selected="selected"';
						} else {
							$selected = '';
						}

						$html .= apply_filters( 'bp_get_the_profile_field_options_select', '<option' . $selected . ' value="' . attribute_escape( $options[$k]->name ) . '">' . __( attribute_escape( $options[$k]->name ), 'bp-filter' ) . '</option>', $options[$k] );
					}
					break;
				case 'radio':
					$html = '<div id="field_' . $field->id . '">';

					for ( $k = 0; $k < count( $options ); $k++ ) {
						if ( $option_value == $options[$k]->name ) {
							$selected = ' checked="checked"';
						} else {
							$selected = '';
						}

						$html .= apply_filters( 'bp_get_the_profile_field_options_radio', '<label><input' . $selected . ' type="radio" name="field_' . $field->id . '" id="option_' . $options[$k]->id . '" value="' . attribute_escape( $options[$k]->name ) . '"> ' . __( attribute_escape( $options[$k]->name ), 'bp-filter' ) . '</label>', $options[$k] );
					}

					$html .= '</div>';
					break;

				// @TODO: This :P
				case 'checkbox':
					return false;

					// Check for updated posted values, but errors preventing them from being saved first time
					if ( isset( $_REQUEST['field_' . $field->id] ) && $option_value != maybe_serialize( $_REQUEST['field_' . $field->id] ) ) {
						if ( !empty( $_REQUEST['field_' . $field->id] ) )
							$option_value = $_REQUEST['field_' . $field->id];
					}

					$option_value = maybe_unserialize( $option_value );

					for ( $k = 0; $k < count($options); $k++ ) {
						for ( $j = 0; $j < count( $option_value ); $j++ ) {
							if ( $option_value[$j] == $options[$k]->name || @in_array( $options[$k]->name, $value ) ) {
								$selected = ' checked="checked"';
								break;
							}
						}

						$html .= apply_filters( 'bp_get_the_profile_field_options_checkbox', '<label><input' . $selected . ' type="checkbox" name="field_' . $field->id . '[]" id="field_' . $options[$k]->id . '_' . $k . '" value="' . attribute_escape( $options[$k]->name ) . '"> ' . __( attribute_escape( $options[$k]->name ), 'bp-filter' ) . '</label>', $options[$k] );
						$selected = '';
					}
					break;

				case 'datebox':

					if ( $field->data->value != '' ) {
						$day = date("j", $field->data->value);
						$month = date("F", $field->data->value);
						$year = date("Y", $field->data->value);
						$default_select = ' selected="selected"';
					}

					// Check for updated posted values, but errors preventing them from being saved first time
					if ( isset( $_REQUEST['field_' . $field->id . '_day'] ) && $day != $_REQUEST['field_' . $field->id . '_day'] ) {
						if ( !empty( $_REQUEST['field_' . $field->id . '_day'] ) )
							$day = $_REQUEST['field_' . $field->id . '_day'];
					}

					if ( isset( $_REQUEST['field_' . $field->id . '_month'] ) && $month != $_REQUEST['field_' . $field->id . '_month'] ) {
						if ( !empty( $_REQUEST['field_' . $field->id . '_month'] ) )
							$month = $_REQUEST['field_' . $field->id . '_month'];
					}

					if ( isset( $_REQUEST['field_' . $field->id . '_year'] ) && $year != date("j", $_REQUEST['field_' . $field->id . '_year'] ) ) {
						if ( !empty( $_REQUEST['field_' . $field->id . '_year'] ) )
							$year = $_REQUEST['field_' . $field->id . '_year'];
					}

					switch ( $type ) {
						case 'day':
							$html .= '<option value=""' . attribute_escape( $default_select ) . '>--</option>';

							for ( $i = 1; $i < 32; $i++ ) {
								if ( $day == $i ) {
									$selected = ' selected = "selected"';
								} else {
									$selected = '';
								}
								$html .= '<option value="' . $i .'"' . $selected . '>' . $i . '</option>';
							}
							break;

						case 'month':
							$eng_months = array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );

							$months = array( __( 'January', 'bp-filter' ), __( 'February', 'bp-filter' ), __( 'March', 'bp-filter' ),
									 __( 'April', 'bp-filter' ), __( 'May', 'bp-filter' ), __( 'June', 'bp-filter' ),
									 __( 'July', 'bp-filter' ), __( 'August', 'bp-filter' ), __( 'September', 'bp-filter' ),
									 __( 'October', 'bp-filter' ), __( 'November', 'bp-filter' ), __( 'December', 'bp-filter' )
									);

							$html .= '<option value=""' . attribute_escape( $default_select ) . '>------</option>';

							for ( $i = 0; $i < 12; $i++ ) {
								if ( $month == $eng_months[$i] ) {
									$selected = ' selected = "selected"';
								} else {
									$selected = '';
								}

								$html .= '<option value="' . $eng_months[$i] . '"' . $selected . '>' . $months[$i] . '</option>';
							}
							break;

						case 'year':
							$html .= '<option value=""' . attribute_escape( $default_select ) . '>----</option>';

							for ( $i = date( 'Y', time() ); $i > 1899; $i-- ) {
								if ( $year == $i ) {
									$selected = ' selected = "selected"';
								} else {
									$selected = '';
								}

								$html .= '<option value="' . $i .'"' . $selected . '>' . $i . '</option>';
							}
							break;
					}
					apply_filters( 'bp_get_the_profile_field_datebox', $html, $day, $month, $year, $default_select );
				break;
			}
			return $html;
		}
}

?>