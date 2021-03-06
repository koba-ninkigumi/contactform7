<?php

class WPCF7_ContactForm {

	const post_type = 'wpcf7_contact_form';

	private static $found_items = 0;
	private static $current = null;
	private static $submission = array(); // result of submit() process

	public $initial = false;
	public $id;
	public $name;
	public $title;
	public $unit_tag;
	public $responses_count = 0;
	public $scanned_form_tags;
	public $posted_data;
	public $uploaded_files = array();
	public $skip_mail = false;

	public static function count() {
		return self::$found_items;
	}

	public static function set_current( self $obj ) {
		self::$current = $obj;
	}

	public static function get_current() {
		return self::$current;
	}

	public static function reset_current() {
		self::$current = null;
	}

	private static function add_submission_status( $id, $status ) {
		self::$submission[$id] = (array) $status;
	}

	private static function get_submission_status( $id ) {
		if ( isset( self::$submission[$id] ) ) {
			return (array) self::$submission[$id];
		}
	}

	private static function get_unit_tag( $id = 0 ) {
		static $global_count = 0;

		$global_count += 1;

		if ( in_the_loop() ) {
			$unit_tag = sprintf( 'wpcf7-f%1$d-p%2$d-o%3$d',
				absint( $id ), get_the_ID(), $global_count );
		} else {
			$unit_tag = sprintf( 'wpcf7-f%1$d-o%2$d',
				absint( $id ), $global_count );
		}

		return $unit_tag;
	}

	public static function register_post_type() {
		register_post_type( self::post_type, array(
			'labels' => array(
				'name' => __( 'Contact Forms', 'contact-form-7' ),
				'singular_name' => __( 'Contact Form', 'contact-form-7' ) ),
			'rewrite' => false,
			'query_var' => false ) );
	}

	public static function find( $args = '' ) {
		$defaults = array(
			'post_status' => 'any',
			'posts_per_page' => -1,
			'offset' => 0,
			'orderby' => 'ID',
			'order' => 'ASC' );

		$args = wp_parse_args( $args, $defaults );

		$args['post_type'] = self::post_type;

		$q = new WP_Query();
		$posts = $q->query( $args );

		self::$found_items = $q->found_posts;

		$objs = array();

		foreach ( (array) $posts as $post )
			$objs[] = new self( $post );

		return $objs;
	}

	public function __construct( $post = null ) {
		$this->initial = true;

		$this->form = '';
		$this->mail = array();
		$this->mail_2 = array();
		$this->messages = array();
		$this->additional_settings = '';

		$post = get_post( $post );

		if ( $post && self::post_type == get_post_type( $post ) ) {
			$this->initial = false;
			$this->id = $post->ID;
			$this->name = $post->post_name;
			$this->title = $post->post_title;
			$this->locale = get_post_meta( $post->ID, '_locale', true );

			$props = $this->get_properties();

			foreach ( $props as $prop => $value ) {
				if ( metadata_exists( 'post', $post->ID, '_' . $prop ) )
					$this->{$prop} = get_post_meta( $post->ID, '_' . $prop, true );
				else
					$this->{$prop} = get_post_meta( $post->ID, $prop, true );
			}

			$this->upgrade();
		}

		do_action_ref_array( 'wpcf7_contact_form', array( &$this ) );
	}

	public static function generate_default_package( $args = '' ) {
		global $l10n;

		$defaults = array( 'locale' => null, 'title' => '' );
		$args = wp_parse_args( $args, $defaults );

		$locale = $args['locale'];
		$title = $args['title'];

		if ( $locale ) {
			$mo_orig = $l10n['contact-form-7'];
			wpcf7_load_textdomain( $locale );
		}

		$contact_form = new self;
		$contact_form->initial = true;
		$contact_form->title =
			( $title ? $title : __( 'Untitled', 'contact-form-7' ) );
		$contact_form->locale = ( $locale ? $locale : get_locale() );

		$props = $contact_form->get_properties();

		foreach ( $props as $prop => $value ) {
			$contact_form->{$prop} = wpcf7_get_default_template( $prop );
		}

		$contact_form = apply_filters( 'wpcf7_contact_form_default_pack',
			$contact_form, $args );

		if ( isset( $mo_orig ) ) {
			$l10n['contact-form-7'] = $mo_orig;
		}

		return $contact_form;
	}

	public function get_properties() {
		$prop_names = array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' );

		$properties = array();

		foreach ( $prop_names as $prop_name )
			$properties[$prop_name] = isset( $this->{$prop_name} ) ? $this->{$prop_name} : '';

		return apply_filters( 'wpcf7_contact_form_properties', $properties, $this );
	}

	// Return true if this form is the same one as currently POSTed.
	public function is_posted() {
		if ( ! isset( $_POST['_wpcf7_unit_tag'] ) || empty( $_POST['_wpcf7_unit_tag'] ) )
			return false;

		if ( $this->unit_tag == $_POST['_wpcf7_unit_tag'] )
			return true;

		return false;
	}

	public function clear_post() {
		$fes = $this->form_scan_shortcode();

		foreach ( $fes as $fe ) {
			if ( ! isset( $fe['name'] ) || empty( $fe['name'] ) )
				continue;

			$name = $fe['name'];

			if ( isset( $_POST[$name] ) )
				unset( $_POST[$name] );
		}
	}

	/* Generating Form HTML */

	public function form_html( $atts = array() ) {
		$atts = wp_parse_args( $atts, array(
			'html_id' => '',
			'html_class' => '' ) );

		$this->unit_tag = self::get_unit_tag( $this->id );

		$form = '<div class="wpcf7" id="' . $this->unit_tag . '">';

		$url = wpcf7_get_request_uri();

		if ( $frag = strstr( $url, '#' ) )
			$url = substr( $url, 0, -strlen( $frag ) );

		$url .= '#' . $this->unit_tag;

		$url = apply_filters( 'wpcf7_form_action_url', $url );

		$id_attr = apply_filters( 'wpcf7_form_id_attr',
			preg_replace( '/[^A-Za-z0-9:._-]/', '', $atts['html_id'] ) );

		$class = 'wpcf7-form';

		$result = self::get_submission_status( $this->id );

		if ( $this->is_posted() ) {
			if ( empty( $result['valid'] ) )
				$class .= ' invalid';
			elseif ( ! empty( $result['spam'] ) )
				$class .= ' spam';
			elseif ( ! empty( $result['mail_sent'] ) )
				$class .= ' sent';
			else
				$class .= ' failed';
		}

		$atts['html_class'] = array_unique( array_map( 'sanitize_html_class',
			explode( ' ', $atts['html_class'] ) ) );

		if ( $atts['html_class'] ) {
			$class .= ' ' . implode( ' ', $atts['html_class'] );
		}

		$class = apply_filters( 'wpcf7_form_class_attr', trim( $class ) );

		$enctype = apply_filters( 'wpcf7_form_enctype', '' );

		$novalidate = apply_filters( 'wpcf7_form_novalidate',
			wpcf7_support_html5() ? ' novalidate="novalidate"' : '' );

		$form .= '<form action="' . esc_url_raw( $url ) . '" method="post"'
			. ( $id_attr ? ' id="' . esc_attr( $id_attr ) . '"' : '' )
			. ' class="' . esc_attr( $class ) . '"'
			. $enctype . $novalidate . '>' . "\n";

		$form .= $this->form_hidden_fields();

		$form .= $this->form_elements();

		if ( ! $this->responses_count )
			$form .= $this->form_response_output();

		$form .= '</form>';

		$form .= '</div>';

		return $form;
	}

	public function form_hidden_fields() {
		$hidden_fields = array(
			'_wpcf7' => $this->id,
			'_wpcf7_version' => WPCF7_VERSION,
			'_wpcf7_locale' => $this->locale,
			'_wpcf7_unit_tag' => $this->unit_tag );

		if ( WPCF7_VERIFY_NONCE )
			$hidden_fields['_wpnonce'] = wpcf7_create_nonce( $this->id );

		$content = '';

		foreach ( $hidden_fields as $name => $value ) {
			$content .= '<input type="hidden"'
				. ' name="' . esc_attr( $name ) . '"'
				. ' value="' . esc_attr( $value ) . '" />' . "\n";
		}

		return '<div style="display: none;">' . "\n" . $content . '</div>' . "\n";
	}

	public function form_response_output() {
		$class = 'wpcf7-response-output';
		$role = '';
		$content = '';

		if ( $this->is_posted() ) { // Post response output for non-AJAX

			$result = self::get_submission_status( $this->id );

			if ( empty( $result['valid'] ) )
				$class .= ' wpcf7-validation-errors';
			elseif ( ! empty( $result['spam'] ) )
				$class .= ' wpcf7-spam-blocked';
			elseif ( ! empty( $result['mail_sent'] ) )
				$class .= ' wpcf7-mail-sent-ok';
			else
				$class .= ' wpcf7-mail-sent-ng';

			$role = 'alert';

			if ( ! empty( $result['message'] ) )
				$content = $result['message'];

		} else {
			$class .= ' wpcf7-display-none';
		}

		$atts = array(
			'class' => trim( $class ),
			'role' => trim( $role ) );

		$atts = wpcf7_format_atts( $atts );

		$output = sprintf( '<div %1$s>%2$s</div>',
			$atts, esc_html( $content ) );

		return apply_filters( 'wpcf7_form_response_output',
			$output, $class, $content, $this );
	}

	public function validation_error( $name ) {
		if ( ! $this->is_posted() )
			return '';

		$result = self::get_submission_status( $this->id );

		if ( ! isset( $result['invalid_reasons'][$name] ) )
			return '';

		$ve = trim( $result['invalid_reasons'][$name] );

		if ( empty( $ve ) )
			return '';

		$ve = '<span role="alert" class="wpcf7-not-valid-tip">'
			. esc_html( $ve ) . '</span>';

		return apply_filters( 'wpcf7_validation_error', $ve, $name, $this );
	}

	/* Form Elements */

	public function form_do_shortcode() {
		$manager = WPCF7_ShortcodeManager::get_instance();
		$form = $this->form;

		if ( WPCF7_AUTOP ) {
			$form = $manager->normalize_shortcode( $form );
			$form = wpcf7_autop( $form );
		}

		$form = $manager->do_shortcode( $form );
		$this->scanned_form_tags = $manager->get_scanned_tags();

		return $form;
	}

	public function form_scan_shortcode( $cond = null ) {
		$manager = WPCF7_ShortcodeManager::get_instance();

		if ( ! empty( $this->scanned_form_tags ) ) {
			$scanned = $this->scanned_form_tags;
		} else {
			$scanned = $manager->scan_shortcode( $this->form );
			$this->scanned_form_tags = $scanned;
		}

		if ( empty( $scanned ) )
			return null;

		if ( ! is_array( $cond ) || empty( $cond ) )
			return $scanned;

		for ( $i = 0, $size = count( $scanned ); $i < $size; $i++ ) {

			if ( isset( $cond['type'] ) ) {
				if ( is_string( $cond['type'] ) && ! empty( $cond['type'] ) ) {
					if ( $scanned[$i]['type'] != $cond['type'] ) {
						unset( $scanned[$i] );
						continue;
					}
				} elseif ( is_array( $cond['type'] ) ) {
					if ( ! in_array( $scanned[$i]['type'], $cond['type'] ) ) {
						unset( $scanned[$i] );
						continue;
					}
				}
			}

			if ( isset( $cond['name'] ) ) {
				if ( is_string( $cond['name'] ) && ! empty( $cond['name'] ) ) {
					if ( $scanned[$i]['name'] != $cond['name'] ) {
						unset ( $scanned[$i] );
						continue;
					}
				} elseif ( is_array( $cond['name'] ) ) {
					if ( ! in_array( $scanned[$i]['name'], $cond['name'] ) ) {
						unset( $scanned[$i] );
						continue;
					}
				}
			}
		}

		return array_values( $scanned );
	}

	public function form_elements() {
		return apply_filters( 'wpcf7_form_elements', $this->form_do_shortcode() );
	}

	public function setup_posted_data() {
		$posted_data = (array) $_POST;

		$fes = $this->form_scan_shortcode();

		foreach ( $fes as $fe ) {
			if ( empty( $fe['name'] ) )
				continue;

			$name = $fe['name'];
			$value = '';

			if ( isset( $posted_data[$name] ) )
				$value = $posted_data[$name];

			$pipes = $fe['pipes'];

			if ( WPCF7_USE_PIPE && is_a( $pipes, 'WPCF7_Pipes' ) && ! $pipes->zero() ) {
				if ( is_array( $value) ) {
					$new_value = array();

					foreach ( $value as $v )
						$new_value[] = $pipes->do_pipe( stripslashes( $v ) );

					$value = $new_value;
				} else {
					$value = $pipes->do_pipe( stripslashes( $value ) );
				}
			}

			$posted_data[$name] = $value;
		}

		$this->posted_data = apply_filters( 'wpcf7_posted_data', $posted_data );

		return $this->posted_data;
	}

	public function submit( $ajax = false ) {
		$result = array(
			'status' => 'init',
			'valid' => true,
			'invalid_reasons' => array(),
			'spam' => false,
			'message' => '',
			'mail_sent' => false,
			'scripts_on_sent_ok' => null,
			'scripts_on_submit' => null );

		$this->setup_posted_data();

		$validation = $this->validate();

		if ( ! $validation['valid'] ) { // Validation error occured
			$result['status'] = 'validation_failed';
			$result['valid'] = false;
			$result['invalid_reasons'] = $validation['reason'];
			$result['message'] = $this->message( 'validation_error' );

		} elseif ( ! $this->accepted() ) { // Not accepted terms
			$result['status'] = 'acceptance_missing';
			$result['message'] = $this->message( 'accept_terms' );

		} elseif ( $this->spam() ) { // Spam!
			$result['status'] = 'spam';
			$result['message'] = $this->message( 'spam' );
			$result['spam'] = true;

		} elseif ( $this->mail() ) {
			if ( $this->in_demo_mode() ) {
				$result['status'] = 'demo_mode';
			} else {
				$result['status'] = 'mail_sent';
			}

			$result['mail_sent'] = true;
			$result['message'] = $this->message( 'mail_sent_ok' );

			do_action_ref_array( 'wpcf7_mail_sent', array( &$this ) );

			if ( $ajax ) {
				$on_sent_ok = $this->additional_setting( 'on_sent_ok', false );

				if ( ! empty( $on_sent_ok ) )
					$result['scripts_on_sent_ok'] = array_map( 'wpcf7_strip_quote', $on_sent_ok );
			} else {
				$this->clear_post();
			}

		} else {
			$result['status'] = 'mail_failed';
			$result['message'] = $this->message( 'mail_sent_ng' );

			do_action_ref_array( 'wpcf7_mail_failed', array( &$this ) );
		}

		if ( $ajax ) {
			$on_submit = $this->additional_setting( 'on_submit', false );

			if ( ! empty( $on_submit ) )
				$result['scripts_on_submit'] = array_map( 'wpcf7_strip_quote', $on_submit );
		}

		// remove upload files
		foreach ( (array) $this->uploaded_files as $name => $path ) {
			@unlink( $path );
		}

		do_action_ref_array( 'wpcf7_submit', array( &$this, $result ) );

		self::add_submission_status( $this->id, $result );

		return $result;
	}

	/* Validate */

	public function validate() {
		$fes = $this->form_scan_shortcode();

		$result = array( 'valid' => true, 'reason' => array() );

		foreach ( $fes as $fe ) {
			$result = apply_filters( 'wpcf7_validate_' . $fe['type'], $result, $fe );
		}

		$result = apply_filters( 'wpcf7_validate', $result );

		return $result;
	}

	public function accepted() {
		$accepted = true;

		return apply_filters( 'wpcf7_acceptance', $accepted );
	}

	public function spam() {
		$spam = false;

		if ( WPCF7_VERIFY_NONCE && ! $this->verify_nonce() )
			$spam = true;

		if ( $this->blacklist_check() )
			$spam = true;

		return apply_filters( 'wpcf7_spam', $spam );
	}

	public function verify_nonce() {
		return wpcf7_verify_nonce( $_POST['_wpnonce'], $this->id );
	}

	public function blacklist_check() {
		$target = wpcf7_array_flatten( $this->posted_data );
		$target[] = $_SERVER['REMOTE_ADDR'];
		$target[] = $_SERVER['HTTP_USER_AGENT'];

		$target = implode( "\n", $target );

		return wpcf7_blacklist_check( $target );
	}

	/* Mail */

	public function mail() {
		if ( $this->in_demo_mode() )
			$this->skip_mail = true;

		do_action_ref_array( 'wpcf7_before_send_mail', array( &$this ) );

		if ( $this->skip_mail )
			return true;

		if ( $this->is_true( 'flamingo_only_mode' ) ){
			$send = false;
		}else{
			$send = true;
		}

		$result = $this->compose_mail( $this->setup_mail_template( $this->mail, 'mail' ), $send );

		if ( $result ) {
			$additional_mail = array();

			if ( $this->mail_2['active'] )
				$additional_mail[] = $this->setup_mail_template( $this->mail_2, 'mail_2' );

			$additional_mail = apply_filters_ref_array( 'wpcf7_additional_mail',
				array( $additional_mail, &$this ) );

			foreach ( $additional_mail as $mail )
				$this->compose_mail( $mail );

			return true;
		}

		return false;
	}

	public function setup_mail_template( $mail_template, $name = '' ) {
		$defaults = array(
			'subject' => '', 'sender' => '', 'body' => '',
			'recipient' => '', 'additional_headers' => '',
			'attachments' => '', 'use_html' => false );

		$mail_template = wp_parse_args( $mail_template, $defaults );

		$name = trim( $name );

		if ( ! empty( $name ) )
			$mail_template['name'] = $name;

		return $mail_template;
	}

	public function compose_mail( $mail_template, $send = true ) {
		$this->mail_template_in_process = $mail_template;

		$use_html = (bool) $mail_template['use_html'];

		$subject = $this->replace_mail_tags( $mail_template['subject'] );
		$sender = $this->replace_mail_tags( $mail_template['sender'] );
		$recipient = $this->replace_mail_tags( $mail_template['recipient'] );
		$additional_headers = $this->replace_mail_tags( $mail_template['additional_headers'] );

		if ( $use_html ) {
			$body = $this->replace_mail_tags( $mail_template['body'], true );
			$body = wpautop( $body );
		} else {
			$body = $this->replace_mail_tags( $mail_template['body'] );
		}

		$attachments = $this->mail_attachments( $mail_template['attachments'] );

		$components = compact(
			'subject', 'sender', 'body', 'recipient', 'additional_headers', 'attachments' );

		$components = apply_filters_ref_array( 'wpcf7_mail_components',
			array( $components, &$this ) );

		extract( $components );

		$subject = wpcf7_strip_newline( $subject );
		$sender = wpcf7_strip_newline( $sender );
		$recipient = wpcf7_strip_newline( $recipient );

		$headers = "From: $sender\n";

		if ( $use_html )
			$headers .= "Content-Type: text/html\n";

		$additional_headers = trim( $additional_headers );

		if ( $additional_headers )
			$headers .= $additional_headers . "\n";

		if ( $send )
			return @wp_mail( $recipient, $subject, $body, $headers, $attachments );

		return compact( 'subject', 'sender', 'body', 'recipient', 'headers', 'attachments' );
	}

	public function replace_mail_tags( $content, $html = false ) {
		$regex = '/(\[?)\[[\t ]*'
			. '([a-zA-Z_][0-9a-zA-Z:._-]*)' // [2] = name
			. '((?:[\t ]+"[^"]*"|[\t ]+\'[^\']*\')*)' // [3] = values
			. '[\t ]*\](\]?)/';

		if ( $html )
			$callback = array( &$this, 'mail_callback_html' );
		else
			$callback = array( &$this, 'mail_callback' );

		return preg_replace_callback( $regex, $callback, $content );
	}

	private function mail_callback_html( $matches ) {
		return $this->mail_callback( $matches, true );
	}

	private function mail_callback( $matches, $html = false ) {
		// allow [[foo]] syntax for escaping a tag
		if ( $matches[1] == '[' && $matches[4] == ']' )
			return substr( $matches[0], 1, -1 );

		$tag = $matches[0];
		$tagname = $matches[2];
		$values = $matches[3];

		if ( ! empty( $values ) ) {
			preg_match_all( '/"[^"]*"|\'[^\']*\'/', $values, $matches );
			$values = wpcf7_strip_quote_deep( $matches[0] );
		}

		$do_not_heat = false;

		if ( preg_match( '/^_raw_(.+)$/', $tagname, $matches ) ) {
			$tagname = trim( $matches[1] );
			$do_not_heat = true;
		}

		$format = '';

		if ( preg_match( '/^_format_(.+)$/', $tagname, $matches ) ) {
			$tagname = trim( $matches[1] );
			$format = $values[0];
		}

		if ( isset( $this->posted_data[$tagname] ) ) {

			if ( $do_not_heat )
				$submitted = isset( $_POST[$tagname] ) ? $_POST[$tagname] : '';
			else
				$submitted = $this->posted_data[$tagname];

			$replaced = $submitted;

			if ( ! empty( $format ) ) {
				$replaced = $this->format( $replaced, $format );
			}

			$replaced = wpcf7_flat_join( $replaced );

			if ( $html ) {
				$replaced = esc_html( $replaced );
				$replaced = wptexturize( $replaced );
			}

			$replaced = apply_filters( 'wpcf7_mail_tag_replaced', $replaced,
				$submitted, $html );

			return stripslashes( $replaced );
		}

		$special = apply_filters( 'wpcf7_special_mail_tags', '', $tagname, $html );

		if ( ! empty( $special ) )
			return $special;

		return $tag;
	}

	public function format( $original, $format ) {
		$original = (array) $original;

		foreach ( $original as $key => $value ) {
			if ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value ) ) {
				$original[$key] = mysql2date( $format, $value );
			}
		}

		return $original;
	}

	public function mail_attachments( $template ) {
		$attachments = array();

		foreach ( (array) $this->uploaded_files as $name => $path ) {
			if ( false !== strpos( $template, "[${name}]" ) && ! empty( $path ) )
				$attachments[] = $path;
		}

		foreach ( explode( "\n", $template ) as $line ) {
			$line = trim( $line );

			if ( '[' == substr( $line, 0, 1 ) )
				continue;

			$path = path_join( WP_CONTENT_DIR, $line );

			if ( @is_readable( $path ) && @is_file( $path ) )
				$attachments[] = $path;
		}

		return $attachments;
	}

	/* Message */

	public function message( $status ) {
		$messages = $this->messages;
		$message = isset( $messages[$status] ) ? $messages[$status] : '';

		$message = $this->replace_mail_tags( $message, true );

		return apply_filters( 'wpcf7_display_message', $message, $status );
	}

	/* Additional settings */

	public function additional_setting( $name, $max = 1 ) {
		$tmp_settings = (array) explode( "\n", $this->additional_settings );

		$count = 0;
		$values = array();

		foreach ( $tmp_settings as $setting ) {
			if ( preg_match('/^([a-zA-Z0-9_]+)[\t ]*:(.*)$/', $setting, $matches ) ) {
				if ( $matches[1] != $name )
					continue;

				if ( ! $max || $count < (int) $max ) {
					$values[] = trim( $matches[2] );
					$count += 1;
				}
			}
		}

		return $values;
	}

	public function is_true( $name ) {
		$settings = $this->additional_setting( $name, false );

		foreach ( $settings as $setting ) {
			if ( in_array( $setting, array( 'on', 'true', '1' ) ) )
				return true;
		}

		return false;
	}

	public function in_demo_mode() {
		return $this->is_true( 'demo_mode' );
	}

	/* Upgrade */

	public function upgrade() {
		if ( is_array( $this->mail ) ) {
			if ( ! isset( $this->mail['recipient'] ) )
				$this->mail['recipient'] = get_option( 'admin_email' );
		}

		if ( is_array( $this->messages ) ) {
			foreach ( wpcf7_messages() as $key => $arr ) {
				if ( ! isset( $this->messages[$key] ) )
					$this->messages[$key] = $arr['default'];
			}
		}
	}

	/* Save */

	public function save() {
		$props = $this->get_properties();

		$post_content = implode( "\n", wpcf7_array_flatten( $props ) );

		if ( $this->initial ) {
			$post_id = wp_insert_post( array(
				'post_type' => self::post_type,
				'post_status' => 'publish',
				'post_title' => $this->title,
				'post_content' => trim( $post_content ) ) );
		} else {
			$post_id = wp_update_post( array(
				'ID' => (int) $this->id,
				'post_status' => 'publish',
				'post_title' => $this->title,
				'post_content' => trim( $post_content ) ) );
		}

		if ( $post_id ) {
			foreach ( $props as $prop => $value )
				update_post_meta( $post_id, '_' . $prop, wpcf7_normalize_newline_deep( $value ) );

			if ( ! empty( $this->locale ) )
				update_post_meta( $post_id, '_locale', $this->locale );

			if ( $this->initial ) {
				$this->initial = false;
				$this->id = $post_id;
				do_action_ref_array( 'wpcf7_after_create', array( &$this ) );
			} else {
				do_action_ref_array( 'wpcf7_after_update', array( &$this ) );
			}

			do_action_ref_array( 'wpcf7_after_save', array( &$this ) );
		}

		return $post_id;
	}

	public function copy() {
		$new = new self;
		$new->initial = true;
		$new->title = $this->title . '_copy';
		$new->locale = $this->locale;

		$props = $this->get_properties();

		foreach ( $props as $prop => $value )
			$new->{$prop} = $value;

		$new = apply_filters_ref_array( 'wpcf7_copy', array( &$new, &$this ) );

		return $new;
	}

	public function delete() {
		if ( $this->initial )
			return;

		if ( wp_delete_post( $this->id, true ) ) {
			$this->initial = true;
			$this->id = null;
			return true;
		}

		return false;
	}
}

function wpcf7_contact_form( $id ) {
	$contact_form = new WPCF7_ContactForm( $id );

	if ( $contact_form->initial )
		return false;

	return $contact_form;
}

function wpcf7_get_contact_form_by_old_id( $old_id ) {
	global $wpdb;

	$q = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_old_cf7_unit_id'"
		. $wpdb->prepare( " AND meta_value = %d", $old_id );

	if ( $new_id = $wpdb->get_var( $q ) )
		return wpcf7_contact_form( $new_id );
}

function wpcf7_get_contact_form_by_title( $title ) {
	$page = get_page_by_title( $title, OBJECT, WPCF7_ContactForm::post_type );

	if ( $page )
		return wpcf7_contact_form( $page->ID );

	return null;
}

function wpcf7_get_contact_form_default_pack( $args = '' ) {
	$contact_form = WPCF7_ContactForm::generate_default_package( $args );

	return $contact_form;
}

function wpcf7_get_current_contact_form() {
	if ( $current = WPCF7_ContactForm::get_current() ) {
		return $current;
	}
}

function wpcf7_is_posted() {
	if ( ! $contact_form = wpcf7_get_current_contact_form() )
		return false;

	return $contact_form->is_posted();
}

function wpcf7_get_validation_error( $name ) {
	if ( ! $contact_form = wpcf7_get_current_contact_form() )
		return '';

	return $contact_form->validation_error( $name );
}

function wpcf7_get_message( $status ) {
	if ( ! $contact_form = wpcf7_get_current_contact_form() )
		return '';

	return $contact_form->message( $status );
}

function wpcf7_scan_shortcode( $cond = null ) {
	if ( ! $contact_form = wpcf7_get_current_contact_form() )
		return null;

	return $contact_form->form_scan_shortcode( $cond );
}

function wpcf7_form_controls_class( $type, $default = '' ) {
	$type = trim( $type );
	$default = array_filter( explode( ' ', $default ) );

	$classes = array_merge( array( 'wpcf7-form-control' ), $default );

	$typebase = rtrim( $type, '*' );
	$required = ( '*' == substr( $type, -1 ) );

	$classes[] = 'wpcf7-' . $typebase;

	if ( $required )
		$classes[] = 'wpcf7-validates-as-required';

	$classes = array_unique( $classes );

	return implode( ' ', $classes );
}

?>