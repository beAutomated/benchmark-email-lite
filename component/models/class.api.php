<?php

/**
 * This file is the benchmark email api model used by WordPress and Joomla.
 *
 * @package	com_benchmarkemaillite
 * @license	GNU General Public License version 3; see LICENSE.txt
 *
 */

// No direct access to this file
defined( '_JEXEC' ) or die( 'Restricted access' );

class benchmarkemaillite_api {
	static $token, $listid, $campaignid, $handshake_version = '2.6', $apiurl = 'https://api.benchmarkemail.com/1.3/';

	// Makes Drop Down Lists From API Keys
	static function print_lists( $apis, $select='', $command='lists' ) {

		// Lookup Lists
		$lists = array();

		// Loop API Keys
		foreach( $apis as $api ) {
			if( ! $api ) { continue; }
			$lists[$api] = array();
			self::$token = $api;

			// Different Requests
			switch( $command ) {

				// Get Signup Forms And Lists
				case 'signup_forms':
					$response = call_user_func( array( 'benchmarkemaillite_api', 'signup_forms' ) );
					$lists[$api] = is_array( $response ) ? $response : array();

				// Just Get Lists
				case 'lists':
					$response = call_user_func( array( 'benchmarkemaillite_api', 'lists' ) );
					if( is_array( $response ) ) {
						foreach( $response as $key => $val ) {
							if( isset( $val['listname'] ) ) {
								$response[$key]['name'] = $val['listname'];
							}
						}
						$lists[$api] = array_merge( $lists[$api], $response );
					}
			}
		}

		// Generate Output
		$output = '';
		$i = 0;

		// Loop Keys And Lists
		foreach( $lists as $key => $list1 ) {
			if( ! $key ) { continue; }
			if( $i > 0 ) { $output .= "<option disabled='disabled' value=''></option>\n"; }

			// Output API Key Heading
			$output .= "<option disabled='disabled' value=''>{$key}</option>\n";

			// Handle API Keys With No Lists
			if( ! $list1 ) {
				$i ++;
				$list1 = array();
				$output .= '
					<option value=""' . ( ( $i == 1 ) ? ' selected="selected"' : '' ) . ' disabled="disabled">
						↳ ' . benchmarkemaillite_settings::badconnection_message() . '
					</option>
				';
				continue;
			}

			// Loop Lists For API Key
			foreach( $list1 as $list ) {
				$selected = false;
				$id = $list['id'];
				$name = $list['name'];
				$val = "{$key}|{$name}|{$id}";

				// Handle Pre Selection Of First Choice When No Choice Exists
				$i ++;
				$is_selected = ( $select === $val || ( $select == 'DEFAULTED' && $i == 1 ) );
				$option_selected = $is_selected ? " selected='selected'" : '';

				// Skip Unsubscribe List
				if( $name == 'Master Unsubscribe List' ) { continue; }

				// Output List Choice
				$output .= "<option value='{$val}'{$option_selected}>↳ {$name}</option>\n";
			}
		}

		// Done
		return $output;
	}

	// Executes Query with Time Tracking
	static function query() {
		$options = get_option( 'benchmark-email-lite_group' );
		$timeout = ( isset( $options[5] ) ) ? $options[5] : 20;
		ini_set( 'default_socket_timeout', $timeout );

		// Skip This Request If Temporarily Disabled
		if ( $disabled = get_transient( 'benchmark-email-lite_serverdown' ) ) { return false; }

		// Connect and Communicate
		$client = new IXR_Client( self::$apiurl, false, 443, $timeout );
		$start_time = microtime( true );
		$start_time_display = date( 'm/d/Y h:i:s A', current_time( 'timestamp' ) );
		$args = func_get_args();
		call_user_func_array( array( $client, 'query' ), $args );
		$response = $client->getResponse();
		$lapsed = round( microtime( true ) - $start_time, 2 );

		// Log Communication
		$logs = get_transient( 'benchmark-email-lite_log' );
		$logs = is_array( $logs ) ? $logs : array();
		$logs[] = array(
			'Time' => $start_time_display,
			'Lapsed' => $lapsed . __( ' seconds', 'benchmark-email-lite' ),
			'Request' => $args,
			'Response' => $response,
		);
		if( sizeof( $logs ) > 25 ) { array_shift( $logs ); }
		set_transient( 'benchmark-email-lite_log', $logs, 86400 );

		// If Over Limit, Disable for Five Minutes And Produce Warning
		if ( $lapsed >= $timeout ) {
			$error = sprintf(
				__(
					'Error connecting to Benchmark Email API server. '
					. 'Connection throttled until %s to prevent sluggish behavior. '
					. 'If this occurs frequently, try increasing your %sConnection Timeout setting.%s ',
					'benchmark-email-lite'
				),
				date( 'H:i:s', ( current_time( 'timestamp' ) + 300 ) ),
				'<a href="admin.php?page=benchmark-email-lite-settings">',
				'</a>'
			);
			set_transient( 'benchmark-email-lite_serverdown', true, 300 );
			set_transient( 'benchmark-email-lite_error', $error, 300 );
			return false;
		}

		// Otherwise Respond
		return $response;
	}

	// Register Vendor With API Key
	static function handshake( $tokens ) {
		foreach( $tokens as $token ) {
			if( ! $token ) { continue; }
			self::query( 'UpdatePartner', $token, 'beautomated' );
		}

		// Mark As Updated For This Version For One Month
		set_transient( 'benchmark-email-lite_handshake', self::$handshake_version, 86400 * 30 );
	}

	// Lookup Lists For Account
	static function lists() {
		$response = self::query( 'listGet', self::$token, '', 1, 100, 'name', 'asc' );
		return ( ! $response || isset( $response['faultCode'] ) ) ? array() : $response;
	}

	// Lookup Lists For Account
	static function signup_forms() {
		$response = self::query( 'listGetSignupForms', self::$token, 1, 100, 'name', 'asc' );
		return ( ! $response || isset( $response['faultCode'] ) ) ? array() : $response;
	}

	// Get Existing Subscriber Data
	static function find( $email, $listID='' ) {
		$listID = $listID ? $listID : self::$listid;
		$response = self::query( 'listGetContacts', self::$token, $listID, $email, 1, 100, 'name', 'asc' );
		return isset( $response[0]['id'] ) ? $response[0]['id'] : false;
	}

	// Add Or Update Subscriber Without Queue
	static function subscribe_simple( $listid, $data ) {

		// Check for List Subscription Preexistance
		$contactID = self::find( $data['Email'], $listid );

		// Update Preexisting List Subscription
		if( is_numeric( $contactID ) ) {
			$response = self::query(
				'listUpdateContactDetails', self::$token, $listid, $contactID, $data
			);
			if( is_array( $response ) ) {
				return 'updated';
			}
			return 'error';
		}

		// Add New List Subscription
		$response = self::query( 'listAddContactsOptin', self::$token, $listid, array( $data ), '1' );
		if( $response === 1 ) {
			return 'added';
		}
		return 'error';
	}

	// Add Or Update Subscriber To List Or Form, With Queue
	static function subscribe( $bmelist, $data ) {
		$matched_to_list = false;

		// Clean Up Submitted Data
		if( ! isset( $data['cleaned'] ) ) {
			$data['email'] = $data['Email'];
			foreach( $data as $key => $val ) {
				$data[$key] = stripslashes( $val );
			}
			$data['cleaned'] = 1;
		}

		// Check To See If Requested ID Matches A List
		$lists = self::lists();
		foreach( $lists as $list ) {
			if( $list['id'] == self::$listid ) { $matched_to_list = $list['id']; }
		}

		// Handle Sign Up Form Subscription
		if( ! $matched_to_list ) {
			$matched_existing_subscription = false;

			// Get Applicable Signup Form
			$forms = self::signup_forms();

			// Search For Signup Form Matching Requested ID
			foreach( $forms as $form ) {
				if( $form['id'] != self::$listid ) { continue; }

				// Get List(s) Used In The Signup Form
				$listnames = explode( ', ', $form['toListName'] );
				foreach( $listnames as $listname ) {

					// Match To Applicable Contact List
					foreach( $lists as $list ) {
						if( $list['listname'] != $listname ) { continue; }

						// Check for List Subscription Preexistance
						$contactID = self::find( $data['Email'], $list['id'] );

						// Update Preexisting List Subscription
						if( is_numeric( $contactID ) ) {
							$response = self::query(
								'listUpdateContactDetails', self::$token, $list['id'], $contactID, $data
							);
							if( is_array( $response ) ) {
								$matched_existing_subscription = 'updated';
							} else {
								benchmarkemaillite_widget::queue_subscription( $bmelist, $data );
								$matched_existing_subscription = 'queued';
							}
						}
					}
				}
			}

			// Updated Existing Subscription
			if( $matched_existing_subscription ) { return $matched_existing_subscription; }

			// New Signup Form Subscription
			$response = self::query( 'listAddContactsForm', self::$token, self::$listid, $data );
			if( $response !== 1 ) {
				benchmarkemaillite_widget::queue_subscription( $bmelist, $data );
				return 'queued';
			}
			return 'added';
		}

		// Check for List Subscription Preexistance
		$contactID = self::find( $data['Email'] );

		// Update Preexisting List Subscription
		if( is_numeric( $contactID ) ) {
			$response = self::query( 'listUpdateContactDetails', self::$token, self::$listid, $contactID, $data );
			if( ! is_array( $response ) ) {
				benchmarkemaillite_widget::queue_subscription( $bmelist, $data );
				return 'queued';
			}
			return 'updated';
		}

		// New List Subscription
		$response = self::query( 'listAddContactsOptin', self::$token, self::$listid, array( $data ), '1' );
		if( $response !== 1 ) {
			benchmarkemaillite_widget::queue_subscription( $bmelist, $data );
			return 'queued';
		}
		return 'added';
	}

	// Create Email Campaign
	static function campaign( $title, $from, $subject, $body, $webpageVersion ) {
		$data = array(
			'emailName' => $title,
			'toListID' => ( int ) self::$listid,
			'fromName' => $from,
			'subject' => $subject,
			'templateContent' => $body,
			'webpageVersion' => $webpageVersion,
		);

		// Check For Preexistance
		if ( $response = self::query( 'emailGet', self::$token, $title, '', 1, 1, '', '' ) ) {
			self::$campaignid = isset( $response[0]['id'] ) ? $response[0]['id'] : false;
		}

		// Handle Preexisting And Sent Campaign
		if ( self::$campaignid && $response[0]['status'] == 'Sent' ) {
			self::$campaignid = false;
			return __( 'preexists', 'benchmark-email-lite' );
		}

		// Update Existing Campaign
		if ( self::$campaignid ) {
			$data['id'] = self::$campaignid;
			if ( $response = self::query( 'emailUpdate', self::$token, $data ) ) {
				return ( $response ) ? __( 'updated', 'benchmark-email-lite' ) : false;
			}
		}

		// Create New Campaign
		if ( $response = self::query( 'emailCreate', self::$token, $data ) ) {
			self::$campaignid = $response;
			return ( $response ) ? __( 'created', 'benchmark-email-lite' ) : false;
		}
		return false;
	}

	// Test Email Campaign
	static function campaign_test( $to ) {
		if ( ! is_numeric( self::$campaignid ) || !$to ) { return; }
		return self::query('emailSendTest', self::$token, self::$campaignid, $to);
	}

	// Send Email Campaign
	static function campaign_now() {
		if ( ! is_numeric( self::$campaignid ) ) { return; }
		return self::query( 'emailSendNow', self::$token, self::$campaignid );
	}

	// Schedule Email Campaign
	static function campaign_later( $when ) {
		if ( ! is_numeric( self::$campaignid ) ) { return; }
		return self::query( 'emailSchedule', self::$token, self::$campaignid, $when );
	}

	// Get Email Campaigns
	static function campaigns() {
		return self::query( 'reportGet', self::$token, '', 1, 25, 'date', 'desc' );
	}

	// Get Email Campaign Report Summary
	static function campaign_summary( $id ) {
		return self::query( 'reportGetSummary', self::$token, (string) $id );
	}

}
