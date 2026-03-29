<?php
/**
 * Handles S3 credential storage and retrieval.
 *
 * Credentials defined in wp-config.php take priority over database values.
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
     * Save credentials to the database.
     * Skips fields that are defined in wp-config.php.
     *
     * @param array $data Associative array: [ 'bucket' => '...', 'region' => '...', ... ]
     */
    public function save( $data ) {
        foreach ( $this->fields as $key => $field ) {
            // Never overwrite wp-config constants.
            if ( defined( $field['constant'] ) ) {
                continue;
            }

            if ( isset( $data[ $key ] ) ) {
                $value = sanitize_text_field( trim( $data[ $key ] ) );
                update_option( $this->prefix . $key, $value );
            }
        }
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
     * Check whether a specific field is defined in wp-config.php.
     *
     * @param  string $key
     * @return bool
     */
    public function is_constant( $key ) {
        if ( ! isset( $this->fields[ $key ] ) ) {
            return false;
        }
        return defined( $this->fields[ $key ]['constant'] );
    }

    /**
     * Get field definitions for rendering.
     *
     * @return array
     */
    public function get_fields() {
        return $this->fields;
    }

    /**
     * Mask a value for display in disabled fields.
     * Shows the full value for bucket and region, masks key and secret.
     *
     * @param  string $value
     * @param  string $key
     * @return string
     */
    public function mask_value( $value, $key ) {
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
