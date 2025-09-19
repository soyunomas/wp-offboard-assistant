<?php
/**
 * Gestiona la interfaz de usuario en el panel de administración.
 *
 * Se encarga de añadir el enlace "Iniciar Offboarding", crear la página de
 * registro de auditoría, encolar los assets (CSS/JS) y renderizar el HTML del modal.
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

class WPOA_Admin_UI {

    public function __construct() {
        // (Hooks sin cambios)
        add_filter( 'user_row_actions', array( $this, 'add_offboarding_link' ), 10, 2 );
        add_action( 'admin_menu', array( $this, 'add_audit_log_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_footer', array( $this, 'add_modal_html' ) );
    }

    public function add_offboarding_link( $actions, $user_object ) {
        // (Función sin cambios)
        if ( get_current_user_id() === $user_object->ID ) {
            return $actions;
        }
        if ( current_user_can( 'manage_options' ) ) {
            $actions['offboard'] = sprintf(
                '<a class="wpoa-start-offboard" href="#" data-user-id="%d" data-user-login="%s">%s</a>',
                absint( $user_object->ID ),
                esc_attr( $user_object->user_login ),
                esc_html__( 'Iniciar Offboarding', 'wp-offboard-assistant' )
            );
        }
        return $actions;
    }

    public function add_audit_log_page() {
        // (Función sin cambios)
        add_users_page(
            esc_html__( 'Registro de Offboarding', 'wp-offboard-assistant' ),
            esc_html__( 'Registro de Offboarding', 'wp-offboard-assistant' ),
            'manage_options',
            'wpoa-audit-log',
            array( $this, 'render_audit_log_page' )
        );
    }

    public function render_audit_log_page() {
        // (Función sin cambios)
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpoa_audit_log';
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY log_timestamp DESC LIMIT %d", 100 ) );
        echo '<div class="wrap"><h1>' . esc_html__( 'Registro de Auditoría de Offboarding', 'wp-offboard-assistant' ) . '</h1>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__( 'Fecha', 'wp-offboard-assistant' ) . '</th>';
        echo '<th>' . esc_html__( 'Usuario Objetivo', 'wp-offboard-assistant' ) . '</th>';
        echo '<th>' . esc_html__( 'Administrador', 'wp-offboard-assistant' ) . '</th>';
        echo '<th>' . esc_html__( 'Acciones Realizadas', 'wp-offboard-assistant' ) . '</th></tr></thead>';
        echo '<tbody>';
        if ( $logs ) {
            foreach ( $logs as $log ) {
                echo '<tr>';
                echo '<td>' . esc_html( $log->log_timestamp ) . '</td>';
                echo '<td>' . esc_html( $log->target_user_login ) . ' (ID: ' . absint($log->target_user_id) . ')</td>';
                echo '<td>' . esc_html( $log->admin_user_login ) . ' (ID: ' . absint($log->admin_user_id) . ')</td>';
                echo '<td>' . esc_html( $log->actions_performed ) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4">' . esc_html__( 'No hay registros de auditoría.', 'wp-offboard-assistant' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function enqueue_assets( $hook ) {
        // (Función sin cambios, el hook 'users.php' es correcto aquí)
        if ( 'users.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wpoa-admin-style', plugins_url( '../assets/css/admin-style.css', __FILE__ ), array(), WPOA_VERSION );
        wp_enqueue_script( 'wpoa-admin-script', plugins_url( '../assets/js/admin-script.js', __FILE__ ), array( 'jquery' ), WPOA_VERSION, true );
        wp_localize_script( 'wpoa-admin-script', 'wpoa_ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wpoa_offboard_nonce' ) ) );
    }
    
    /**
     * Imprime el HTML del modal en el footer de la página de usuarios.
     * (VERSIÓN CORREGIDA CON COMPROBACIÓN DE PANTALLA)
     */
    public function add_modal_html() {
        // --- ESTA ES LA COMPROBACIÓN CORREGIDA Y MÁS ROBUSTA ---
        // Obtenemos la pantalla actual.
        $screen = get_current_screen();
        // Nos aseguramos de que el objeto screen existe y de que su ID es 'users'.
        // Esto garantiza que el modal solo se imprima en la tabla principal de usuarios.
        if ( ! isset( $screen ) || 'users' !== $screen->id ) {
            return;
        }
        ?>
        <div id="wpoa-modal" class="wpoa-modal-hidden">
            <div class="wpoa-modal-content">
                <button class="wpoa-close-button">&times;</button>
                <h2><?php esc_html_e( 'Asistente de Offboarding para', 'wp-offboard-assistant' ); ?> <strong id="wpoa-user-login"></strong></h2>
                <div id="wpoa-spinner" class="spinner is-active"></div>
                <div id="wpoa-steps-container" style="display:none;">
                    <!-- Los pasos se llenarán dinámicamente con JS -->
                </div>
            </div>
        </div>
        <?php
    }
}
