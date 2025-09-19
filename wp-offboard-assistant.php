<?php
/**
 * Plugin Name:       WP Offboard Assistant
 * Plugin URI:        https://github.com/soyunomas/wp-offboard-assistant
 * Description:       Un asistente guiado y seguro para dar de baja a usuarios de WordPress.
 * Version:           1.1.0
 * Author:            soyunomas
 * Author URI:        https://github.com/soyunomas
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-offboard-assistant
 * Domain Path:       /languages
 *
 * @package           WP_Offboard_Assistant
 * @author            soyunomas
 * @copyright         Copyright (C) 2025 soyunomas
 */

// ¡Control de seguridad! Si este archivo es llamado directamente, abortar.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// --- MODIFICACIÓN v1.1.0: Versión actualizada ---
define( 'WPOA_VERSION', '1.1.0' );
define( 'WPOA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Cargar las clases necesarias.
require_once WPOA_PLUGIN_DIR . 'includes/class-wpoa-core.php';
require_once WPOA_PLUGIN_DIR . 'includes/class-wpoa-db.php';
require_once WPOA_PLUGIN_DIR . 'includes/class-wpoa-admin-ui.php';
require_once WPOA_PLUGIN_DIR . 'includes/class-wpoa-ajax.php';

/**
 * Hook de activación del plugin: Crear la tabla de auditoría.
 */
function wpoa_activate_plugin() {
    WPOA_DB::create_audit_log_table();
}
register_activation_hook( __FILE__, 'wpoa_activate_plugin' );

/**
 * Iniciar el plugin.
 */
function wpoa_run_plugin() {
    $plugin = new WPOA_Core();
    $plugin->run();
}
wpoa_run_plugin();
