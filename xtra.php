<?php
/**
 * Plugin Name:       Xtra
 * Plugin URI:        https://xtra.cubedigital.com.au
 * Description:       Donors sponsor specific weekly hours of a staff position on a monthly Stripe subscription. The public grid never shows who paid — only that the hour is taken.
 * Version:           0.1.8
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Cube Digital
 * Author URI:        https://cubedigital.com.au
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       xtra
 *
 * Stripe is called with wp_remote_post against the Stripe REST API. There is no Composer
 * dependency and no bundled stripe-php SDK.
 *
 * @package Xtra
 */

defined( 'ABSPATH' ) || exit;

define( 'XTRA_VERSION', '0.1.8' );
define( 'XTRA_FILE', __FILE__ );
define( 'XTRA_DIR', plugin_dir_path( __FILE__ ) );
define( 'XTRA_URL', plugin_dir_url( __FILE__ ) );

require_once XTRA_DIR . 'includes/class-xtra-plugin.php';
require_once XTRA_DIR . 'includes/class-xtra-db.php';
require_once XTRA_DIR . 'includes/class-xtra-cpt.php';
require_once XTRA_DIR . 'includes/class-xtra-activator.php';
require_once XTRA_DIR . 'includes/class-xtra-mail.php';
require_once XTRA_DIR . 'includes/class-xtra-receipts.php';
require_once XTRA_DIR . 'includes/class-xtra-stripe.php';
require_once XTRA_DIR . 'includes/class-xtra-cron.php';
require_once XTRA_DIR . 'includes/class-xtra-rest.php';
require_once XTRA_DIR . 'includes/class-xtra-admin.php';
require_once XTRA_DIR . 'includes/class-xtra-public.php';

register_activation_hook( XTRA_FILE, array( 'Xtra_Activator', 'activate' ) );
register_deactivation_hook( XTRA_FILE, array( 'Xtra_Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Xtra_Plugin::instance()->init();
	}
);