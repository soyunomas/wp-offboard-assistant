<?php
/**
 * Gestiona todas las interacciones con la base de datos.
 *
 * Contiene los métodos estáticos para crear la tabla de auditoría en la activación
 * y para insertar nuevos registros de log de forma segura.
 *
 * @package           WP_Offboard_Assistant
 * @subpackage        Includes
 * @author            soyunomas
 * @since             1.0.0
 */

// ¡Control de seguridad! Si este archivo es llamado directamente, abortar.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class WPOA_DB {

    /**
     * Crea la tabla de registro de auditoría en la activación del plugin.
     */
    public static function create_audit_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpoa_audit_log';
        $charset_collate = $wpdb->get_charset_collate();

        // Usamos dbDelta para crear la tabla de forma segura.
        $sql = "CREATE TABLE $table_name (
            log_id mediumint(9) NOT NULL AUTO_INCREMENT,
            log_timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            target_user_id bigint(20) unsigned NOT NULL,
            target_user_login varchar(60) NOT NULL,
            admin_user_id bigint(20) unsigned NOT NULL,
            admin_user_login varchar(60) NOT NULL,
            actions_performed text NOT NULL,
            PRIMARY KEY  (log_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Inserta un nuevo registro en la tabla de auditoría.
     *
     * @param int    $target_user_id   ID del usuario dado de baja.
     * @param string $target_user_login  Login del usuario dado de baja.
     * @param string $actions_performed Texto descriptivo de las acciones.
     */
    public static function add_log_entry( $target_user_id, $target_user_login, $actions_performed ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpoa_audit_log';

        // Validamos que el usuario actual tenga permisos.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_user = wp_get_current_user();

        // Usamos $wpdb->insert para una inserción segura. Automáticamente escapa los valores.
        $wpdb->insert(
            $table_name,
            array(
                'log_timestamp'      => current_time( 'mysql' ),
                'target_user_id'     => absint( $target_user_id ),
                'target_user_login'  => $target_user_login, // Ya es un dato del sistema, pero podría sanitizarse.
                'admin_user_id'      => $current_user->ID,
                'admin_user_login'   => $current_user->user_login,
                'actions_performed'  => sanitize_textarea_field( $actions_performed ),
            ),
            array(
                '%s', // log_timestamp
                '%d', // target_user_id
                '%s', // target_user_login
                '%d', // admin_user_id
                '%s', // admin_user_login
                '%s', // actions_performed
            )
        );
    }
}
