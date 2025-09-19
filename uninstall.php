<?php
/**
 * Lógica de desinstalación para WP Offboard Assistant.
 *
 * Este archivo se ejecuta cuando un usuario elimina el plugin desde el panel
 * de administración de WordPress. Se encarga de limpiar la base de datos.
 *
 * @package           WP_Offboard_Assistant
 * @author            soyunomas
 * @since             1.0.0
 */

// Si la desinstalación no es llamada desde WordPress, salir.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'wpoa_audit_log';

// Borrar la tabla personalizada.
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Opcional: Borrar opciones guardadas si las hubiera.
// delete_option('wpoa_settings');
