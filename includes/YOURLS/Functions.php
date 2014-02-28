<?php

/**
 * Functions Wrapper
 *
 * @since 2.0
 * @version 2.0-alpha
 * @copyright 2009-2014 YOURLS
 * @license MIT
 */

namespace YOURLS;

/**
 * Summary of Functions
 *
 * @deprecated Too much methods!
 * @todo We have to separate methods!
 */
class Functions {

    /**
     * Determine the allowed character set in short URLs
     *
     */
    public function get_shorturl_charset() {
        static $charset = null;
        if( $charset !== null )

            return $charset;

        if( defined('URL_CONVERT') && in_array( URL_CONVERT, array( 62, 64 ) ) ) {
            $charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        } else {
            // defined to 36, or wrongly defined
            $charset = '0123456789abcdefghijklmnopqrstuvwxyz';
        }

        $charset = apply_filter( 'get_shorturl_charset', $charset );

        return $charset;
    }

    /**
     * Make an optimized regexp pattern from a string of characters
     *
     */
    public function make_regexp_pattern( $string ) {
        $pattern = preg_quote( $string );
        // TODO: replace char sequences by smart sequences such as 0-9, a-z, A-Z ... ?
        return $pattern;
    }

    /**
     * Is a URL a short URL? Accept either 'http://sho.rt/abc' or 'abc'
     *
     */
    public function is_shorturl( $shorturl ) {
        // TODO: make sure this function evolves with the feature set.

        $is_short = false;

        // Is $shorturl a URL (http://sho.rt/abc) or a keyword (abc) ?
        if( get_protocol( $shorturl ) ) {
            $keyword = get_relative_url( $shorturl );
        } else {
            $keyword = $shorturl;
        }

        // Check if it's a valid && used keyword
        if( $keyword && $keyword == sanitize_string( $keyword ) && keyword_is_taken( $keyword ) ) {
            $is_short = true;
        }

        return apply_filter( 'is_shorturl', $is_short, $shorturl );
    }

    /**
     * Check to see if a given keyword is reserved (ie reserved URL or an existing page). Returns bool
     *
     */
    public function keyword_is_reserved( $keyword ) {
        global $reserved_URL;
        $keyword = sanitize_keyword( $keyword );
        $reserved = false;

        if ( in_array( $keyword, $reserved_URL)
            or file_exists( YOURLS_PAGEDIR ."/$keyword.php" )
            or is_dir( YOURLS_ABSPATH ."/$keyword" )
            or ( substr( $keyword, 0, strlen( YOURLS_ADMIN_LOCATION ) + 1 ) === YOURLS_ADMIN_LOCATION."/" )
        )
            $reserved = true;

        return apply_filter( 'keyword_is_reserved', $reserved, $keyword );
    }

    /**
     * Function: Get client IP Address. Returns a DB safe string.
     *
     */
    public function get_IP() {
        $ip = '';

        // Precedence: if set, X-Forwarded-For > HTTP_X_FORWARDED_FOR > HTTP_CLIENT_IP > HTTP_VIA > REMOTE_ADDR
        $headers = array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_VIA', 'REMOTE_ADDR' );
        foreach( $headers as $header ) {
            if ( !empty( $_SERVER[ $header ] ) ) {
                $ip = $_SERVER[ $header ];
                break;
            }
        }

        // headers can contain multiple IPs (X-Forwarded-For = client, proxy1, proxy2). Take first one.
        if ( strpos( $ip, ',' ) !== false )
            $ip = substr( $ip, 0, strpos( $ip, ',' ) );

        return apply_filter( 'get_IP', sanitize_ip( $ip ) );
    }

    /**
     * Get next id a new link will have if no custom keyword provided
     *
     */
    public function get_next_decimal() {
        return apply_filter( 'get_next_decimal', (int)get_option( 'next_id' ) );
    }

    /**
     * Update id for next link with no custom keyword
     *
     */
    public function update_next_decimal( $int = '' ) {
        $int = ( $int == '' ) ? get_next_decimal() + 1 : (int)$int ;
        $update = update_option( 'next_id', $int );
        do_action( 'update_next_decimal', $int, $update );

        return $update;
    }

    /**
     * Delete a link in the DB
     *
     */
    public function delete_link_by_keyword( $keyword ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_delete_link_by_keyword', null, $keyword );
        if ( null !== $pre )
            return $pre;

        global $ydb;

        $table = YOURLS_DB_TABLE_URL;
        $keyword = escape( sanitize_string( $keyword ) );
        $delete = $ydb->query("DELETE FROM `$table` WHERE `keyword` = '$keyword';");
        do_action( 'delete_link', $keyword, $delete );

        return $delete;
    }

    /**
     * SQL query to insert a new link in the DB. Returns boolean for success or failure of the inserting
     *
     */
    public function insert_link_in_db( $url, $keyword, $title = '' ) {
        global $ydb;

        $url     = escape( sanitize_url( $url ) );
        $keyword = escape( sanitize_keyword( $keyword ) );
        $title   = escape( sanitize_title( $title ) );

        $table = YOURLS_DB_TABLE_URL;
        $timestamp = date('Y-m-d H:i:s');
        $ip = get_IP();
        $insert = $ydb->query("INSERT INTO `$table` (`keyword`, `url`, `title`, `timestamp`, `ip`, `clicks`) VALUES('$keyword', '$url', '$title', '$timestamp', '$ip', 0);");

        do_action( 'insert_link', (bool)$insert, $url, $keyword, $title, $timestamp, $ip );

        return (bool)$insert;
    }

    /**
     * Check if a URL already exists in the DB. Return NULL (doesn't exist) or an object with URL informations.
     *
     */
    public function url_exists( $url ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_url_exists', false, $url );
        if ( false !== $pre )
            return $pre;

        global $ydb;
        $table = YOURLS_DB_TABLE_URL;
        $url   = escape( sanitize_url( $url) );
        $url_exists = $ydb->get_row( "SELECT * FROM `$table` WHERE `url` = '".$url."';" );

        return apply_filter( 'url_exists', $url_exists, $url );
    }

    /**
     * Add a new link in the DB, either with custom keyword, or find one
     *
     */
    public function add_new_link( $url, $keyword = '', $title = '' ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_add_new_link', false, $url, $keyword, $title );
        if ( false !== $pre )
            return $pre;

        $url = encodeURI( $url );
        $url = escape( sanitize_url( $url ) );
        if ( !$url || $url == 'http://' || $url == 'https://' ) {
            $return['status']    = 'fail';
            $return['code']      = 'error:nourl';
            $return['message']   = _( 'Missing or malformed URL' );
            $return['errorCode'] = '400';

            return apply_filter( 'add_new_link_fail_nourl', $return, $url, $keyword, $title );
        }

        // Prevent DB flood
        $ip = get_IP();
        check_IP_flood( $ip );

        // Prevent internal redirection loops: cannot shorten a shortened URL
        if( get_relative_url( $url ) ) {
            if( is_shorturl( $url ) ) {
                $return['status']    = 'fail';
                $return['code']      = 'error:noloop';
                $return['message']   = _( 'URL is a short URL' );
                $return['errorCode'] = '400';

                return apply_filter( 'add_new_link_fail_noloop', $return, $url, $keyword, $title );
            }
        }

        do_action( 'pre_add_new_link', $url, $keyword, $title );

        $strip_url = stripslashes( $url );
        $return = array();

        // duplicates allowed or new URL => store it
        if( allow_duplicate_longurls() || !( $url_exists = url_exists( $url ) ) ) {

            if( isset( $title ) && !empty( $title ) ) {
                $title = sanitize_title( $title );
            } else {
                $title = get_remote_title( $url );
            }
            $title = apply_filter( 'add_new_title', $title, $url, $keyword );

            // Custom keyword provided
            if ( $keyword ) {

                do_action( 'add_new_link_custom_keyword', $url, $keyword, $title );

                $keyword = escape( sanitize_string( $keyword ) );
                $keyword = apply_filter( 'custom_keyword', $keyword, $url, $title );
                if ( !keyword_is_free( $keyword ) ) {
                    // This shorturl either reserved or taken already
                    $return['status']  = 'fail';
                    $return['code']    = 'error:keyword';
                    $return['message'] = s( 'Short URL %s already exists in database or is reserved', $keyword );
                } else {
                    // all clear, store !
                    insert_link_in_db( $url, $keyword, $title );
                    $return['url']      = array('keyword' => $keyword, 'url' => $strip_url, 'title' => $title, 'date' => date('Y-m-d H:i:s'), 'ip' => $ip );
                    $return['status']   = 'success';
                    $return['message']  = /* //translators: eg "http://someurl/ added to DB" */ s( '%s added to database', trim_long_string( $strip_url ) );
                    $return['title']    = $title;
                    $return['html']     = table_add_row( $keyword, $url, $title, $ip, 0, time() );
                    $return['shorturl'] = SITE .'/'. $keyword;
                }

                // Create random keyword
            } else {

                do_action( 'add_new_link_create_keyword', $url, $keyword, $title );

                $timestamp = date( 'Y-m-d H:i:s' );
                $id = get_next_decimal();
                $ok = false;
                do {
                    $keyword = int2string( $id );
                    $keyword = apply_filter( 'random_keyword', $keyword, $url, $title );
                    if ( keyword_is_free($keyword) ) {
                        if( @insert_link_in_db( $url, $keyword, $title ) ){
                            // everything ok, populate needed vars
                            $return['url']      = array('keyword' => $keyword, 'url' => $strip_url, 'title' => $title, 'date' => $timestamp, 'ip' => $ip );
                            $return['status']   = 'success';
                            $return['message']  = /* //translators: eg "http://someurl/ added to DB" */ s( '%s added to database', trim_long_string( $strip_url ) );
                            $return['title']    = $title;
                            $return['html']     = table_add_row( $keyword, $url, $title, $ip, 0, time() );
                            $return['shorturl'] = SITE .'/'. $keyword;
                        }else{
                            // database error, couldnt store result
                            $return['status']   = 'fail';
                            $return['code']     = 'error:db';
                            $return['message']  = s( 'Error saving url to database' );
                        }
                        $ok = true;
                    }
                    $id++;
                } while ( !$ok );
                @update_next_decimal( $id );
            }

            // URL was already stored
        } else {

            do_action( 'add_new_link_already_stored', $url, $keyword, $title );

            $return['status']   = 'fail';
            $return['code']     = 'error:url';
            $return['url']      = array( 'keyword' => $url_exists->keyword, 'url' => $strip_url, 'title' => $url_exists->title, 'date' => $url_exists->timestamp, 'ip' => $url_exists->ip, 'clicks' => $url_exists->clicks );
            $return['message']  = /* //translators: eg "http://someurl/ already exists" */ s( '%s already exists in database', trim_long_string( $strip_url ) );
            $return['title']    = $url_exists->title;
            $return['shorturl'] = SITE .'/'. $url_exists->keyword;
        }

        do_action( 'post_add_new_link', $url, $keyword, $title );

        $return['statusCode'] = 200; // regardless of result, this is still a valid request

        return apply_filter( 'add_new_link', $return, $url, $keyword, $title );
    }

    /**
     * Edit a link
     *
     */
    public function edit_link( $url, $keyword, $newkeyword='', $title='' ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_edit_link', null, $keyword, $url, $keyword, $newkeyword, $title );
        if ( null !== $pre )
            return $pre;

        global $ydb;

        $table = YOURLS_DB_TABLE_URL;
        $url = escape (sanitize_url( $url ) );
        $keyword = escape( sanitize_string( $keyword ) );
        $title = escape( sanitize_title( $title ) );
        $newkeyword = escape( sanitize_string( $newkeyword ) );
        $strip_url = stripslashes( $url );
        $strip_title = stripslashes( $title );
        $old_url = $ydb->get_var( "SELECT `url` FROM `$table` WHERE `keyword` = '$keyword';" );

        // Check if new URL is not here already
        if ( $old_url != $url && !allow_duplicate_longurls() ) {
            $new_url_already_there = intval($ydb->get_var("SELECT COUNT(keyword) FROM `$table` WHERE `url` = '$url';"));
        } else {
            $new_url_already_there = false;
        }

        // Check if the new keyword is not here already
        if ( $newkeyword != $keyword ) {
            $keyword_is_ok = keyword_is_free( $newkeyword );
        } else {
            $keyword_is_ok = true;
        }

        do_action( 'pre_edit_link', $url, $keyword, $newkeyword, $new_url_already_there, $keyword_is_ok );

        // All clear, update
        if ( ( !$new_url_already_there || allow_duplicate_longurls() ) && $keyword_is_ok ) {
            $update_url = $ydb->query( "UPDATE `$table` SET `url` = '$url', `keyword` = '$newkeyword', `title` = '$title' WHERE `keyword` = '$keyword';" );
            if( $update_url ) {
                $return['url']     = array( 'keyword' => $newkeyword, 'shorturl' => SITE.'/'.$newkeyword, 'url' => $strip_url, 'display_url' => trim_long_string( $strip_url ), 'title' => $strip_title, 'display_title' => trim_long_string( $strip_title ) );
                $return['status']  = 'success';
                $return['message'] = _( 'Link updated in database' );
            } else {
                $return['status']  = 'fail';
                $return['message'] = /* //translators: "Error updating http://someurl/ (Shorturl: http://sho.rt/blah)" */ s( 'Error updating %s (Short URL: %s)', trim_long_string( $strip_url ), $keyword ) ;
            }

            // Nope
        } else {
            $return['status']  = 'fail';
            $return['message'] = _( 'URL or keyword already exists in database' );
        }

        return apply_filter( 'edit_link', $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok );
    }

    /**
     * Update a title link (no checks for duplicates etc..)
     *
     */
    public function edit_link_title( $keyword, $title ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_edit_link_title', null, $keyword, $title );
        if ( null !== $pre )
            return $pre;

        global $ydb;

        $keyword = escape( sanitize_keyword( $keyword ) );
        $title = escape( sanitize_title( $title ) );

        $table = YOURLS_DB_TABLE_URL;
        $update = $ydb->query("UPDATE `$table` SET `title` = '$title' WHERE `keyword` = '$keyword';");

        return $update;
    }

    /**
     * Check if keyword id is free (ie not already taken, and not reserved). Return bool.
     *
     */
    public function keyword_is_free( $keyword ) {
        $free = true;
        if ( keyword_is_reserved( $keyword ) or keyword_is_taken( $keyword ) )
            $free = false;

        return apply_filter( 'keyword_is_free', $free, $keyword );
    }

    /**
     * Check if a keyword is taken (ie there is already a short URL with this id). Return bool.
     *
     */
    public function keyword_is_taken( $keyword ) {

        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_keyword_is_taken', false, $keyword );
        if ( false !== $pre )
            return $pre;

        global $ydb;
        $keyword = escape( sanitize_keyword( $keyword ) );
        $taken = false;
        $table = YOURLS_DB_TABLE_URL;
        $already_exists = $ydb->get_var( "SELECT COUNT(`keyword`) FROM `$table` WHERE `keyword` = '$keyword';" );
        if ( $already_exists )
            $taken = true;

        return apply_filter( 'keyword_is_taken', $taken, $keyword );
    }

    /**
     * Return XML output.
     *
     */
    public function xml_encode( $array ) {
        require_once YOURLS_INC . '/functions-xml.php';
        $converter= new array2xml;

        return $converter->array2xml( $array );
    }

    /**
     * Return array of all information associated with keyword. Returns false if keyword not found. Set optional $use_cache to false to force fetching from DB
     *
     */
    public function get_keyword_infos( $keyword, $use_cache = true ) {
        global $ydb;
        $keyword = escape( sanitize_string( $keyword ) );

        do_action( 'pre_get_keyword', $keyword, $use_cache );

        if( isset( $ydb->infos[$keyword] ) && $use_cache == true ) {
            return apply_filter( 'get_keyword_infos', $ydb->infos[$keyword], $keyword );
        }

        do_action( 'get_keyword_not_cached', $keyword );

        $table = YOURLS_DB_TABLE_URL;
        $infos = $ydb->get_row( "SELECT * FROM `$table` WHERE `keyword` = '$keyword'" );

        if( $infos ) {
            $infos = (array)$infos;
            $ydb->infos[ $keyword ] = $infos;
        } else {
            $ydb->infos[ $keyword ] = false;
        }

        return apply_filter( 'get_keyword_infos', $ydb->infos[$keyword], $keyword );
    }

    /**
     * Return (string) selected information associated with a keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_info( $keyword, $field, $notfound = false ) {

        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_get_keyword_info', false, $keyword, $field, $notfound );
        if ( false !== $pre )
            return $pre;

        $keyword = sanitize_string( $keyword );
        $infos = get_keyword_infos( $keyword );

        $return = $notfound;
        if ( isset( $infos[ $field ] ) && $infos[ $field ] !== false )
            $return = $infos[ $field ];

        return apply_filter( 'get_keyword_info', $return, $keyword, $field, $notfound );
    }

    /**
     * Return title associated with keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_title( $keyword, $notfound = false ) {
        return get_keyword_info( $keyword, 'title', $notfound );
    }

    /**
     * Return long URL associated with keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_longurl( $keyword, $notfound = false ) {
        return get_keyword_info( $keyword, 'url', $notfound );
    }

    /**
     * Return number of clicks on a keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_clicks( $keyword, $notfound = false ) {
        return get_keyword_info( $keyword, 'clicks', $notfound );
    }

    /**
     * Return IP that added a keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_IP( $keyword, $notfound = false ) {
        return get_keyword_info( $keyword, 'ip', $notfound );
    }

    /**
     * Return timestamp associated with a keyword. Optional $notfound = string default message if nothing found
     *
     */
    public function get_keyword_timestamp( $keyword, $notfound = false ) {
        return get_keyword_info( $keyword, 'timestamp', $notfound );
    }

    /**
     * Update click count on a short URL. Return 0/1 for error/success.
     *
     */
    public function update_clicks( $keyword, $clicks = false ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_update_clicks', false, $keyword, $clicks );
        if ( false !== $pre )
            return $pre;

        global $ydb;
        $keyword = escape( sanitize_string( $keyword ) );
        $table = YOURLS_DB_TABLE_URL;
        if ( $clicks !== false && is_int( $clicks ) && $clicks >= 0 )
            $update = $ydb->query( "UPDATE `$table` SET `clicks` = $clicks WHERE `keyword` = '$keyword'" );
        else
            $update = $ydb->query( "UPDATE `$table` SET `clicks` = clicks + 1 WHERE `keyword` = '$keyword'" );

        do_action( 'update_clicks', $keyword, $update, $clicks );

        return $update;
    }

    /**
     * Return array of stats. (string)$filter is 'bottom', 'last', 'rand' or 'top'. (int)$limit is the number of links to return
     *
     */
    public function get_stats( $filter = 'top', $limit = 10, $start = 0 ) {
        global $ydb;

        switch( $filter ) {
            case 'bottom':
                $sort_by    = 'clicks';
                $sort_order = 'asc';
                break;
            case 'last':
                $sort_by    = 'timestamp';
                $sort_order = 'desc';
                break;
            case 'rand':
            case 'random':
                $sort_by    = 'RAND()';
                $sort_order = '';
                break;
            case 'top':
            default:
                $sort_by    = 'clicks';
                $sort_order = 'desc';
                break;
        }

        // Fetch links
        $limit = intval( $limit );
        $start = intval( $start );
        if ( $limit > 0 ) {

            $table_url = YOURLS_DB_TABLE_URL;
            $results = $ydb->get_results( "SELECT * FROM `$table_url` WHERE 1=1 ORDER BY `$sort_by` $sort_order LIMIT $start, $limit;" );

            $return = array();
            $i = 1;

            foreach ( (array)$results as $res ) {
                $return['links']['link_'.$i++] = array(
                    'shorturl' => SITE .'/'. $res->keyword,
                    'url'      => $res->url,
                    'title'    => $res->title,
                    'timestamp'=> $res->timestamp,
                    'ip'       => $res->ip,
                    'clicks'   => $res->clicks,
                );
            }
        }

        $return['stats'] = get_db_stats();

        $return['statusCode'] = 200;

        return apply_filter( 'get_stats', $return, $filter, $limit, $start );
    }

    /**
     * Return array of stats. (string)$filter is 'bottom', 'last', 'rand' or 'top'. (int)$limit is the number of links to return
     *
     */
    public function get_link_stats( $shorturl ) {
        global $ydb;

        $table_url = YOURLS_DB_TABLE_URL;
        $shorturl  = escape( sanitize_keyword( $shorturl ) );

        $res = $ydb->get_row( "SELECT * FROM `$table_url` WHERE keyword = '$shorturl';" );
        $return = array();

        if( !$res ) {
            // non existent link
            $return = array(
                'statusCode' => 404,
                'message'    => 'Error: short URL not found',
            );
        } else {
            $return = array(
                'statusCode' => 200,
                'message'    => 'success',
                'link'       => array(
                    'shorturl' => SITE .'/'. $res->keyword,
                    'url'      => $res->url,
                    'title'    => $res->title,
                    'timestamp'=> $res->timestamp,
                    'ip'       => $res->ip,
                    'clicks'   => $res->clicks,
                )
            );
        }

        return apply_filter( 'get_link_stats', $return, $shorturl );
    }

    /**
     * Get total number of URLs and sum of clicks. Input: optional "AND WHERE" clause. Returns array
     *
     * IMPORTANT NOTE: make sure arguments for the $where clause have been sanitized and escape()'d
     * before calling this function.
     *
     */
    public function get_db_stats( $where = '' ) {
        global $ydb;
        $table_url = YOURLS_DB_TABLE_URL;

        $totals = $ydb->get_row( "SELECT COUNT(keyword) as count, SUM(clicks) as sum FROM `$table_url` WHERE 1=1 $where" );
        $return = array( 'total_links' => $totals->count, 'total_clicks' => $totals->sum );

        return apply_filter( 'get_db_stats', $return, $where );
    }

    /**
     * Get number of SQL queries performed
     *
     */
    public function get_num_queries() {
        global $ydb;

        return apply_filter( 'get_num_queries', $ydb->num_queries );
    }

    /**
     * Returns a sanitized a user agent string. Given what I found on http://www.user-agents.org/ it should be OK.
     *
     */
    public function get_user_agent() {
        if ( !isset( $_SERVER['HTTP_YOURLS_USER_AGENT'] ) )
            return '-';

        $ua = strip_tags( html_entity_decode( $_SERVER['HTTP_YOURLS_USER_AGENT'] ));
        $ua = preg_replace('![^0-9a-zA-Z\':., /{}\(\)\[\]\+@&\!\?;_\-=~\*\#]!', '', $ua );

        return apply_filter( 'get_user_agent', substr( $ua, 0, 254 ) );
    }

    /**
     * Redirect to another page
     *
     */
    public function redirect( $location, $code = 301 ) {
        do_action( 'pre_redirect', $location, $code );
        $location = apply_filter( 'redirect_location', $location, $code );
        $code     = apply_filter( 'redirect_code', $code, $location );
        // Redirect, either properly if possible, or via Javascript otherwise
        if( !headers_sent() ) {
            status_header( $code );
            header( "Location: $location" );
        } else {
            redirect_javascript( $location );
        }
        die();
    }

    /**
     * Set HTTP status header
     *
     */
    public function status_header( $code = 200 ) {
        if( headers_sent() )

            return;

        $protocol = $_SERVER['SERVER_PROTOCOL'];
        if ( 'HTTP/1.1' != $protocol && 'HTTP/1.0' != $protocol )
            $protocol = 'HTTP/1.0';

        $code = intval( $code );
        $desc = get_HTTP_status( $code );

        @header ("$protocol $code $desc"); // This causes problems on IIS and some FastCGI setups
        do_action( 'status_header', $code );
    }

    /**
     * Redirect to another page using Javascript. Set optional (bool)$dontwait to false to force manual redirection (make sure a message has been read by user)
     *
     */
    public function redirect_javascript( $location, $dontwait = true ) {
        do_action( 'pre_redirect_javascript', $location, $dontwait );
        $location = apply_filter( 'redirect_javascript', $location, $dontwait );
        if( $dontwait ) {
            $message = s( 'if you are not redirected after 10 seconds, please <a href="%s">click here</a>', $location );
            echo <<<REDIR
        <script type="text/javascript">
        window.location="$location";
        </script>
        <small>($message)</small>
REDIR;
        } else {
            echo '<p>' . s( 'Please <a href="%s">click here</a>', $location ) . '</p>';
        }
        do_action( 'post_redirect_javascript', $location );
    }

    /**
     * Return a HTTP status code
     *
     */
    public function get_HTTP_status( $code ) {
        $code = intval( $code );
        $headers_desc = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',

            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            226 => 'IM Used',

            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => 'Reserved',
            307 => 'Temporary Redirect',

            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            426 => 'Upgrade Required',

            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            510 => 'Not Extended'
        );

        if ( isset( $headers_desc[$code] ) )
            return $headers_desc[$code];
        else
            return '';
    }

    /**
     * Log a redirect (for stats)
     *
     * This function does not check for the existence of a valid keyword, in order to save a query. Make sure the keyword
     * exists before calling it.
     *
     * @since 1.4
     * @param string $keyword short URL keyword
     * @return mixed Result of the INSERT query (1 on success)
     */
    public function log_redirect( $keyword ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_log_redirect', false, $keyword );
        if ( false !== $pre )
            return $pre;

        if ( !do_log_redirect() )
            return true;

        global $ydb;
        $table = YOURLS_DB_TABLE_LOG;

        $keyword  = escape( sanitize_string( $keyword ) );
        $referrer = ( isset( $_SERVER['HTTP_REFERER'] ) ? escape( sanitize_url( $_SERVER['HTTP_REFERER'] ) ) : 'direct' );
        $ua       = escape( get_user_agent() );
        $ip       = escape( get_IP() );
        $location = escape( geo_ip_to_countrycode( $ip ) );

        return $ydb->query( "INSERT INTO `$table` (click_time, shorturl, referrer, user_agent, ip_address, country_code) VALUES (NOW(), '$keyword', '$referrer', '$ua', '$ip', '$location')" );
    }

    /**
     * Check if we want to not log redirects (for stats)
     *
     */
    public function do_log_redirect() {
        return ( !defined( 'YOURLS_NOSTATS' ) || YOURLS_NOSTATS != true );
    }

    /**
     * Converts an IP to a 2 letter country code, using GeoIP database if available in includes/geo/
     *
     * @since 1.4
     * @param string $ip IP or, if empty string, will be current user IP
     * @param string $defaut Default string to return if IP doesn't resolve to a country (malformed, private IP...)
     * @return string 2 letter country code (eg 'US') or $default
     */
    public function geo_ip_to_countrycode( $ip = '', $default = '' ) {
        // Allow plugins to short-circuit the Geo IP API
        $location = apply_filter( 'shunt_geo_ip_to_countrycode', false, $ip, $default ); // at this point $ip can be '', check if your plugin hooks in here
        if ( false !== $location )
            return $location;

        if ( $ip == '' )
            $ip = get_IP();

        // Use IPv4 or IPv6 DB & functions
        if( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $db   = 'GeoIP.dat';
            $func = 'geoip_country_code_by_addr';
        } else {
            $db   = 'GeoIPv6.dat';
            $func = 'geoip_country_code_by_addr_v6';
        }

        if ( !file_exists( YOURLS_INC . '/geo/' . $db ) || !file_exists( YOURLS_INC .'/geo/geoip.inc' ) )
            return $default;

        require_once( YOURLS_INC . '/geo/geoip.inc' );
        $gi = geoip_open( YOURLS_INC . '/geo/' . $db, GEOIP_STANDARD );
        try {
            $location = call_user_func( $func, $gi, $ip );
        }
        catch ( Exception $e ) {
            $location = '';
        }
        geoip_close( $gi );

        if( '' == $location )
            $location = $default;

        return apply_filter( 'geo_ip_to_countrycode', $location, $ip, $default );
    }

    /**
     * Converts a 2 letter country code to long name (ie AU -> Australia)
     *
     */
    public function geo_countrycode_to_countryname( $code ) {
        // Allow plugins to short-circuit the Geo IP API
        $country = apply_filter( 'shunt_geo_countrycode_to_countryname', false, $code );
        if ( false !== $country )
            return $country;

        // Load the Geo class if not already done
        if( !class_exists( 'GeoIP', false ) ) {
            $temp = geo_ip_to_countrycode( '127.0.0.1' );
        }

        if( class_exists( 'GeoIP', false ) ) {
            $geo  = new GeoIP;
            $id   = $geo->GEOIP_COUNTRY_CODE_TO_NUMBER[ $code ];
            $long = $geo->GEOIP_COUNTRY_NAMES[ $id ];

            return $long;
        } else {
            return false;
        }
    }

    /**
     * Return flag URL from 2 letter country code
     *
     */
    public function geo_get_flag( $code ) {
        return apply_filter( 'geo_get_flag', 'flag-' . strtolower( $code ), $code );
    }


    /**
     * Check if an upgrade is needed
     *
     */
    public function upgrade_is_needed() {
        // check YOURLS_DB_VERSION exist && match values stored in YOURLS_DB_TABLE_OPTIONS
        list( $currentver, $currentsql ) = get_current_version_from_sql();
        if( $currentsql < YOURLS_DB_VERSION )

            return true;

        return false;
    }

    /**
     * Get current version & db version as stored in the options DB. Prior to 1.4 there's no option table.
     *
     */
    public function get_current_version_from_sql() {
        $currentver = get_option( 'version' );
        $currentsql = get_option( 'db_version' );

        // Values if version is 1.3
        if( !$currentver )
            $currentver = '1.3';
        if( !$currentsql )
            $currentsql = '100';

        return array( $currentver, $currentsql);
    }

    /**
     * Read an option from DB (or from cache if available). Return value or $default if not found
     *
     * Pretty much stolen from WordPress
     *
     * @since 1.4
     * @param string $option Option name. Expected to not be SQL-escaped.
     * @param mixed $default Optional value to return if option doesn't exist. Default false.
     * @return mixed Value set for the option.
     */
    public function get_option( $option_name, $default = false ) {
        global $ydb;

        // Allow plugins to short-circuit options
        $pre = apply_filter( 'shunt_option_'.$option_name, false );
        if ( false !== $pre )
            return $pre;

        // If option not cached already, get its value from the DB
        if ( !isset( $ydb->option[$option_name] ) ) {
            $table = YOURLS_DB_TABLE_OPTIONS;
            $option_name = escape( $option_name );
            $row = $ydb->get_row( "SELECT `option_value` FROM `$table` WHERE `option_name` = '$option_name' LIMIT 1" );
            if ( is_object( $row ) ) { // Has to be get_row instead of get_var because of funkiness with 0, false, null values
                $value = $row->option_value;
            } else { // option does not exist, so we must cache its non-existence
                $value = $default;
            }
            $ydb->option[ $option_name ] = maybe_unserialize( $value );
        }

        return apply_filter( 'get_option_'.$option_name, $ydb->option[$option_name] );
    }

    /**
     * Read all options from DB at once
     *
     * The goal is to read all options at once and then populate array $ydb->option, to prevent further
     * SQL queries if we need to read an option value later.
     * It's also a simple check whether YOURLS is installed or not (no option = assuming not installed) after
     * a check for DB server reachability has been performed
     *
     * @since 1.4
     */
    public function get_all_options() {
        global $ydb;

        // Allow plugins to short-circuit all options. (Note: regular plugins are loaded after all options)
        $pre = apply_filter( 'shunt_all_options', false );
        if ( false !== $pre )
            return $pre;

        $table = YOURLS_DB_TABLE_OPTIONS;

        $allopt = $ydb->get_results( "SELECT `option_name`, `option_value` FROM `$table` WHERE 1=1" );

        foreach( (array)$allopt as $option ) {
            $ydb->option[ $option->option_name ] = maybe_unserialize( $option->option_value );
        }

        if( property_exists( $ydb, 'option' ) ) {
            $ydb->option = apply_filter( 'get_all_options', $ydb->option );
            $ydb->installed = true;
        } else {
            // Zero option found: either YOURLS is not installed or DB server is dead
            if( !is_db_alive() ) {
                db_dead(); // YOURLS will die here
            }
            $ydb->installed = false;
        }
    }

    /**
     * Update (add if doesn't exist) an option to DB
     *
     * Pretty much stolen from WordPress
     *
     * @since 1.4
     * @param string $option Option name. Expected to not be SQL-escaped.
     * @param mixed $newvalue Option value. Must be serializable if non-scalar. Expected to not be SQL-escaped.
     * @return bool False if value was not updated, true otherwise.
     */
    public function update_option( $option_name, $newvalue ) {
        global $ydb;
        $table = YOURLS_DB_TABLE_OPTIONS;

        $option_name = trim( $option_name );
        if ( empty( $option_name ) )
            return false;

        // Use clone to break object refs -- see commit 09b989d375bac65e692277f61a84fede2fb04ae3
        if ( is_object( $newvalue ) )
            $newvalue = clone $newvalue;

        $option_name = escape( $option_name );

        $oldvalue = get_option( $option_name );

        // If the new and old values are the same, no need to update.
        if ( $newvalue === $oldvalue )
            return false;

        if ( false === $oldvalue ) {
            add_option( $option_name, $newvalue );

            return true;
        }

        $_newvalue = escape( maybe_serialize( $newvalue ) );

        do_action( 'update_option', $option_name, $oldvalue, $newvalue );

        $ydb->query( "UPDATE `$table` SET `option_value` = '$_newvalue' WHERE `option_name` = '$option_name'" );

        if ( $ydb->rows_affected == 1 ) {
            $ydb->option[ $option_name ] = $newvalue;

            return true;
        }

        return false;
    }

    /**
     * Add an option to the DB
     *
     * Pretty much stolen from WordPress
     *
     * @since 1.4
     * @param string $option Name of option to add. Expected to not be SQL-escaped.
     * @param mixed $value Optional option value. Must be serializable if non-scalar. Expected to not be SQL-escaped.
     * @return bool False if option was not added and true otherwise.
     */
    public function add_option( $name, $value = '' ) {
        global $ydb;
        $table = YOURLS_DB_TABLE_OPTIONS;

        $name = trim( $name );
        if ( empty( $name ) )
            return false;

        // Use clone to break object refs -- see commit 09b989d375bac65e692277f61a84fede2fb04ae3
        if ( is_object( $value ) )
            $value = clone $value;

        $name = escape( $name );

        // Make sure the option doesn't already exist
        if ( false !== get_option( $name ) )
            return false;

        $_value = escape( maybe_serialize( $value ) );

        do_action( 'add_option', $name, $_value );

        $ydb->query( "INSERT INTO `$table` (`option_name`, `option_value`) VALUES ('$name', '$_value')" );
        $ydb->option[ $name ] = $value;

        return true;
    }

    /**
     * Delete an option from the DB
     *
     * Pretty much stolen from WordPress
     *
     * @since 1.4
     * @param string $option Option name to delete. Expected to not be SQL-escaped.
     * @return bool True, if option is successfully deleted. False on failure.
     */
    public function delete_option( $name ) {
        global $ydb;
        $table = YOURLS_DB_TABLE_OPTIONS;
        $name = escape( $name );

        // Get the ID, if no ID then return
        $option = $ydb->get_row( "SELECT option_id FROM `$table` WHERE `option_name` = '$name'" );
        if ( is_null( $option ) || !$option->option_id )
            return false;

        do_action( 'delete_option', $name );

        $ydb->query( "DELETE FROM `$table` WHERE `option_name` = '$name'" );
        unset( $ydb->option[ $name ] );

        return true;
    }

    /**
     * Serialize data if needed. Stolen from WordPress
     *
     * @since 1.4
     * @param mixed $data Data that might be serialized.
     * @return mixed A scalar data
     */
    public function maybe_serialize( $data ) {
        if ( is_array( $data ) || is_object( $data ) )
            return serialize( $data );

        if ( is_serialized( $data, false ) )
            return serialize( $data );

        return $data;
    }

    /**
     * Check value to find if it was serialized. Stolen from WordPress
     *
     * @since 1.4
     * @param mixed $data Value to check to see if was serialized.
     * @param bool $strict Optional. Whether to be strict about the end of the string. Defaults true.
     * @return bool False if not serialized and true if it was.
     */
    public function is_serialized( $data, $strict = true ) {
        // if it isn't a string, it isn't serialized
        if ( ! is_string( $data ) )
            return false;
        $data = trim( $data );
        if ( 'N;' == $data )
            return true;
        $length = strlen( $data );
        if ( $length < 4 )
            return false;
        if ( ':' !== $data[1] )
            return false;
        if ( $strict ) {
            $lastc = $data[ $length - 1 ];
            if ( ';' !== $lastc && '}' !== $lastc )
                return false;
        } else {
            $semicolon = strpos( $data, ';' );
            $brace	 = strpos( $data, '}' );
            // Either ; or } must exist.
            if ( false === $semicolon && false === $brace )
                return false;
            // But neither must be in the first X characters.
            if ( false !== $semicolon && $semicolon < 3 )
                return false;
            if ( false !== $brace && $brace < 4 )
                return false;
        }
        $token = $data[0];
        switch ( $token ) {
            case 's' :
                if ( $strict ) {
                    if ( '"' !== $data[ $length - 2 ] )
                        return false;
                } elseif ( false === strpos( $data, '"' ) ) {
                    return false;
                }
            // or else fall through
            case 'a' :
            case 'O' :
                return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
            case 'b' :
            case 'i' :
            case 'd' :
                $end = $strict ? '$' : '';

                return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
        }

        return false;
    }

    /**
     * Unserialize value only if it was serialized. Stolen from WP
     *
     * @since 1.4
     * @param string $original Maybe unserialized original, if is needed.
     * @return mixed Unserialized data can be any type.
     */
    public function maybe_unserialize( $original ) {
        if ( is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
            return @unserialize( $original );
        return $original;
    }

    /**
     * Determine if the current page is private
     *
     */
    public function is_private() {
        $private = false;

        if ( defined('YOURLS_PRIVATE') && YOURLS_PRIVATE == true ) {

            // Allow overruling for particular pages:

            // API
            if( is_API() ) {
                if( !defined('YOURLS_PRIVATE_API') || YOURLS_PRIVATE_API != false )
                    $private = true;

                // Infos
            } elseif( is_infos() ) {
                if( !defined('YOURLS_PRIVATE_INFOS') || YOURLS_PRIVATE_INFOS !== false )
                    $private = true;

                // Others
            } else {
                $private = true;
            }

        }

        return apply_filter( 'is_private', $private );
    }

    /**
     * Show login form if required
     *
     */
    public function maybe_require_auth() {
        if( is_private() ) {
            do_action( 'require_auth' );
            require_once YOURLS_INC . '/auth.php';
        } else {
            do_action( 'require_no_auth' );
        }
    }

    /**
     * Allow several short URLs for the same long URL ?
     *
     */
    public function allow_duplicate_longurls() {
        // special treatment if API to check for WordPress plugin requests
        if( is_API() ) {
            if ( isset($_REQUEST['source']) && $_REQUEST['source'] == 'plugin' )
                return false;
        }

        return ( defined( 'UNIQUE_URLS' ) && UNIQUE_URLS == false );
    }

    /**
     * Return array of keywords that redirect to the submitted long URL
     *
     * @since 1.7
     * @param string $longurl long url
     * @param string $sort Optional ORDER BY order (can be 'keyword', 'title', 'timestamp' or'clicks')
     * @param string $order Optional SORT order (can be 'ASC' or 'DESC')
     * @return array array of keywords
     */
    public function get_longurl_keywords( $longurl, $sort = 'none', $order = 'ASC' ) {
        global $ydb;
        $longurl = escape( sanitize_url( $longurl ) );
        $table   = YOURLS_DB_TABLE_URL;
        $query   = "SELECT `keyword` FROM `$table` WHERE `url` = '$longurl'";

        // Ensure sort is a column in database (@TODO: update verification array if database changes)
        if ( in_array( $sort, array('keyword','title','timestamp','clicks') ) ) {
            $query .= " ORDER BY '".$sort."'";
            if ( in_array( $order, array( 'ASC','DESC' ) ) ) {
                $query .= " ".$order;
            }
        }

        return apply_filter( 'get_longurl_keywords', $ydb->get_col( $query ), $longurl );
    }

    /**
     * Check if an IP shortens URL too fast to prevent DB flood. Return true, or die.
     *
     */
    public function check_IP_flood( $ip = '' ) {

        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_check_IP_flood', false, $ip );
        if ( false !== $pre )
            return $pre;

        do_action( 'pre_check_ip_flood', $ip ); // at this point $ip can be '', check it if your plugin hooks in here

        // Raise white flag if installing or if no flood delay defined
        if(
            ( defined('YOURLS_FLOOD_DELAY_SECONDS') && YOURLS_FLOOD_DELAY_SECONDS === 0 ) ||
            !defined('YOURLS_FLOOD_DELAY_SECONDS') ||
            is_installing()
        )

            return true;

        // Don't throttle logged in users
        if( is_private() ) {
            if( is_valid_user() === true )

                return true;
        }

        // Don't throttle whitelist IPs
        if( defined( 'YOURLS_FLOOD_IP_WHITELIST' ) && YOURLS_FLOOD_IP_WHITELIST ) {
            $whitelist_ips = explode( ',', YOURLS_FLOOD_IP_WHITELIST );
            foreach( (array)$whitelist_ips as $whitelist_ip ) {
                $whitelist_ip = trim( $whitelist_ip );
                if ( $whitelist_ip == $ip )
                    return true;
            }
        }

        $ip = ( $ip ? sanitize_ip( $ip ) : get_IP() );
        $ip = escape( $ip );

        do_action( 'check_ip_flood', $ip );

        global $ydb;
        $table = YOURLS_DB_TABLE_URL;

        $lasttime = $ydb->get_var( "SELECT `timestamp` FROM $table WHERE `ip` = '$ip' ORDER BY `timestamp` DESC LIMIT 1" );
        if( $lasttime ) {
            $now = date( 'U' );
            $then = date( 'U', strtotime( $lasttime ) );
            if( ( $now - $then ) <= YOURLS_FLOOD_DELAY_SECONDS ) {
                // Flood!
                do_action( 'ip_flood', $ip, $now - $then );
                die( _( 'Too many URLs added too fast. Slow down please.' )/*, _( 'Forbidden' ), 403 */);
            }
        }

        return true;
    }

    /**
     * Check if YOURLS is installing
     *
     * @return bool
     * @since 1.6
     */
    public function is_installing() {
        $installing = defined( 'INSTALLING' ) && INSTALLING == true;

        return apply_filter( 'is_installing', $installing );
    }

    /**
     * Check if YOURLS is upgrading
     *
     * @return bool
     * @since 1.6
     */
    public function is_upgrading() {
        $upgrading = defined( 'UPGRADING' ) && UPGRADING == true;

        return apply_filter( 'is_upgrading', $upgrading );
    }

    /**
     * Check if YOURLS is installed
     *
     * Checks property $ydb->installed that is created by get_all_options()
     *
     * See inline comment for updating from 1.3 or prior.
     *
     */
    public function is_installed() {
        global $ydb;
        $is_installed = ( property_exists( $ydb, 'installed' ) && $ydb->installed == true );

        return apply_filter( 'is_installed', $is_installed );

        /* Note: this test won't work on YOURLS 1.3 or older (Aug 2009...)
        Should someone complain that they cannot upgrade directly from
        1.3 to 1.7: first, laugh at them, then ask them to install 1.6 first.
         */
    }

    /**
     * Generate random string of (int)$length length and type $type (see function for details)
     *
     */
    public function rnd_string ( $length = 5, $type = 0, $charlist = '' ) {
        $str = '';
        $length = intval( $length );

        // define possible characters
        switch ( $type ) {

            // custom char list, or comply to charset as defined in config
            case '0':
                $possible = $charlist ? $charlist : get_shorturl_charset() ;
                break;

            // no vowels to make no offending word, no 0/1/o/l to avoid confusion between letters & digits. Perfect for passwords.
            case '1':
                $possible = "23456789bcdfghjkmnpqrstvwxyz";
                break;

            // Same, with lower + upper
            case '2':
                $possible = "23456789bcdfghjkmnpqrstvwxyzBCDFGHJKMNPQRSTVWXYZ";
                break;

            // all letters, lowercase
            case '3':
                $possible = "abcdefghijklmnopqrstuvwxyz";
                break;

            // all letters, lowercase + uppercase
            case '4':
                $possible = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
                break;

            // all digits & letters lowercase
            case '5':
                $possible = "0123456789abcdefghijklmnopqrstuvwxyz";
                break;

            // all digits & letters lowercase + uppercase
            case '6':
                $possible = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
                break;

        }

        $str = substr( str_shuffle( $possible ), 0, $length );

        return apply_filter( 'rnd_string', $str, $length, $type, $charlist );
    }

    /**
     * Return salted string
     *
     */
    public function salt( $string ) {
        $salt = defined('YOURLS_COOKIEKEY') ? YOURLS_COOKIEKEY : md5(__FILE__) ;

        return apply_filter( 'salt', md5 ($string . $salt), $string );
    }

    /**
     * Add a query var to a URL and return URL. Completely stolen from WP.
     *
     * Works with one of these parameter patterns:
     *     array( 'var' => 'value' )
     *     array( 'var' => 'value' ), $url
     *     'var', 'value'
     *     'var', 'value', $url
     * If $url omitted, uses $_SERVER['REQUEST_URI']
     *
     */
    public function add_query_arg() {
        $ret = '';
        if ( is_array( func_get_arg(0) ) ) {
            if ( @func_num_args() < 2 || false === @func_get_arg( 1 ) )
                $uri = $_SERVER['REQUEST_URI'];
            else
                $uri = @func_get_arg( 1 );
        } else {
            if ( @func_num_args() < 3 || false === @func_get_arg( 2 ) )
                $uri = $_SERVER['REQUEST_URI'];
            else
                $uri = @func_get_arg( 2 );
        }

        $uri = str_replace( '&amp;', '&', $uri );


        if ( $frag = strstr( $uri, '#' ) )
            $uri = substr( $uri, 0, -strlen( $frag ) );
        else
            $frag = '';

        if ( preg_match( '|^https?://|i', $uri, $matches ) ) {
            $protocol = $matches[0];
            $uri = substr( $uri, strlen( $protocol ) );
        } else {
            $protocol = '';
        }

        if ( strpos( $uri, '?' ) !== false ) {
            $parts = explode( '?', $uri, 2 );
            if ( 1 == count( $parts ) ) {
                $base = '?';
                $query = $parts[0];
            } else {
                $base = $parts[0] . '?';
                $query = $parts[1];
            }
        } elseif ( !empty( $protocol ) || strpos( $uri, '=' ) === false ) {
            $base = $uri . '?';
            $query = '';
        } else {
            $base = '';
            $query = $uri;
        }

        parse_str( $query, $qs );
        $qs = urlencode_deep( $qs ); // this re-URL-encodes things that were already in the query string
        if ( is_array( func_get_arg( 0 ) ) ) {
            $kayvees = func_get_arg( 0 );
            $qs = array_merge( $qs, $kayvees );
        } else {
            $qs[func_get_arg( 0 )] = func_get_arg( 1 );
        }

        foreach ( (array) $qs as $k => $v ) {
            if ( $v === false )
                unset( $qs[$k] );
        }

        $ret = http_build_query( $qs );
        $ret = trim( $ret, '?' );
        $ret = preg_replace( '#=(&|$)#', '$1', $ret );
        $ret = $protocol . $base . $ret . $frag;
        $ret = rtrim( $ret, '?' );

        return $ret;
    }

    /**
     * Navigates through an array and encodes the values to be used in a URL. Stolen from WP, used in add_query_arg()
     *
     */
    public function urlencode_deep( $value ) {
        $value = is_array( $value ) ? array_map( 'urlencode_deep', $value ) : urlencode( $value );

        return $value;
    }

    /**
     * Remove arg from query. Opposite of add_query_arg. Stolen from WP.
     *
     */
    public function remove_query_arg( $key, $query = false ) {
        if ( is_array( $key ) ) { // removing multiple keys
            foreach ( $key as $k )
                $query = add_query_arg( $k, false, $query );

            return $query;
        }

        return add_query_arg( $key, false, $query );
    }

    /**
     * Return a time-dependent string for nonce creation
     *
     */
    public function tick() {
        return ceil( time() / YOURLS_NONCE_LIFE );
    }

    /**
     * Create a time limited, action limited and user limited token
     *
     */
    public function create_nonce( $action, $user = false ) {
        if( false == $user )
            $user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
        $tick = tick();

        return substr( salt($tick . $action . $user), 0, 10 );
    }

    /**
     * Create a nonce field for inclusion into a form
     *
     */
    public function nonce_field( $action, $name = 'nonce', $user = false, $echo = true ) {
        $field = '<input type="hidden" id="'.$name.'" name="'.$name.'" value="'.create_nonce( $action, $user ).'" />';
        if( $echo )
            echo $field;

        return $field;
    }

    /**
     * Add a nonce to a URL. If URL omitted, adds nonce to current URL
     *
     */
    public function nonce_url( $action, $url = false, $name = 'nonce', $user = false ) {
        $nonce = create_nonce( $action, $user );

        return add_query_arg( $name, $nonce, $url );
    }

    /**
     * Check validity of a nonce (ie time span, user and action match).
     *
     * Returns true if valid, dies otherwise (die() or die($return) if defined)
     * if $nonce is false or unspecified, it will use $_REQUEST['nonce']
     *
     */
    public function verify_nonce( $action, $nonce = false, $user = false, $return = '' ) {
        // get user
        if( false == $user )
            $user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';

        // get current nonce value
        if( false == $nonce && isset( $_REQUEST['nonce'] ) )
            $nonce = $_REQUEST['nonce'];

        // what nonce should be
        $valid = create_nonce( $action, $user );

        if( $nonce == $valid ) {
            return true;
        } else {
            if( $return )
                die( $return );
            die( _( 'Unauthorized action or expired link' )/*, _( 'Error' ), 403 */);
        }
    }

    /**
     * Converts keyword into short link (prepend with YOURLS base URL)
     *
     */
    public function link( $keyword = '' ) {
        $link = SITE . '/' . sanitize_keyword( $keyword );

        return apply_filter( 'link', $link, $keyword );
    }

    /**
     * Converts keyword into stat link (prepend with YOURLS base URL, append +)
     *
     */
    public function statlink( $keyword = '' ) {
        $link = SITE . '/' . sanitize_keyword( $keyword ) . '+';
        if( is_ssl() )
            $link = set_url_scheme( $link, 'https' );

        return apply_filter( 'statlink', $link, $keyword );
    }

    /**
     * Check if we're in API mode. Returns bool
     *
     */
    public function is_API() {
        if ( defined( 'API' ) && API == true )
            return true;
        return false;
    }

    /**
     * Check if we're in Ajax mode. Returns bool
     *
     */
    public function is_Ajax() {
        if ( defined( 'AJAX' ) && AJAX == true )
            return true;
        return false;
    }

    /**
     * Check if we're in GO mode (yourls-go.php). Returns bool
     *
     */
    public function is_GO() {
        if ( defined( 'GO' ) && GO == true )
            return true;
        return false;
    }

    /**
     * Check if we're displaying stats infos (yourls-infos.php). Returns bool
     *
     */
    public function is_infos() {
        if ( defined( 'INFOS' ) && INFOS == true )
            return true;
        return false;
    }

    /**
     * Check if we'll need interface display function (ie not API or redirection)
     *
     */
    public function has_interface() {
        if( is_API() or is_GO() )

            return false;
        return true;
    }

    /**
     * Check if we're in the admin area. Returns bool
     *
     */
    public function is_admin() {
        if ( defined( 'YOURLS_ADMIN' ) && YOURLS_ADMIN == true )
            return true;
        return false;
    }

    /**
     * Check if current session is valid and secure as configurated
     *
     */
    public function is_public_or_logged() {
        if ( !is_private() )
            return true;
        else
            return defined( 'YOURLS_USER' );
    }

    /**
     * Check if the server seems to be running on Windows. Not exactly sure how reliable this is.
     *
     */
    public function is_windows() {
        return defined( 'DIRECTORY_SEPARATOR' ) && DIRECTORY_SEPARATOR == '\\';
    }

    /**
     * Check if SSL is required. Returns bool.
     *
     */
    public function needs_ssl() {
        if ( defined('YOURLS_ADMIN_SSL') && YOURLS_ADMIN_SSL == true )
            return true;
        return false;
    }

    /**
     * Return admin link, with SSL preference if applicable.
     *
     */
    public function admin_url( $page = '' ) {
        $admin = SITE . '/' . YOURLS_ADMIN_LOCATION . '/' . $page;
        if( is_ssl() or needs_ssl() )
            $admin = set_url_scheme( $admin, 'https' );

        return apply_filter( 'admin_url', $admin, $page );
    }

    /**
     * Return SITE or URL under YOURLS setup, with SSL preference
     *
     */
    public function site_url( $echo = true, $url = '' ) {
        $url = get_relative_url( $url );
        $url = trim( SITE . '/' . $url, '/' );

        // Do not enforce (checking need_ssl() ) but check current usage so it won't force SSL on non-admin pages
        if( is_ssl() )
            $url = set_url_scheme( $url, 'https' );
        $url = apply_filter( 'site_url', $url );
        if( $echo )
            echo $url;

        return $url;
    }

    /**
     * Check if SSL is used, returns bool. Stolen from WP.
     *
     */
    public function is_ssl() {
        $is_ssl = false;
        if ( isset( $_SERVER['HTTPS'] ) ) {
            if ( 'on' == strtolower( $_SERVER['HTTPS'] ) )
                $is_ssl = true;
            if ( '1' == $_SERVER['HTTPS'] )
                $is_ssl = true;
        } elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
            $is_ssl = true;
        }

        return apply_filter( 'is_ssl', $is_ssl );
    }

    /**
     * Get a remote page title
     *
     * This function returns a string: either the page title as defined in HTML, or the URL if not found
     * The function tries to convert funky characters found in titles to UTF8, from the detected charset.
     * Charset in use is guessed from HTML meta tag, or if not found, from server's 'content-type' response.
     *
     * @param string $url URL
     * @return string Title (sanitized) or the URL if no title found
     */
    public function get_remote_title( $url ) {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_get_remote_title', false, $url );
        if ( false !== $pre )
            return $pre;

        require_once YOURLS_INC . '/functions-http.php';

        $url = sanitize_url( $url );

        // Only deal with http(s)://
        if( !in_array( get_protocol( $url ), array( 'http://', 'https://' ) ) )

            return $url;

        $title = $charset = false;

        $response = http_get( $url ); // can be a Request object or an error string
        if( is_string( $response ) ) {
            return $url;
        }

        // Page content. No content? Return the URL
        $content = $response->body;
        if( !$content )

            return $url;

        // look for <title>. No title found? Return the URL
        if ( preg_match('/<title>(.*?)<\/title>/is', $content, $found ) ) {
            $title = $found[1];
            unset( $found );
        }
        if( !$title )

            return $url;

        // Now we have a title. We'll try to get proper utf8 from it.

        // Get charset as (and if) defined by the HTML meta tag. We should match
        // <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        // or <meta charset='utf-8'> and all possible variations: see https://gist.github.com/ozh/7951236
        if ( preg_match( '/<meta[^>]*charset\s*=["\' ]*([a-zA-Z0-9\-_]+)/is', $content, $found ) ) {
            $charset = $found[1];
            unset( $found );
        } else {
            // No charset found in HTML. Get charset as (and if) defined by the server response
            $_charset = current( $response->headers->getValues( 'content-type' ) );
            if( preg_match( '/charset=(\S+)/', $_charset, $found ) ) {
                $charset = trim( $found[1], ';' );
                unset( $found );
            }
        }

        // Conversion to utf-8 if what we have is not utf8 already
        if( strtolower( $charset ) != 'utf-8' && function_exists( 'mb_convert_encoding' ) ) {
            // We use @ to remove warnings because mb_ functions are easily bitching about illegal chars
            if( $charset ) {
                $title = @mb_convert_encoding( $title, 'UTF-8', $charset );
            } else {
                $title = @mb_convert_encoding( $title, 'UTF-8' );
            }
        }

        // Remove HTML entities
        $title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );

        // Strip out evil things
        $title = sanitize_title( $title );

        return apply_filter( 'get_remote_title', $title, $url );
    }

    /**
     * Quick UA check for mobile devices. Return boolean.
     *
     */
    public function is_mobile_device() {
        // Strings searched
        $mobiles = array(
            'android', 'blackberry', 'blazer',
            'compal', 'elaine', 'fennec', 'hiptop',
            'iemobile', 'iphone', 'ipod', 'ipad',
            'iris', 'kindle', 'opera mobi', 'opera mini',
            'palm', 'phone', 'pocket', 'psp', 'symbian',
            'treo', 'wap', 'windows ce', 'windows phone'
        );

        // Current user-agent
        $current = strtolower( $_SERVER['HTTP_YOURLS_USER_AGENT'] );

        // Check and return
        $is_mobile = ( str_replace( $mobiles, '', $current ) != $current );

        return apply_filter( 'is_mobile_device', $is_mobile );
    }

    /**
     * Get request in YOURLS base (eg in 'http://site.com/yourls/abcd' get 'abdc')
     *
     */
    public function get_request() {
        // Allow plugins to short-circuit the whole function
        $pre = apply_filter( 'shunt_get_request', false );
        if ( false !== $pre )
            return $pre;

        static $request = null;

        do_action( 'pre_get_request', $request );

        if( $request !== null )

            return $request;

        // Ignore protocol & www. prefix
        $root = str_replace( array( 'https://', 'http://', 'https://www.', 'http://www.' ), '', SITE );
        // Case insensitive comparison of the YOURLS root to match both http://Sho.rt/blah and http://sho.rt/blah
        $request = preg_replace( "!$root/!i", '', $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], 1 );

        // Unless request looks like a full URL (ie request is a simple keyword) strip query string
        if( !preg_match( "@^[a-zA-Z]+://.+@", $request ) ) {
            $request = current( explode( '?', $request ) );
        }

        return apply_filter( 'get_request', $request );
    }

    /**
     * Change protocol to match current scheme used (http or https)
     *
     */
    public function match_current_protocol( $url, $normal = 'http://', $ssl = 'https://' ) {
        if( is_ssl() )
            $url = str_replace( $normal, $ssl, $url );

        return apply_filter( 'match_current_protocol', $url );
    }

    /**
     * Fix $_SERVER['REQUEST_URI'] variable for various setups. Stolen from WP.
     *
     */
    public function fix_request_uri() {

        $default_server_values = array(
            'SERVER_SOFTWARE' => '',
            'REQUEST_URI' => '',
        );
        $_SERVER = array_merge( $default_server_values, $_SERVER );

        // Fix for IIS when running with PHP ISAPI
        if ( empty( $_SERVER['REQUEST_URI'] ) || ( php_sapi_name() != 'cgi-fcgi' && preg_match( '/^Microsoft-IIS\//', $_SERVER['SERVER_SOFTWARE'] ) ) ) {

            // IIS Mod-Rewrite
            if ( isset( $_SERVER['HTTP_X_ORIGINAL_URL'] ) ) {
                $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_ORIGINAL_URL'];
            }
            // IIS Isapi_Rewrite
            else if ( isset( $_SERVER['HTTP_X_REWRITE_URL'] ) ) {
                $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
            } else {
                // Use ORIG_PATH_INFO if there is no PATH_INFO
                if ( !isset( $_SERVER['PATH_INFO'] ) && isset( $_SERVER['ORIG_PATH_INFO'] ) )
                    $_SERVER['PATH_INFO'] = $_SERVER['ORIG_PATH_INFO'];

                // Some IIS + PHP configurations puts the script-name in the path-info (No need to append it twice)
                if ( isset( $_SERVER['PATH_INFO'] ) ) {
                    if ( $_SERVER['PATH_INFO'] == $_SERVER['SCRIPT_NAME'] )
                        $_SERVER['REQUEST_URI'] = $_SERVER['PATH_INFO'];
                    else
                        $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] . $_SERVER['PATH_INFO'];
                }

                // Append the query string if it exists and isn't null
                if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
                    $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
                }
            }
        }
    }

    /**
     * Shutdown function, runs just before PHP shuts down execution. Stolen from WP
     *
     */
    public function shutdown() {
        do_action( 'shutdown' );
    }

    /**
     * Auto detect custom favicon in /user directory, fallback to YOURLS favicon, and echo/return its URL
     *
     */
    public function favicon( $echo = true ) {
        static $favicon = null;
        if( $favicon !== null )

            return $favicon;

        // search for favicon.(ico|png|gif)
        foreach( array( 'png', 'ico', 'gif' ) as $ext ) {
            if( file_exists( YOURLS_USERDIR. '/favicon.' . $ext ) ) {
                $favicon = site_url( false, YOURLS_USERURL . '/favicon.' . $ext );
                break;
            }
        }
        if ( $favicon === null )
            $favicon = site_url( false, YOURLS_ASSETURL . '/img/favicon.ico' );

        if( $echo )
            echo '<link rel="shortcut icon" href="'. $favicon . '">';
        else
            return $favicon;
    }

    /**
     * Check for maintenance mode. If yes, die. See maintenance_mode(). Stolen from WP.
     *
     */
    public function check_maintenance_mode() {

        $file = YOURLS_ABSPATH . '/.maintenance' ;
        if ( !file_exists( $file ) || is_upgrading() || is_installing() )
            return;

        global $maintenance_start;

        include_once( $file );
        // If the $maintenance_start timestamp is older than 10 minutes, don't die.
        if ( ( time() - $maintenance_start ) >= 600 )
            return;

        // Use any /user/maintenance.php file
        if( file_exists( YOURLS_USERDIR.'/maintenance.php' ) ) {
            include_once( YOURLS_USERDIR.'/maintenance.php' );
            die();
        }

        // https://www.youtube.com/watch?v=Xw-m4jEY-Ns
        $title   = _( 'Service temporarily unavailable' );
        $message = _( 'Our service is currently undergoing scheduled maintenance.' ) . "</p><p>" .
        _( 'Things should not last very long, thank you for your patience and please excuse the inconvenience' );
        die( $message/*, $title , 503 */);

    }

    /**
     * Return current admin page, or null if not an admin page
     *
     * @return mixed string if admin page, null if not an admin page
     * @since 1.6
     */
    public function current_admin_page() {
        if( is_admin() ) {
            $current = substr( get_request(), 6 );
            if( $current === false )
                $current = 'index'; // if current page is http://sho.rt/admin/ instead of http://sho.rt/admin/index

            return $current;
        }

        return null;
    }

    /**
     * Check if a URL protocol is allowed
     *
     * Checks a URL against a list of whitelisted protocols. Protocols must be defined with
     * their complete scheme name, ie 'stuff:' or 'stuff://' (for instance, 'mailto:' is a valid
     * protocol, 'mailto://' isn't, and 'http:' with no double slashed isn't either
     *
     * @since 1.6
     *
     * @param string $url URL to be check
     * @param array $protocols Optional. Array of protocols, defaults to global $allowedprotocols
     * @return boolean true if protocol allowed, false otherwise
     */
    public function is_allowed_protocol( $url, $protocols = array() ) {
        if( ! $protocols ) {
            global $allowedprotocols;
            $protocols = $allowedprotocols;
        }

        $protocol = get_protocol( $url );

        return apply_filter( 'is_allowed_protocol', in_array( $protocol, $protocols ), $url, $protocols );
    }

    /**
     * Get protocol from a URL (eg mailto:, http:// ...)
     *
     * @since 1.6
     *
     * @param string $url URL to be check
     * @return string Protocol, with slash slash if applicable. Empty string if no protocol
     */
    public function get_protocol( $url ) {
        preg_match( '!^[a-zA-Z0-9\+\.-]+:(//)?!', $url, $matches );
        /*
        http://en.wikipedia.org/wiki/URI_scheme#Generic_syntax
        The scheme name consists of a sequence of characters beginning with a letter and followed by any
        combination of letters, digits, plus ("+"), period ("."), or hyphen ("-"). Although schemes are
        case-insensitive, the canonical form is lowercase and documents that specify schemes must do so
        with lowercase letters. It is followed by a colon (":").
         */
        $protocol = ( isset( $matches[0] ) ? $matches[0] : '' );

        return apply_filter( 'get_protocol', $protocol, $url );
    }

    /**
     * Get relative URL (eg 'abc' from 'http://sho.rt/abc')
     *
     * Treat indifferently http & https. If a URL isn't relative to the YOURLS install, return it as is
     * or return empty string if $strict is true
     *
     * @since 1.6
     * @param string $url URL to relativize
     * @param bool $strict if true and if URL isn't relative to YOURLS install, return empty string
     * @return string URL
     */
    public function get_relative_url( $url, $strict = true ) {
        $url = sanitize_url( $url );

        // Remove protocols to make it easier
        $noproto_url  = str_replace( 'https:', 'http:', $url );
        $noproto_site = str_replace( 'https:', 'http:', SITE );

        // Trim URL from YOURLS root URL : if no modification made, URL wasn't relative
        $_url = str_replace( $noproto_site . '/', '', $noproto_url );
        if( $_url == $noproto_url )
            $_url = ( $strict ? '' : $url );

        return apply_filter( 'get_relative_url', $_url, $url );
    }

    /**
     * Marks a function as deprecated and informs when it has been used. Stolen from WP.
     *
     * There is a hook deprecated_function that will be called that can be used
     * to get the backtrace up to what file and function called the deprecated
     * function.
     *
     * The current behavior is to trigger a user error if YOURLS_DEBUG is true.
     *
     * This function is to be used in every function that is deprecated.
     *
     * @since 1.6
     * @uses do_action() Calls 'deprecated_function' and passes the function name, what to use instead,
     *   and the version the function was deprecated in.
     * @uses apply_filters() Calls 'deprecated_function_trigger_error' and expects boolean value of true to do
     *   trigger or false to not trigger error.
     *
     * @param string $function The function that was called
     * @param string $version The version of WordPress that deprecated the function
     * @param string $replacement Optional. The function that should have been called
     */
    public function deprecated_function( $function, $version, $replacement = null ) {

        do_action( 'deprecated_function', $function, $replacement, $version );

        // Allow plugin to filter the output error trigger
        if ( YOURLS_DEBUG && apply_filters( 'deprecated_function_trigger_error', true ) ) {
            if ( ! is_null( $replacement ) )
                trigger_error( sprintf( _( '%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.' ), $function, $version, $replacement ) );
            else
                trigger_error( sprintf( _( '%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.' ), $function, $version ) );
        }
    }

    /**
     * Return the value if not an empty string
     *
     * Used with array_filter(), to remove empty keys but not keys with value 0 or false
     *
     * @since 1.6
     * @param mixed $val Value to test against ''
     * @return bool True if not an empty string
     */
    public function return_if_not_empty_string( $val ) {
        return( $val !== '' );
    }

    /**
     * Add a message to the debug log
     *
     * When in debug mode ( YOURLS_DEBUG == true ) the debug log is echoed in html_footer()
     * Log messages are appended to $ydb->debug_log array, which is instanciated within class ezSQLcore_YOURLS
     *
     * @since 1.7
     * @param string $msg Message to add to the debug log
     * @return string The message itself
     */
    public function debug_log( $msg ) {
        global $ydb;
        $ydb->debug_log[] = $msg;

        return $msg;
    }

    /**
     * Explode a URL in an array of ( 'protocol' , 'slashes if any', 'rest of the URL' )
     *
     * Some hosts trip up when a query string contains 'http://' - see http://git.io/j1FlJg
     * The idea is that instead of passing the whole URL to a bookmarklet, eg index.php?u=http://blah.com,
     * we pass it by pieces to fool the server, eg index.php?proto=http:&slashes=//&rest=blah.com
     *
     * Known limitation: this won't work if the rest of the URL itself contains 'http://', for example
     * if rest = blah.com/file.php?url=http://foo.com
     *
     * Sample returns:
     *
     *   with 'mailto:jsmith@example.com?subject=hey' :
     *   array( 'protocol' => 'mailto:', 'slashes' => '', 'rest' => 'jsmith@example.com?subject=hey' )
     *
     *   with 'http://example.com/blah.html' :
     *   array( 'protocol' => 'http:', 'slashes' => '//', 'rest' => 'example.com/blah.html' )
     *
     * @since 1.7
     * @param string $url URL to be parsed
     * @param array $array Optional, array of key names to be used in returned array
     * @return mixed false if no protocol found, array of ('protocol' , 'slashes', 'rest') otherwise
     */
    public function get_protocol_slashes_and_rest( $url, $array = array( 'protocol', 'slashes', 'rest' ) ) {
        $proto = get_protocol( $url );

        if( !$proto or count( $array ) != 3 )

            return false;

        list( $null, $rest ) = explode( $proto, $url, 2 );

        list( $proto, $slashes ) = explode( ':', $proto );

        return array( $array[0] => $proto . ':', $array[1] => $slashes, $array[2] => $rest );
    }

    /**
     * Set URL scheme (to HTTP or HTTPS)
     *
     * @since 1.7.1
     * @param string $url URL
     * @param string $scheme scheme, either 'http' or 'https'
     * @return string URL with chosen scheme
     */
    public function set_url_scheme( $url, $scheme = false ) {
        if( $scheme != 'http' && $scheme != 'https' ) {
            return $url;
        }

        return preg_replace( '!^[a-zA-Z0-9\+\.-]+://!', $scheme . '://', $url );
    }

}
