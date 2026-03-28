<?php
/**
 * Adds S3 Auto Updater settings to Settings > General.
 *
 * Credentials defined in wp-config.php take priority over database
 * values. When a constant is defined, the corresponding input field
 * is shown as disabled with the value masked.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Auto_Updater_Settings {

    /** @var string Option prefix in the database. */
    private $prefix = 's3_auto_updater_';

    /** @var array Map of setting keys to their wp-config constant names. */
    private $fields = array(
        'bucket' => array(
            'constant' => 'S3_UPDATER_BUCKET',
            'label'    => 'S3 Bucket Name',
            'type'     => 'text',
        ),
        'region' => array(
            'constant' => 'S3_UPDATER_REGION',
            'label'    => 'S3 Region',
            'type'     => 'text',
        ),
        'key' => array(
            'constant' => 'S3_UPDATER_KEY',
            'label'    => 'Access Key ID',
            'type'     => 'text',
        ),
        'secret' => array(
            'constant' => 'S3_UPDATER_SECRET',
            'label'    => 'Secret Access Key',
            'type'     => 'password',
        ),
    );

    /**
     * Register hooks.
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Register a settings section and fields under Settings > General.
     */
    public function register_settings() {
        add_settings_section(
            's3_auto_updater_section',
            'S3 Auto Updater',
            array( $this, 'render_section' ),
            'general'
        );

        foreach ( $this->fields as $key => $field ) {
            $option_name = $this->prefix . $key;

            register_setting( 'general', $option_name, array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_field' ),
                'default'           => '',
            ) );

            add_settings_field(
                $option_name,
                $field['label'],
                array( $this, 'render_field' ),
                'general',
                's3_auto_updater_section',
                array(
                    'key'  => $key,
                    'field' => $field,
                )
            );
        }
    }

    /**
     * Section description.
     */
    public function render_section() {
        echo '<p id="s3-auto-updater-section">Enter your Amazon S3 credentials. Fields defined in <code>wp-config.php</code> take priority and cannot be edited here.</p>';
    }

    /**
     * Render a single settings field.
     *
     * @param array $args
     */
    public function render_field( $args ) {
        $key         = $args['key'];
        $field       = $args['field'];
        $option_name = $this->prefix . $key;
        $constant    = $field['constant'];
        $is_const    = defined( $constant );

        if ( $is_const ) {
            $display_value = $this->mask_value( constant( $constant ), $key );
            printf(
                '<input type="text" name="%s" value="%s" class="regular-text" disabled="disabled" /> '
                . '<span class="description">Defined in <code>wp-config.php</code></span>',
                esc_attr( $option_name ),
                esc_attr( $display_value )
            );
        } else {
            $db_value = get_option( $option_name, '' );
            $type     = $field['type'];
            printf(
                '<input type="%s" name="%s" value="%s" class="regular-text" autocomplete="off" />',
                esc_attr( $type ),
                esc_attr( $option_name ),
                esc_attr( $db_value )
            );
        }
    }

    /**
     * Sanitise a field value before saving.
     *
     * @param  string $value
     * @return string
     */
    public function sanitize_field( $value ) {
        return sanitize_text_field( trim( $value ) );
    }

    /**
     * Get a setting value, preferring wp-config constants over database.
     *
     * @param  string      $key One of: bucket, region, key, secret.
     * @return string|null      The value, or null if not set anywhere.
     */
    public function get( $key ) {
        if ( ! isset( $this->fields[ $key ] ) ) {
            return null;
        }

        $constant = $this->fields[ $key ]['constant'];

        if ( defined( $constant ) ) {
            return constant( $constant );
        }

        $value = get_option( $this->prefix . $key, '' );

        return ( '' !== $value ) ? $value : null;
    }

    /**
     * Check whether all required settings are configured
     * (from either source).
     *
     * @return bool
     */
    public function is_configured() {
        foreach ( array_keys( $this->fields ) as $key ) {
            if ( null === $this->get( $key ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Mask a value for display in disabled fields.
     * Shows the full value for bucket and region, masks key and secret.
     *
     * @param  string $value
     * @param  string $key
     * @return string
     */
    private function mask_value( $value, $key ) {
        if ( in_array( $key, array( 'bucket', 'region' ), true ) ) {
            return $value;
        }

        $length = strlen( $value );
        if ( $length <= 6 ) {
            return str_repeat( '•', $length );
        }

        return substr( $value, 0, 4 ) . str_repeat( '•', $length - 6 ) . substr( $value, -2 );
    }
}
