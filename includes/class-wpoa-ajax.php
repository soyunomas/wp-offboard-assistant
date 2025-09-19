<?php
/**
 * Maneja todas las peticiones AJAX del plugin.
 *
 * Se encarga de la obtención de datos del usuario y la ejecución
 * del proceso de offboarding de forma segura.
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

class WPOA_Ajax {

    public function __construct() {
        // Hooks
        add_action( 'wp_ajax_wpoa_get_user_data', array( $this, 'get_user_data' ) );
        add_action( 'wp_ajax_wpoa_execute_offboarding', array( $this, 'execute_offboarding' ) );
    }

    public function get_user_data() {
        // Seguridad y validación inicial
        check_ajax_referer( 'wpoa_offboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permiso denegado.' ) );
        }
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => 'ID de usuario no válido.' ) );
        }

        $content_count = count_user_posts( $user_id );

        // --- MODIFICACIÓN 1: OBTENER ROL DEL USUARIO PARA MOSTRARLO EN EL DROPDOWN ---
        // Se modifica get_users para obtener el objeto de usuario completo en lugar de solo campos específicos.
        $potential_owners = get_users( array(
            'capability' => 'edit_others_posts',
            'exclude'    => array( $user_id ),
            'fields'     => 'all', // Pedimos el objeto completo para poder acceder a los roles.
        ) );
        
        $admin_options = array();
        foreach ( $potential_owners as $owner ) {
            $display_text = esc_html( $owner->user_login );

            // Comprobamos que el usuario tenga roles asignados.
            if ( ! empty( $owner->roles ) ) {
                // Obtenemos el slug del primer rol (el más común).
                $role_slug = $owner->roles[0];
                // Obtenemos el nombre traducido y legible del rol.
                $role_name = translate_user_role( $role_slug );
                // Formateamos el texto para mostrar: "username (Role)"
                $display_text = sprintf( '%s (%s)', esc_html( $owner->user_login ), esc_html( $role_name ) );
            }

            $admin_options[] = array( 'id' => $owner->ID, 'login' => $display_text );
        }

        // Devolvemos los datos al frontend. Mantenemos el nombre de la clave 'admins'
        // y la clave 'login' para no tener que modificar el archivo JavaScript.
        wp_send_json_success( array(
			'content_count' => $content_count,
			'admins'        => $admin_options,
		) );
    }

    /**
     * AJAX Handler: Ejecuta las acciones de offboarding.
     * (VERSIÓN CON MITIGACIÓN PARA WPOA-001 y NUEVAS FUNCIONALIDADES)
     */
    public function execute_offboarding() {
        // 1. SEGURIDAD Y SANITIZACIÓN
        check_ajax_referer( 'wpoa_offboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permiso denegado.' ) );
        }
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( get_current_user_id() === $user_id ) {
            wp_send_json_error( array( 'message' => 'Error de seguridad: No puedes realizar un offboarding a tu propia cuenta.' ) );
        }
        
        $action = isset( $_POST['action_type'] ) ? sanitize_key( $_POST['action_type'] ) : '';
        $reassign_to = isset( $_POST['reassign_to'] ) ? absint( $_POST['reassign_to'] ) : 0;
        $confirmation = isset( $_POST['confirmation_text'] ) ? sanitize_text_field( $_POST['confirmation_text'] ) : '';

        // --- MODIFICACIÓN 2: AÑADIR OPCIÓN PARA CAMBIAR EMAIL ---
        // Esperamos un nuevo parámetro booleano desde la petición AJAX.
        $anonymize_email = isset( $_POST['anonymize_email'] ) && 'true' === $_POST['anonymize_email'];
        
        // 2. VALIDACIÓN
        if ( ! $user_id || ! in_array( $action, array( 'delete', 'degrade' ) ) || strtoupper( $confirmation ) !== 'OFFBOARD' ) {
            wp_send_json_error( array( 'message' => 'Datos de solicitud inválidos.' ) );
        }
        $user_to_offboard = get_userdata( $user_id );
        if ( ! $user_to_offboard ) {
            wp_send_json_error( array( 'message' => 'El usuario a dar de baja no existe.' ) );
        }
        
        $actions_performed = array();

        // 3. EJECUCIÓN

        // --- MODIFICACIÓN 2 (Continuación): LÓGICA DE CAMBIO DE EMAIL ---
        // Se ejecuta antes que el resto de acciones.
        if ( $anonymize_email ) {
            // Creamos un email ficticio y no funcional usando el login del usuario.
            $new_email = $user_to_offboard->user_login . '@deleted.local';
            wp_update_user( array(
                'ID'         => $user_id,
                'user_email' => $new_email
            ) );
            $actions_performed[] = 'Correo electrónico anonimizado a ' . esc_html( $new_email ) . '.';
        }
        
        if ( $reassign_to > 0 ) {
            global $wpdb;
            $reassign_user = get_userdata($reassign_to);

            // Esta comprobación de seguridad es CLAVE. Se asegura de que, independientemente
            // de lo que se envíe desde el frontend, la reasignación solo se haga a un
            // usuario que realmente tenga permisos para editar posts de otros.
            if ( $reassign_user && user_can( $reassign_user->ID, 'edit_others_posts' ) ) {
                $wpdb->update( $wpdb->posts, array( 'post_author' => $reassign_to ), array( 'post_author' => $user_id ), array( '%d' ), array( '%d' ) );
                $actions_performed[] = "Contenido reasignado a " . esc_html($reassign_user->user_login) . " (ID: {$reassign_to}).";
            } else {
                // Si la comprobación falla, la reasignación se omite y se registra.
                $actions_performed[] = "AVISO: Intento de reasignación a un usuario sin privilegios (ID: {$reassign_to}) fue denegado.";
            }
        }
        
        $sessions = WP_Session_Tokens::get_instance( $user_id );
        $sessions->destroy_all();
        $actions_performed[] = 'Todas las sesiones activas han sido destruidas.';
        
        if ( 'degrade' === $action ) {
            wp_update_user( array( 'ID' => $user_id, 'role' => 'subscriber' ) );
            wp_set_password( wp_generate_password( 24, true, true ), $user_id );
            $actions_performed[] = 'Cuenta degradada a rol "Suscriptor" y contraseña reiniciada.';
        } elseif ( 'delete' === $action ) {
            require_once( ABSPATH . 'wp-admin/includes/user.php' );
            wp_delete_user( $user_id ); 
            $actions_performed[] = 'Cuenta de usuario eliminada permanentemente.';
        }
        
        // 4. REGISTRO Y RESPUESTA
        WPOA_DB::add_log_entry( $user_id, $user_to_offboard->user_login, implode( ' ', $actions_performed ) );
        wp_send_json_success( array( 'message' => 'Proceso de offboarding completado con éxito.' ) );
    }
}
