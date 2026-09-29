<?php
/**
 * Self-updates from GitHub releases, through WordPress's normal plugin update system
 * (Dashboard > Updates, the Plugins screen, and WordPress auto-updates).
 *
 * A release is published by .github/workflows/release.yml whenever the version in
 * checkoutflow.php changes; it carries a ready-to-install checkoutflow.zip asset.
 *
 * @package CheckoutFlow
 */

namespace CheckoutFlow;

defined( 'ABSPATH' ) || exit;

class Updater {

	const REPO      = 'Nickyd36555/CheckoutFlow';
	const SLUG      = 'checkoutflow';
	const TRANSIENT = 'checkoutflow_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'github_auth' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'admin_init', array( __CLASS__, 'enable_auto_updates_once' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_cf_check_updates', array( __CLASS__, 'check_now' ) );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'keep_folder_name' ), 10, 4 );
	}

	/**
	 * Install the update into the plugin's current folder, whatever the zip's folder is called.
	 */
	public static function keep_folder_name( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( empty( $extra['plugin'] ) || self::basename() !== $extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	private static function basename() {
		return plugin_basename( CHECKOUTFLOW_FILE );
	}

	/**
	 * Latest published release, cached for 6 hours (1 hour after a failed lookup).
	 *
	 * @return array|null { version, package, url, notes, published }
	 */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		$release = null;
		if ( 200 === wp_remote_retrieve_response_code( $response ) && ! empty( $body['tag_name'] ) ) {
			foreach ( (array) $body['assets'] as $asset ) {
				if ( 'checkoutflow.zip' === $asset['name'] ) {
					$release = array(
						'version'   => ltrim( $body['tag_name'], 'vV' ),
						'package'   => $asset['browser_download_url'],
						'url'       => $body['html_url'],
						'notes'     => (string) $body['body'],
						'published' => (string) $body['published_at'],
					);
					break;
				}
			}
		}

		set_site_transient( self::TRANSIENT, $release ? $release : '', $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Tell WordPress about a newer version (or that we're current, which is what makes the
	 * "Enable auto-updates" toggle appear for plugins not hosted on wordpress.org).
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::release();
		$item    = (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $release ? $release['version'] : CHECKOUTFLOW_VERSION,
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $release ? $release['package'] : '',
			'icons'         => array(),
			'banners'       => array(),
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'compatibility' => new \stdClass(),
		);

		if ( $release && version_compare( $release['version'], CHECKOUTFLOW_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$transient->no_update[ self::basename() ] = $item;
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	/**
	 * "View details" popup on the Plugins / Updates screens.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		return (object) array(
			'name'          => 'CheckoutFlow',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : CHECKOUTFLOW_VERSION,
			'author'        => 'CheckoutFlow',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => __( 'Lightweight WooCommerce checkout, side cart, abandoned cart recovery and email marketing over SMTP.', 'checkoutflow' ),
				'changelog'   => $release && '' !== trim( $release['notes'] ) ? wp_kses_post( wpautop( make_clickable( esc_html( $release['notes'] ) ) ) ) : '',
			),
		);
	}

	/**
	 * Optional token (define CHECKOUTFLOW_GITHUB_TOKEN) to avoid GitHub's 60 requests/hour
	 * anonymous API limit on shared hosting.
	 */
	public static function github_auth( $args, $url ) {
		if ( defined( 'CHECKOUTFLOW_GITHUB_TOKEN' ) && CHECKOUTFLOW_GITHUB_TOKEN && 0 === strpos( $url, 'https://api.github.com/repos/' . self::REPO . '/' ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . CHECKOUTFLOW_GITHUB_TOKEN;
		}
		return $args;
	}

	/**
	 * Turn on WordPress auto-updates for this plugin once (the admin can switch it off
	 * on the Plugins screen and it stays off).
	 */
	public static function enable_auto_updates_once() {
		if ( get_option( 'checkoutflow_autoupdate_set' ) ) {
			return;
		}
		$enabled = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( self::basename(), $enabled, true ) ) {
			$enabled[] = self::basename();
			update_site_option( 'auto_update_plugins', $enabled );
		}
		update_option( 'checkoutflow_autoupdate_set', 1, false );
	}

	public static function row_meta( $links, $file ) {
		if ( self::basename() === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cf_check_updates' ), 'cf_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'checkoutflow' ) . '</a>';
		}
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'checkoutflow' ), 403 );
		}
		check_admin_referer( 'cf_check_updates' );
		self::flush();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
