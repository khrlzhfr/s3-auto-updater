<?php
/**
 * Lightweight S3 client using AWS Signature V4.
 *
 * Implements ListObjectsV2, GetObject, PutObject, and DeleteObject.
 * Uses wp_remote_request() so there are no external dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Auto_Updater_Client {

    /** @var string */
    private $bucket;

    /** @var string */
    private $region;

    /** @var string */
    private $access_key;

    /** @var string */
    private $secret_key;

    /** @var string */
    private $host;

    /**
     * @param string $bucket     S3 bucket name.
     * @param string $region     AWS region, e.g. 'ap-southeast-1'.
     * @param string $access_key IAM access key ID.
     * @param string $secret_key IAM secret access key.
     */
    public function __construct( $bucket, $region, $access_key, $secret_key ) {
        $this->bucket     = $bucket;
        $this->region     = $region;
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->host       = "{$bucket}.s3.{$region}.amazonaws.com";
    }

    /**
     * List objects under a given prefix.
     *
     * @param  string          $prefix  e.g. 'plugins/' or 'themes/'.
     * @return string[]|WP_Error        Array of object keys.
     */
    public function list_objects( $prefix = '' ) {
        $query_params = array(
            'list-type' => '2',
            'prefix'    => $prefix,
        );

        $response = $this->request( 'GET', '/', $query_params );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error(
                's3_list_error',
                sprintf( 'S3 ListObjectsV2 returned HTTP %d.', $code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $xml  = @simplexml_load_string( $body );

        if ( ! $xml ) {
            return new WP_Error( 's3_parse_error', 'Failed to parse S3 XML response.' );
        }

        $keys = array();
        if ( isset( $xml->Contents ) ) {
            foreach ( $xml->Contents as $item ) {
                $keys[] = (string) $item->Key;
            }
        }

        return $keys;
    }

    /**
     * Download an object to a local temp file.
     *
     * @param  string            $key  S3 object key, e.g. 'plugins/my-plugin---1.0.0.zip'.
     * @return string|WP_Error         Path to the temp file.
     */
    public function download( $key ) {
        $path    = '/' . ltrim( $key, '/' );
        $tmpfile = wp_tempnam( basename( $key ) );

        $response = $this->request( 'GET', $path, array(), '', array(
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $tmpfile,
        ) );

        if ( is_wp_error( $response ) ) {
            @unlink( $tmpfile );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            @unlink( $tmpfile );
            return new WP_Error(
                's3_download_error',
                sprintf( 'S3 GetObject returned HTTP %d for key "%s".', $code, $key )
            );
        }

        return $tmpfile;
    }

    /**
     * Upload a file to S3.
     *
     * @param  string          $key       S3 object key, e.g. 'plugins/my-plugin---1.0.0.zip'.
     * @param  string          $filepath  Path to the local file to upload.
     * @return true|WP_Error
     */
    public function upload( $key, $filepath ) {
        if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
            return new WP_Error(
                's3_upload_error',
                sprintf( 'File "%s" does not exist or is not readable.', $filepath )
            );
        }

        $body         = file_get_contents( $filepath );
        $path         = '/' . ltrim( $key, '/' );
        $content_type = 'application/zip';

        $response = $this->request( 'PUT', $path, array(), $body, array(
            'timeout' => 300,
            'headers' => array(
                'Content-Type' => $content_type,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error(
                's3_upload_error',
                sprintf( 'S3 PutObject returned HTTP %d for key "%s".', $code, $key )
            );
        }

        return true;
    }

    /**
     * Delete an object from S3.
     *
     * @param  string          $key  S3 object key.
     * @return true|WP_Error
     */
    public function delete( $key ) {
        $path     = '/' . ltrim( $key, '/' );
        $response = $this->request( 'DELETE', $path );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        // S3 returns 204 on successful delete.
        if ( $code !== 204 && $code !== 200 ) {
            return new WP_Error(
                's3_delete_error',
                sprintf( 'S3 DeleteObject returned HTTP %d for key "%s".', $code, $key )
            );
        }

        return true;
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Make a signed request to S3.
     *
     * @param  string $method       HTTP method: GET, PUT, DELETE.
     * @param  string $path         URI path, e.g. '/' or '/plugins/file.zip'.
     * @param  array  $query_params Query string parameters.
     * @param  string $body         Request body (empty for GET/DELETE).
     * @param  array  $extra_args   Additional args passed to wp_remote_request().
     * @return array|WP_Error       WP HTTP response.
     */
    private function request( $method = 'GET', $path = '/', $query_params = array(), $body = '', $extra_args = array() ) {
        $timestamp = time();
        $date      = gmdate( 'Ymd\THis\Z', $timestamp );
        $datestamp  = gmdate( 'Ymd', $timestamp );

        $encoded_path = $this->encode_uri( $path );

        ksort( $query_params );
        $canonical_query = $this->build_query_string( $query_params );

        $payload_hash = hash( 'sha256', $body );

        // Collect headers – content-type only for PUT.
        $header_lines = array(
            'host:' . $this->host,
        );
        $signed_header_names = array( 'host' );

        if ( isset( $extra_args['headers']['Content-Type'] ) ) {
            $header_lines[]        = 'content-type:' . $extra_args['headers']['Content-Type'];
            $signed_header_names[] = 'content-type';
        }

        $header_lines[]        = 'x-amz-content-sha256:' . $payload_hash;
        $signed_header_names[] = 'x-amz-content-sha256';

        $header_lines[]        = 'x-amz-date:' . $date;
        $signed_header_names[] = 'x-amz-date';

        // Sort both arrays alphabetically.
        sort( $header_lines );
        sort( $signed_header_names );

        $canonical_headers = implode( "\n", $header_lines ) . "\n";
        $signed_headers    = implode( ';', $signed_header_names );

        $canonical_request = implode( "\n", array(
            $method,
            $encoded_path,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ) );

        $scope          = "{$datestamp}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode( "\n", array(
            'AWS4-HMAC-SHA256',
            $date,
            $scope,
            hash( 'sha256', $canonical_request ),
        ) );

        $signing_key = $this->derive_signing_key( $datestamp );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $scope,
            $signed_headers,
            $signature
        );

        $url = 'https://' . $this->host . $path;
        if ( $canonical_query !== '' ) {
            $url .= '?' . $canonical_query;
        }

        $request_headers = array(
            'Host'                 => $this->host,
            'x-amz-date'           => $date,
            'x-amz-content-sha256' => $payload_hash,
            'Authorization'        => $authorization,
        );

        if ( isset( $extra_args['headers']['Content-Type'] ) ) {
            $request_headers['Content-Type'] = $extra_args['headers']['Content-Type'];
        }

        // Remove our processed headers from extra_args to avoid conflicts.
        unset( $extra_args['headers'] );

        $args = array_merge(
            array(
                'method'  => $method,
                'headers' => $request_headers,
                'body'    => ( '' !== $body ) ? $body : null,
                'timeout' => 30,
            ),
            $extra_args
        );

        return wp_remote_request( $url, $args );
    }

    /**
     * Derive the SigV4 signing key.
     *
     * @param  string $datestamp YYYYMMDD.
     * @return string           Binary signing key.
     */
    private function derive_signing_key( $datestamp ) {
        $k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );

        return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    }

    /**
     * URI-encode a path, encoding each segment individually
     * while preserving '/' separators.
     *
     * @param  string $path
     * @return string
     */
    private function encode_uri( $path ) {
        $segments = explode( '/', $path );
        $encoded  = array_map( 'rawurlencode', $segments );

        return implode( '/', $encoded );
    }

    /**
     * Build a canonical query string (RFC 3986 encoding, sorted).
     *
     * @param  array  $params
     * @return string
     */
    private function build_query_string( $params ) {
        if ( empty( $params ) ) {
            return '';
        }

        $parts = array();
        foreach ( $params as $key => $value ) {
            $parts[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
        }

        return implode( '&', $parts );
    }
}
