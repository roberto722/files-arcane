<?php
global $ArcaneWpTeamWars;

define('ARCANE_TEAMWARS_CATEGORY', '_wp_teamwars_category');
define('ARCANE_TEAMWARS_DEFAULTCSS', '_wp_teamwars_defaultcss');
define('ARCANE_TEAMWARS_ACL', '_wp_teamwars_acl');
require_once(ABSPATH . 'wp-admin/includes/screen.php');

require ( get_theme_file_path('addons/team-wars/wp-teamwars-widget.php'));
require ( get_theme_file_path('addons/team-wars/wp-upcoming-matches-widget.php'));
require ( get_theme_file_path('addons/team-wars/wp-other-matches-widget.php'));

class Arcane_TeamWars {

    var $tables = array(
        'games' => 'cw_games',
        'maps' => 'cw_maps',
    );

    var $match_status = array();
    var $acl_keys = array();
    var $page_hooks = array();
    var $page_notices = array();

    const ErrorOK = 0;
    const ErrorDatabase = -199;
    const ErrorUploadMaxFileSize = -208;
    const ErrorUploadHTMLMaxFileSize = -209;
    const ErrorUploadPartially = -210;
    const ErrorUploadNoFile = -211;
    const ErrorUploadMissingTemp = -212;
    const ErrorUploadDiskWrite = -213;
    const ErrorUploadStoppedByExt = -214;
    const ErrorUploadFileTypeNotAllowed = -215;

    function __construct() {
        $new_tables = array();
        foreach($this->tables as $key => $tbl){
            global $wpdb;
            $new_tables[$key] = $wpdb->prefix.$tbl;

        }

        $this->tables = $new_tables;

        add_action('widgets_init', array($this, 'on_widgets_init'));
    }


    /**
     * WP init hook
     *
     * Plugin initialization method used to load textdomain,
     * register hooks, scripts and styles.
     *
     *
     */

    function on_init()
    {


        add_option(ARCANE_TEAMWARS_CATEGORY, -1);
        add_option(ARCANE_TEAMWARS_DEFAULTCSS, true);
        add_option(ARCANE_TEAMWARS_ACL, array());

        // update database


        $this->acl_keys = array(
            'manage_matches' => esc_html__('Manage matches', 'arcane'),
            'manage_games' => esc_html__('Manage games', 'arcane'),
            'manage_teams' => esc_html__('Manage teams', 'arcane')
        );

        $this->match_status = array(
            esc_html__('Friendly', 'arcane'),
            esc_html__('Official', 'arcane')
        );




        add_action('admin_post_wp-teamwars-deleteteams', array($this, 'on_admin_post_deleteteams'));
        add_action('admin_post_wp-teamwars-sethometeam', array($this, 'on_admin_post_sethometeam'));
        add_action('admin_post_wp-teamwars-gamesop', array($this, 'on_admin_post_gamesop'));
        add_action('admin_post_wp-teamwars-deletemaps', array($this, 'on_admin_post_deletemaps'));
        add_action('admin_post_wp-teamwars-deletematches', array($this, 'on_admin_post_deletematches'));

        add_action('admin_post_wp-teamwars-settings', array($this, 'on_admin_post_settings'));
        add_action('admin_post_wp-teamwars-acl', array($this, 'on_admin_post_acl'));
        add_action('admin_post_wp-teamwars-deleteacl', array($this, 'on_admin_post_deleteacl'));
        add_action('admin_post_wp-teamwars-import', array($this, 'on_admin_post_import'));

        add_action('wp_ajax_get_maps', array($this, 'on_ajax_get_maps'));

    }

    /**
     * WP admin_menu hook
     *
     * Page, Assets registration, load-* action hooks
     *
     *
     */


    function on_tournaments () {
        header('Location: '.get_admin_url().'edit.php?post_type=tournament');
    }

    function on_teams_redirect() {
      header('Location: '.get_admin_url().'edit.php?post_type=team');
    }
    function acl_user_can($action, $value = false, $user_id = false)
    {
        global $user_ID;

        $acl = $this->acl_get();
        $is_super = false;
        $caps = array(
            'games' => array(),
            'permissions' => array_fill_keys(array_keys($this->acl_keys), false)
        );

        if(empty($user_id))
            $user_id = $user_ID;

        if(!empty($acl) && isset($acl[$user_id]))
            $caps = $acl[$user_id];

        $user = new WP_User($user_id);
        if(!empty($user))
            $is_super = $user->has_cap('manage_options');

        if($is_super) {
            $caps['games'] = array('all');
            $caps['permissions'] = array_fill_keys(array_keys($caps['permissions']), true);
        }

        switch($action)
        {
            case 'which_games':

                $where = array_search(0, $caps['games']);

                if($where === false)
                    return $caps['games'];

                return 'all';

            break;

            case 'manage_game':

                if($value == 'all')
                    $value = 0;

                $ret = array_search($value, $caps['games']) !== false;

                if(!$ret) {
                    $ret = array_search(0, $caps['games']) !== false;
                }

                return $ret;

            break;
        }

        return isset($caps['permissions'][$action]) && $caps['permissions'][$action];
    }

    function acl_get() {
        $acl = get_option(ARCANE_TEAMWARS_ACL);

        if(!is_array($acl))
            $acl = array();

        return $acl;
    }

    function acl_update($user_id, $data) {

        $acl = $this->acl_get();

        $acl[$user_id] =  array(
            'games' => array(0),
            'permissions' => array('manage_matches')
        );

        $default_perms = array(
            'manage_matches' => false,
            'manage_teams' => false,
            'manage_games' => false
        );

        $acl[$user_id]['games'] = isset($data['games']) ? array_unique(array_values($data['games'])) : array(0);
        $acl[$user_id]['permissions'] = isset($data['permissions']) ? $this->extract_args($data['permissions'], $default_perms) : $default_perms;

        update_option(ARCANE_TEAMWARS_ACL, $acl);

        return true;
    }

    function acl_delete($user_id) {

        $acl = $this->acl_get();

        if(isset($acl[$user_id])) {
            unset($acl[$user_id]);
            update_option(ARCANE_TEAMWARS_ACL, $acl);

            return true;
        }

        return false;
    }


    function on_widgets_init()
    {
        register_widget('Arcane_TeamWars_Widget');
        register_widget('Arcane_Upcoming_Matches_Widget');
        register_widget('Arcane_Other_Matches_Widget');
        return;

    }


    function html_notice_helper($message, $type = 'updated', $echo = true) {
        $arcane_allowed = wp_kses_allowed_html( 'post' );
        $text = '<div class="' . $type . ' fade"><p>' . $message . '</p></div>';

        if($echo) echo wp_kses($text,$arcane_allowed);

        return $text;
    }

    function print_table_header($columns, $id = true)
    {

       $arcane_allowed = wp_kses_allowed_html( 'entities ' );
        foreach ( $columns as $column_key => $column_display_name ) {
                $class = ' class="manage-column';

                $class .= " column-$column_key";

                if ( 'cb' == $column_key )
                    $class .= ' check-column';
                elseif ( in_array($column_key, array('posts', 'comments', 'links')) )
                    $class .= ' num';

                $class .= '"';
        ?>
            <th scope="col" <?php echo esc_attr($id) ? "id=\"$column_key\"" : ""; echo wp_kses($class,$arcane_allowed); ?>><?php echo wp_kses ($column_display_name,
                array(

                   'input'  => array(
                      'type'  => array()
                      ),
                   )
        ); ?></th>
        <?php }
    }

    function add_notice($message, $type = 'updated') {

        if(empty($type)) $type = 'updated';

        if(!isset($this->page_notices[$type])) {
            $this->page_notices[$type] = array();
        }

        $this->page_notices[$type][] = $message;
    }

    function print_notices() {
        foreach($this->page_notices as $type => $e) {
            foreach($e as $msg) {
                $this->html_notice_helper($msg, $type, true);
            }
        }
    }

    /**
     * Image uploading handling, used internally by plugin
     *
     * @param string $name $_FILES array key for a file which should be uploaded
     *
     */

    function handle_upload($name)
    {
        $mimes = apply_filters('upload_mimes',
                array('jpg|jpeg|jpe' => 'image/jpeg',
                      'gif' => 'image/gif',
                      'png' => 'image/png'));

        $upload = isset($_FILES[$name]) ? $_FILES[$name] : false;
        $upload_errors = array(self::ErrorOK,
                               self::ErrorUploadMaxFileSize,
                               self::ErrorUploadHTMLMaxFileSize,
                               self::ErrorUploadPartially,
                               self::ErrorUploadNoFile,
                               self::ErrorOK,
                               self::ErrorUploadMissingTemp,
                               self::ErrorUploadDiskWrite,
                               self::ErrorUploadStoppedByExt);

	    if( empty($upload) ) {
		    return new WP_Error( self::ErrorUploadNoFile, $upload_errors[self::ErrorUploadNoFile] );
	    }


	    if($upload['error'] > 0) {
		    $code = $upload['error'];

		    if(isset($upload_errors[$code])) {
			    return new WP_Error( $code, $upload_errors[$code] );
		    }

		    return new WP_Error( $code, sprintf(esc_html__( 'Unknown upload error: %d', 'arcane' ), $code ) );
	    }

        extract(wp_check_filetype($upload['name'], $mimes));

        if(!$type || !$ext)
        {
            return self::ErrorUploadFileTypeNotAllowed;
        }
        else
        {
            $file_data = wp_handle_upload($upload, array('test_type' => false,'test_form' => false));

            if(!empty($file_data) && is_array($file_data))
            {
                $file_data['type'] = $type;
                if(!isset($file_data['error']))
                {
                    $fileinfo = pathinfo($file_data['file']);
                    $attach_title = basename($fileinfo['basename'], '.' . $fileinfo['extension']);
                    $attach_id = wp_insert_attachment(array('guid' => $file_data['url'],
                                                            'post_title' => $attach_title,
                                                            'post_content' => '',
                                                            'post_status' => 'publish',
                                                            'post_mime_type' => $file_data['type']),
                                                        $file_data['file']);

                    $metadata = wp_generate_attachment_metadata($attach_id, $file_data['file']);

                    if(!empty($metadata))
                        wp_update_attachment_metadata($attach_id, $metadata);

                    if(!empty($attach_id) && is_int($attach_id))
                        return $attach_id;
                } else {
                    return $upload_errors[$file_data['error']];
                }
            }
        }


        return self::ErrorOK;
    }

    /**
     * Parse arguments and return a list of values with keys from defaults
     *
     * @param array|string $args Input values
     * @param array $defaults Array of default values
     * @return array Merged array. Same behaviour as wp_parse_args except it generates array which only consists of keys from $defaults array
     */

    function extract_args($args, $defaults) {
        $rslt = array();

        $options = wp_parse_args($args, $defaults);

        if(is_array($defaults))
            foreach(array_keys($defaults) as $key)
                $rslt[$key] = $options[$key];

        return $rslt;
    }




    function get_team($p)
    {
        //UPDATED TO POSTS
	    $id = false;
        $title = false;
        $limit = 0;
        $offset = 0;
        $orderby = 'id';
        $order = 'ASC';

        extract($this->extract_args($p, array(
                    'id' => false,
                    'title' => false,
                    'limit' => 0,
                    'offset' => 0,
                    'orderby' => 'id',
                    'order' => 'ASC')));

        $order = strtoupper($order);
        if($order != 'ASC' && $order != 'DESC')
            $order = 'ASC';

        if(($id != 'all' && $id !== false) OR ((is_array($id)) AND (!empty($id)))) {
          if (is_array($id)) {
            $returns = array();
            $counter = 0;
            foreach ($id as $single) {
              $returns[$counter] = (object) array_merge((array) get_post($single), (array) arcane_get_meta($single));
              $returns[$counter]->title = $returns[$counter]->post_title;
              $returns[$counter]->id = $returns[$counter]->ID;
              $counter++;
            }
            return $returns;
          } else {
            $obj_merged = (object) array_merge((array) get_post($id), (array) arcane_get_meta($id));
            if(isset($obj_merged->post_title))
            $obj_merged->title = $obj_merged->post_title;
            if(isset($obj_merged->ID))
            $obj_merged->id = $obj_merged->ID;
            return $obj_merged;
          }
        } else {
          //id not set
          if ($limit == 0) {
            $limit = -1;
          }
          $args = array(
            'post_type' => 'team',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'post_status' => 'publish',
            'orderby' => $orderby,
            'order' => $order
          );

          if($title !== false) {
            $test = get_page_by_title($title, OBJECT, 'post');
            if ($test) {
              return $test;
            } else {
              return false;
            }
          }
          $posts = get_posts ($args);
          $returner = array();
          if (is_array($posts)) {
            foreach ($posts as $post) {
               $obj_merged = (object) array_merge((array) $post, (array) arcane_get_meta($post->ID));
               $obj_merged->title = $obj_merged->post_title;
               $obj_merged->id = $obj_merged->ID;
               $returner[] = $obj_merged;
            }
          }
          return $returner;
        }
    }

    function add_team($p)
    {
      //UPDATED TO POSTS
      $data = $this->extract_args($p, array(
                  'title' => '',
                  'logo' => 0,
                  'home_team' => 0,
                  'post_id' => ''));
      if ( FALSE === get_post_status( $data['post_id'] ) ) {
        //team doesn't exist create it
          $args = array(
              'post_type' => 'team',
              'post_title' => $data['title'],
              'post_status'=> 'publish',
              'post_content' => '',
              'post_name'   => sanitize_title($data['title'])
          );
          $pid = wp_insert_post($args);
          if ($pid) {
            update_post_meta($pid, 'team_photo', $data['logo']);
            update_post_meta($pid, 'home_team', $data['home_team']);
            return true;
          } else {
            return false;
          }
      } else {
        //team exists update it
        $args = array(
          'ID' => $data['post_id'],
          'post_title'   => $data['title'],
          'post_name'   => sanitize_title($data['title'])
        );
        wp_update_post( $args );
        update_post_meta($data['post_id'], 'team_photo', $data['logo']);
        update_post_meta($data['post_id'], 'home_team', $data['home_team']);
        return $data['post_id'];
      }
    }

    function set_hometeam($id) {
      //UPDATED TO POSTS
      return update_post_meta($id, 'home_team', 1);
    }

    function update_team($id, $p)
    {
      //UPDATED TO POSTS
      $data = wp_parse_args($p, array());
      if ( FALSE === get_post_status( $id ) ) {
        //team doesnt exist, create instead, dunno how tho
        $this->add_team($p);
      } else {
        //team exists

        //update title
        if (isset($data['title']) AND (strlen($data['title']) > 1)) {
          $args = array(
            'ID' => $id,
            'post_title'   => $data['title']
          );
          wp_update_post( $args );
        }
        //update home_team
        if (isset($data['home_team']) AND (strlen($data['home_team']) > 1)) {
          update_post_meta($id, 'home_team', $data['home_team']);
        }
        if (isset($data['logo']) AND (strlen($data['logo']) > 1)) {
          update_post_meta($id, 'team_photo', $data['logo']);
        }
      }

        return $id;
    }

    function delete_team($id, $skipdelete = false)
    {
      //UPDATED TO POSTS
        if(!is_array($id))
            $id = array($id);

        $id = array_map('intval', $id);

        // delete matches belongs to this team
        $this->delete_match_by_team($id);
        //parse ids, remove post
        if (is_array($id)) {
          foreach($id as $small_id) {
            if ($small_id > 0) {
              if ($skipdelete == false) {
                wp_delete_post($small_id);
              }
            }
          }
        } else {
          if (is_int($id)) {
            if ($id > 0) {
              if ($skipdelete == false) {
                wp_delete_post($id);
              }
            }
          }
        }
        return true;
    }

    function on_admin_post_deleteteams()
    {
      //UPDATED TO POSTS
        if(!$this->acl_user_can('manage_teams'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

        check_admin_referer('wp-teamwars-deleteteams');

        $referer = remove_query_arg(array('add', 'update'), $_REQUEST['_wp_http_referer']);

        if($_REQUEST['do_action'] == 'delete' || $_REQUEST['do_action2'] == 'delete') {
	        $delete = array();
            extract($this->extract_args($_REQUEST, array('delete' => array())));

            $error = $this->delete_team($delete);
            $referer = add_query_arg('delete', $error, $referer);
        }

        wp_redirect($referer, $status = 302);
    }

    function on_admin_post_sethometeam()
    {
      //UPDATED TO POSTS
        if(!$this->acl_user_can('manage_teams'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

        check_admin_referer('wp-teamwars-sethometeam');

        $referer = $_REQUEST['_wp_http_referer'];
	    $id = array();
        extract($this->extract_args($_REQUEST, array('id' => array())));

        $this->set_hometeam($id);

        wp_redirect($referer, $status = 302);
    }

    function escape_array($arr){
        global $wpdb;
        $escaped = array();
        foreach($arr as $k => $v){
            if(is_numeric($v))
                $escaped[] = $wpdb->prepare('%d', $v);
            else
                $escaped[] = $wpdb->prepare('%s', $v);
        }
        return implode(',', $escaped);
    }

    /*
     * Games Managment
     */

    function get_game($p, $count = false)
    {
        global $wpdb;

        $id = false;
        $limit = 0;
        $offset = 0;
        $orderby = 'id';
        $order = 'ASC';

        extract($this->extract_args($p, array(
            'id' => false,
            'limit' => 0,
            'offset' => 0,
            'orderby' => 'id',
            'order' => 'ASC')));

        $limit_query = '';
           $where_query = '';

        $order = strtolower($order);
        if($order != 'asc' && $order != 'desc')
            $order = 'asc';

        $order_query = $wpdb->prepare('ORDER BY %s %s', $orderby, $order );

        if($id != 'all' && $id !== false) {

            if(!is_array($id))
                $id = array($id);

            $id = array_map('intval', $id);
            //$id = implode(', ', $id);
            $where_query = array();
            $where_query[] = "id IN (" . $this->escape_array($id) . ")";
        }

        if($limit > 0) {
            $limit_query = $wpdb->prepare('LIMIT %d, %d', $offset, $limit);
        }


        if(!empty($where_query))
            $where_query = 'WHERE ' . implode(' AND ', $where_query);

        if($count) {

            $rslt = $wpdb->get_row('SELECT COUNT(id) AS m_count FROM `' . $this->tables['games'] . '` ' . $where_query);

            $ret = array('total_items' => 0, 'total_pages' => 1);

            $ret['total_items'] = $rslt->m_count;

            if($limit > 0)
                $ret['total_pages'] = ceil($ret['total_items'] / $limit);

            return $ret;
        }

        if(!isset($where_query))$where_query = '';

        if (class_exists( 'Arcane_Types' )){
            $rslt = $wpdb->get_results('SELECT * FROM `' . $this->tables['games'] . '` ' . implode(' ', array($where_query, $order_query, $limit_query)));
        }else{
            $rslt = '';
        }
        return $rslt;
    }

    function get_games($p, $count = false)
    {
        global $wpdb;

        $id = false;
        $limit = 0;
        $offset = 0;
        $orderby = 'id';
        $order = 'ASC';
        extract($this->extract_args($p, array(
            'id' => false,
            'limit' => 0,
            'offset' => 0,
            'orderby' => 'id',
            'order' => 'ASC')));

        $limit_query = '';

        $order = strtolower($order);
        if($order != 'asc' && $order != 'desc')
            $order = 'asc';

        $order_query = 'ORDER BY `' . $orderby . '` ' . $order;

        if($id != 'all' && $id !== false && !empty($id)) {
            $where_query = array();
            $where_query[] = 'id IN ('. $id . ')';
        }

        if($limit > 0) {
            $limit_query = $wpdb->prepare('LIMIT %d, %d', $offset, $limit);
        }


        if(!empty($where_query))
            $where_query = 'WHERE ' . implode(' AND ', $where_query);

        if($count) {

            $rslt = $wpdb->get_row('SELECT COUNT(id) AS m_count FROM `' . $this->tables['games'] . '` ' . $where_query);

            $ret = array('total_items' => 0, 'total_pages' => 1);

            $ret['total_items'] = $rslt->m_count;

            if($limit > 0)
                $ret['total_pages'] = ceil($ret['total_items'] / $limit);

            return $ret;
        }

        if(!isset($where_query))$where_query = '';

        if (class_exists( 'Arcane_Types' )){
            $rslt = $wpdb->get_results('SELECT * FROM `' . $this->tables['games'] . '` ' . implode(' ', array($where_query, $order_query, $limit_query)));
        }else{
            $rslt = '';
        }

        return $rslt;
    }

    function add_game($p)
    {
        global $wpdb;

        $data = $this->extract_args($p, array('title' => '', 'abbr' => '', 'icon' => 0, 'g_banner_file' => 0));

        if($wpdb->insert($this->tables['games'], $data, array('%s', '%s', '%d', '%d')))
        {
            $insert_id = $wpdb->insert_id;

            return $insert_id;
        }

        return false;
    }

    function update_game($id, $p)
    {
        global $wpdb;

        $fields = array('title' => '%s', 'abbr' => '%s', 'icon' => '%d',  'g_banner_file' => '%d');

        $data = wp_parse_args($p, array());

        $update_data = array();
        $update_mask = array();

        foreach($fields as $fld => $mask) {
            if(isset($data[$fld])) {
                $update_data[$fld] = $data[$fld];
                $update_mask[] = $mask;
            }
        }

        return $wpdb->update($this->tables['games'], $update_data, array('id' => $id), $update_mask, array('%d'));
    }

    function delete_game($id)
    {
        global $wpdb;

        if(!is_array($id))
            $id = array($id);

        $id = array_map('intval', $id);

        $this->delete_map_by_game($id);
        $this->delete_match_by_game($id);

        return $wpdb->query('DELETE FROM `' . $this->tables['games'] . '` WHERE id IN(' . implode(',', $id) . ')');
    }


    function on_admin_post_gamesop()
    {

        if(!$this->acl_user_can('manage_games'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

        check_admin_referer('wp-teamwars-gamesop');

        $referer = remove_query_arg(array('add', 'update', 'export'), $_REQUEST['_wp_http_referer']);

        $args = $this->extract_args($_REQUEST, array('do_action' => '', 'do_action2' => '', 'items' => array()));
        extract($args);

        $action = !empty($do_action) ? $do_action : (!empty($do_action2) ? $do_action2 : '');

        if(!empty($items)) {

            switch($action) {
                case 'delete':
                    $error = $this->delete_game($items);
                    $referer = add_query_arg('delete', $error, $referer);
                break;
                case 'export':

                    $data = $this->export_games($items);

                    header('Content-Type: application/x-gzip-compressed');
                    header('Content-Disposition: attachment; filename="wp-teamwars-gamepack-' . date('Y-m-d', current_time('timestamp', 1)) . '.gz"');

                    $json = json_encode($data);

                    $gzdata = gzcompress($json, 9);

                    header('Content-Length: ' . strlen($gzdata));

                    //echo  $gzdata;

                    die();

                break;
            }

        }

        wp_redirect($referer, $status = 302);
    }

    function export_games($id)
    {
        $data = array();
        $games = $this->get_game(array('id' => $id));

        foreach($games as $game) {
            $game_data = $this->extract_args($game, array(
                    'title' => '', 'abbr' => '',
                    'icon' => '', 'g_banner_file' => '', 'maplist' => array()
                ));

            $maplist = $this->get_map(array('game_id' => $game->id));

            if($game->icon != 0) {
                $attach = get_attached_file($game->icon);
                $mimetype = get_post_mime_type($game->icon);
                $pathinfo = pathinfo($attach);

                if(!empty($attach)){
                     $content = $this->_get_file_content ($attach);

                    if(!empty($content))
                        $game_data['icon'] = array(
                            'filename' => $pathinfo['basename'],
                            'mimetype' => $mimetype,
                            'data' => $content);
                }
            }

            if($game->g_banner_file != 0) {
                $attach = get_attached_file($game->g_banner_file);
                $mimetype = get_post_mime_type($game->g_banner_file);
                $pathinfo = pathinfo($attach);

                if(!empty($attach)){
                     $content = $this->_get_file_content ($attach);

                    if(!empty($content))

                       $game_data['g_banner_file'] = array(
                            'filename' => $pathinfo['basename'],
                            'mimetype' => $mimetype,
                            'data' => $content);
                }
            }

            foreach($maplist as $map) {
                $map_data = array('title' => $map->title, 'screenshot' => '');

                if($map->screenshot != 0) {
                    $attach = get_attached_file($map->screenshot);
                    $mimetype = get_post_mime_type($map->screenshot);
                    $pathinfo = pathinfo($attach);

                    if(!empty($attach)){
                        $content = $this->_get_file_content ($attach);

                        if(!empty($content))
                            $map_data['screenshot'] = array(
                                'filename' => $pathinfo['basename'],
                                'mimetype' => $mimetype,
                                'data' => $content);
                    }
                }

                $game_data['maplist'][] = $map_data;
            }

            $data[] = $game_data;
        }

        return $data;
    }

    function _import_image($p) {

        if(!empty($p)) {
            $upload = wp_upload_bits($p['filename'], null, $p['data']);

            if($upload['error'] === false) {
                $attach = array('guid' => $upload['url'],
                                'post_title' => sanitize_title($p['filename']),
                                'post_content' => '',
                                'post_status' => 'publish',
                                'post_mime_type' => $p['mimetype']);

                $attach_id = wp_insert_attachment($attach, $upload['file']);

                if(!empty($attach_id)) {
                    $metadata = wp_generate_attachment_metadata($attach_id, $upload['file']);

                    if(!empty($metadata))
                        wp_update_attachment_metadata($attach_id, $metadata);

                    return $attach_id;
                }
            }
        }

        return 0;
    }

    function import_games($data) {

        if(is_string($data))
            $data = json_decode(gzuncompress($data));

        if(empty($data) || !is_array($data))
            return false;

        foreach($data as $game) {

            $game_data = $this->extract_args($game, array(
                'title' => '', 'abbr' => '',
                'icon' => '', 'g_banner_file' => '', 'maplist' => array()
            ));

            if(!empty($game_data['title'])) {
                $p = $game_data;
                $p['icon'] = $this->_import_image((array)$p['icon']);
                $p['g_banner_file'] = $this->_import_image((array)$p['g_banner_file']);
                $maplist = $p['maplist'];

                unset($p['maplist']);

                $game_id = $this->add_game($p);
                if(!empty($game_id)) {

                    foreach($maplist as $map) {
                        $p = (array)$map;
                        $p['screenshot'] = $this->_import_image((array)$p['screenshot']);
                        $p['game_id'] = $game_id;

                        if(!empty($p['title']))
                            $this->add_map($p);
                    }

                }
            }

        }

        return true;
    }

    function on_add_game()
    {
        return $this->game_editor(esc_html__('New Game', 'arcane'), 'wp-teamwars-addgame', esc_html__('Add Game', 'arcane'));
    }

    function on_edit_game()
    {

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        return $this->game_editor(esc_html__('Edit Game', 'arcane'), 'wp-teamwars-editgame', esc_html__('Update Game', 'arcane'), $id);
    }

    function on_load_manage_games()
    {
        $act = isset($_GET['act']) ? $_GET['act'] : '';
        $id = isset($_GET['id']) ? $_GET['id'] : 0;
        $game_id = isset($_GET['game_id']) ? $_GET['game_id'] : 0;
        $die = false;

        // Check game or map is really exists
        if($act == 'add' && !$this->acl_user_can('manage_game', 'all')) {
            $die = true;
        }
        else if($act == 'edit' || $act == 'maps' || $act == 'addmap') {

            $g = $this->get_game(array('id' =>
                    ($act == 'maps' || $act == 'addmap' ? $game_id : $id)
                ));

            $die = empty($g) || !$this->acl_user_can('manage_game', $g[0]->id);

        } else if($act == 'editmap') {

            $m = $this->get_map(array('id' => $id));
            $die = empty($m);
        }

        if($die)
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );
        $allowed_tags = array(
            'code' => array(),
            'em' => array()
        );
        if(sizeof($_POST)) {

            $edit_maps_errors = array(
                self::ErrorDatabase => esc_html__('Database error.', 'arcane'),
                self::ErrorOK => esc_html__('The game is updated.', 'arcane'),
                self::ErrorUploadMaxFileSize => wp_kses(__('The uploaded file exceeds the <code>upload_max_filesize</code> directive in <code>php.ini</code>.', 'arcane'), $allowed_tags ),
                self::ErrorUploadHTMLMaxFileSize => wp_kses(__('The uploaded file exceeds the <em>MAX_FILE_SIZE</em> directive that was specified in the HTML form.', 'arcane'), $allowed_tags ),
                self::ErrorUploadPartially => esc_html__('The uploaded file was only partially uploaded.', 'arcane'),
                self::ErrorUploadNoFile => esc_html__('No file was uploaded.', 'arcane'),
                self::ErrorUploadMissingTemp => esc_html__('Missing a temporary folder.', 'arcane'),
                self::ErrorUploadDiskWrite => esc_html__('Failed to write file to disk.', 'arcane'),
                self::ErrorUploadStoppedByExt => esc_html__('File upload stopped by extension.', 'arcane'),
                self::ErrorUploadFileTypeNotAllowed => esc_html__('File type does not meet security guidelines. Try another.', 'arcane')
            );

                switch($act) {
                    case 'add':

                        $defaults = array('title' => '', 'abbr' => '', 'icon' => 0, 'g_banner_file' => 0);
                        $data = $this->extract_args(stripslashes_deep($_POST), $defaults);
                        extract($data);

                        if(!empty($title)) {

                            $data['icon'] = $this->handle_upload('icon_file');
                            $data['g_banner_file'] = $this->handle_upload('g_banner_file');

                            if($data['icon'] == self::ErrorUploadNoFile)
                                $data['icon'] = 0;

                            if($data['g_banner_file'] == self::ErrorUploadNoFile)
                                $data['g_banner_file'] = 0;

                            if($data['icon'] >= 0) {

                                if($this->add_game($data)) {
                                    wp_redirect(admin_url('admin.php?page=wp-teamwars-games&add=1'), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');
                            } else
                                $this->add_notice($edit_maps_errors[$attach_id], 'error');

                            if($data['g_banner_file'] >= 0) {

                                if($this->add_game($data)) {
                                    wp_redirect(admin_url('admin.php?page=wp-teamwars-games&add=1'), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');
                            } else
                                $this->add_notice($edit_maps_errors[$attach_id], 'error');


                        } else
                            $this->add_notice(esc_html__('Game title is required field.', 'arcane'), 'error');
                    break;

                    case 'edit':
                        $defaults = array('title' => '', 'abbr' => '', 'delete_image' => false, 'delete_image1' => false);
                        $data = $this->extract_args(stripslashes_deep($_POST), $defaults);
                        extract($data);

                        unset($data['delete_image']);
                        unset($data['delete_image1']);

                        if(!empty($title)) {

                            if(!empty($delete_image))
                                $data['icon'] = 0;

                            if(!empty($delete_image1))
                                $data['g_banner_file'] = 0;

                            $attach_id = $this->handle_upload('icon_file');
                            $attach_id1 = $this->handle_upload('g_banner_file');

                            if($attach_id == self::ErrorUploadNoFile)
                                $attach_id = 0;
                            else if($attach_id > 0)
                                $data['icon'] = $attach_id;

                            if($attach_id1 == self::ErrorUploadNoFile)
                                $attach_id1 = 0;
                            else if($attach_id1 > 0)
                                $data['g_banner_file'] = $attach_id1;


                            if($attach_id >= 0) {

                                if($this->update_game($id, $data) !== false) {
                                    wp_redirect(admin_url('admin.php?page=wp-teamwars-games&update=1'), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');

                            } else
                                $this->add_notice($edit_maps_errors[$attach_id], 'error');


                            if($attach_id1 >= 0) {

                                if($this->update_game($id, $data) !== false) {
                                    wp_redirect(admin_url('admin.php?page=wp-teamwars-games&update=1'), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');

                            } else
                                $this->add_notice($edit_maps_errors[$attach_id1], 'error');



                        } else
                            $this->add_notice(esc_html__('Game title is required field.', 'arcane'), 'error');
                        break;

                    case 'addmap':
                        $defaults = array('title' => '', 'game_id' => 0, 'id' => 0);
                        $data = $this->extract_args(stripslashes_deep($_POST), $defaults);
                        extract($data);

                        if(!empty($title)) {

                            $attach_id = $this->handle_upload('screenshot_file');

                            if($attach_id == self::ErrorUploadNoFile)
                                $attach_id = 0;

                            if($attach_id >= 0) {

                                if($this->add_map(array('title' => $title, 'screenshot' => $attach_id, 'game_id' => $game_id)) !== false) {
                                    wp_redirect(admin_url(sprintf('admin.php?page=wp-teamwars-games&act=maps&game_id=%d&add=1', $game_id)), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');

                            } else
                                $this->add_notice($edit_maps_errors[$attach_id], 'error');

                        } else
                            $this->add_notice(esc_html__('Map title is required field.', 'arcane'), 'error');

                        break;

                    case 'editmap':
                        $defaults = array('title' => '', 'game_id' => 'all', 'id' => 0, 'delete_image' => false);
                        $data = $this->extract_args(stripslashes_deep($_POST), $defaults);
                        extract($data);

                        $update_data = array('title' => $title);

                        if(!empty($title)) {

                            if(!empty($delete_image))
                                $update_data['screenshot'] = 0;

                            $attach_id = $this->handle_upload('screenshot_file');

                            if($attach_id == self::ErrorUploadNoFile)
                                $attach_id = 0;
                            else if($attach_id > 0)
                                $update_data['screenshot'] = $attach_id;

                            if($attach_id >= 0) {

                                if($this->update_map($id, $update_data) !== false) {
                                    wp_redirect(admin_url(sprintf('admin.php?page=wp-teamwars-games&act=maps&game_id=%d&update=1', $game_id)), $status = 302);
                                    exit();
                                } else
                                    $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');

                            } else
                                $this->add_notice($edit_maps_errors[$attach_id], 'error');

                        } else
                            $this->add_notice(esc_html__('Map title is required field.', 'arcane'), 'error');

                        break;
                }

        }
    }

    function on_manage_games()
    {
        $act = isset($_GET['act']) ? $_GET['act'] : '';
        $current_page = isset($_GET['paged']) ? $_GET['paged'] : 1;
        $filter_games = $this->acl_user_can('which_games');
        $limit = 10;
        $arcane_allowed = wp_kses_allowed_html( 'post' );

        switch($act) {
            case 'add':
                return $this->on_add_game();
                break;
            case 'edit':
                return $this->on_edit_game();
                break;
            case 'maps':
                return $this->on_edit_maps();
                break;
            case 'addmap':
                return $this->on_add_map();
                break;
            case 'editmap':
                return $this->on_edit_map();
                break;
        }

        $teams = $this->get_game(array(
                    'id' => $filter_games,
                    'orderby' => 'title', 'order' => 'asc',
                    'limit' => $limit, 'offset' => ($limit * ($current_page-1))
                ));
        $stat = $this->get_game(array('id' => $filter_games, 'limit' => $limit), true);

        $page_links = paginate_links( array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => esc_html__('&laquo;', 'arcane'),
                'next_text' => esc_html__('&raquo;', 'arcane'),
                'total' => $stat['total_pages'],
                'current' => $current_page
        ));

        $page_links_text = sprintf( '<span class="displaying-num">' . esc_html__( 'Displaying %s&#8211;%s of %s', 'arcane' ) . '</span>%s',
                number_format_i18n( (($current_page - 1) * $limit) + 1 ),
                number_format_i18n( min( $current_page * $limit, $stat['total_items'] ) ),
                '<span class="total-type-count">' . number_format_i18n( $stat['total_items'] ) . '</span>',
                $page_links
        );

        $table_columns = array('cb' => '<input type="checkbox" />',
                      'title' => esc_html__('Title', 'arcane'),
                      'abbr' => esc_html__('Abbreviation', 'arcane'),
                      'id' => esc_html__('Game ID', 'arcane')

                      );

        if(isset($_GET['add'])) {
            $this->add_notice(esc_html__('Game is successfully added.', 'arcane'), 'updated');
        }

        if(isset($_GET['update'])) {
            $this->add_notice(esc_html__('Game is successfully updated.', 'arcane'), 'updated');
        }

        if(isset($_GET['delete'])) {
            $deleted = (int)$_GET['delete'];
            $this->add_notice(sprintf(_n('%d Game deleted.', '%d Games deleted', $deleted, 'arcane'), $deleted), 'updated');
        }

        $this->print_notices();

    ?>
        <div class="wrap wp-cw-games">
            <h2><?php esc_html_e('Games', 'arcane'); ?>
                <?php if($this->acl_user_can('manage_game', 'all')) : ?> <a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&act=add')); ?>" class="add-new-h2"><?php esc_html_e('Add New', 'arcane'); ?></a><?php endif; ?>
            </h2>

            <div id="poststuff" class="metabox-holder">

                <div id="post-body">
                    <div id="post-body-content" class="has-sidebar-content">

                    <form id="wp-teamwars-manageform" action="admin-post.php" method="post">
                        <?php wp_nonce_field('wp-teamwars-gamesop'); ?>

                        <input type="hidden" name="action" value="wp-teamwars-gamesop" />

                        <div class="tablenav">

                            <div class="alignleft actions">
                                <select name="do_action">
                                    <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                                    <option value="export"><?php esc_html_e('Export', 'arcane'); ?></option>
                                </select>
                                <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction" id="wp-teamwars-doaction" class="button-secondary action" />
                            </div>

                            <div class="alignright actions">
                                <label class="screen-reader-text" for="games-search-input"><?php esc_html_e('Search Teams:', 'arcane'); ?></label>
                                <input id="games-search-input" name="s" value="" type="text" />

                                <input id="games-search-submit" value="<?php esc_html_e('Search Games', 'arcane'); ?>" class="button" type="button" />
                            </div>

                        <br class="clear" />

                        </div>

                        <div class="clear"></div>

                        <table class="widefat fixed" cellspacing="0">
                        <thead>
                        <tr>
                        <?php $this->print_table_header($table_columns); ?>
                        </tr>
                        </thead>

                        <tfoot>
                        <tr>
                        <?php $this->print_table_header($table_columns, false); ?>
                        </tr>
                        </tfoot>

                        <tbody>
                        <?php
                        $requesturi = admin_url('admin.php?page=wp-clanwars-games');
                        if (filter_has_var(INPUT_SERVER, "REQUEST_URI")) {
                            $requesturi = filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
                        }


                        ?>
                        <?php foreach($teams as $i => $item) : ?>

                            <tr class="iedit<?php if($i % 2 == 0) echo ' alternate'; ?>">
                                <th scope="row" class="check-column"><input type="checkbox" name="items[]" value="<?php echo esc_attr($item->id); ?>" /></th>
                                <td class="title column-title">
                                    <a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&amp;act=edit&amp;id=' . $item->id)); ?>" title="<?php echo sprintf(esc_html__('Edit &#8220;%s&#8221; Team', 'arcane'), esc_attr($item->title)); ?>"> <?php echo esc_html($item->title); ?></a><br />
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&amp;act=edit&amp;id=' . $item->id)); ?>"><?php esc_html_e('Edit', 'arcane'); ?></a></span> |
                                        <span class="edit"><a href="<?php echo admin_url('admin.php?page=wp-teamwars-games&amp;act=maps&amp;game_id=' . $item->id); ?>"><?php esc_html_e('Maps', 'arcane'); ?></a></span> | <span class="delete">
                                        <a href="<?php echo wp_nonce_url('admin-post.php?action=wp-teamwars-gamesop&amp;do_action=delete&amp;items[]=' . $item->id . '&amp;_wp_http_referer=' . urlencode($requesturi), 'wp-teamwars-gamesop'); ?>"><?php esc_html_e('Delete', 'arcane'); ?></a></span>
                                    </div>
                                </td>
                                <td class="abbr column-abbr">
                                    <?php echo esc_html($item->abbr); ?>
                                </td>

                                <td class="id column-id">
                                    <?php echo esc_html($item->id); ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                        </table>

                        <div class="tablenav">

                            <div class="tablenav-pages"><?php echo wp_kses($page_links_text, $arcane_allowed); ?></div>

                            <div class="alignleft actions">
                            <select name="do_action2">
                                <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                                <option value="export"><?php esc_html_e('Export', 'arcane'); ?></option>
                            </select>
                            <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction2" id="wp-teamwars-doaction2" class="button-secondary action" />
                            </div>

                            <br class="clear" />

                        </div>

                    </form>

                    </div>
                </div>
                <br class="clear"/>

            </div>
        </div>
    <?php
    }

    function game_editor($page_title, $page_action, $page_submit, $game_id = 0)
    {
        $defaults = array('title' => '', 'icon' => 0, 'g_banner_file' => 0, 'abbr' => '', 'action' => '');
        $arcane_allowed = wp_kses_allowed_html( 'post' );
	    $data = array();

        if($game_id > 0) {
            $t = $this->get_game(array('id' => $game_id));
            if(!empty($t))
                $data = (array)$t[0];
        }

	    $icon = 0;
	    $g_banner_file = 0;
	    $title = '';
	    $abbr = '';
	    $action = '';

        extract($this->extract_args(stripslashes_deep($_POST), $this->extract_args($data, $defaults)));

        $this->print_notices();

        $attach = wp_get_attachment_image($icon, 'thumbnail');
        $attach1 = wp_get_attachment_image($g_banner_file, 'thumbnail');

        ?>

            <div class="wrap wp-cw-gameeditor">
                <h2><?php echo esc_attr($page_title); ?></h2>

                    <form name="team-editor" id="team-editor" method="post" action="" enctype="multipart/form-data">

                        <input type="hidden" name="action" value="<?php echo esc_attr($page_action); ?>" />
                        <input type="hidden" name="id" value="<?php echo esc_attr($game_id); ?>" />

                        <?php wp_nonce_field($page_action); ?>

                        <table class="form-table">

                        <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="title"><span class="alignleft"><?php esc_html_e('Title', 'arcane'); ?></span><span class="alignright"><abbr title="<?php esc_html_e('required', 'arcane'); ?>" class="required">*</abbr></span><br class="clear" /></label></th>
                            <td>
                                <input name="title" id="title" type="text" class="regular-text" value="<?php echo esc_attr($title); ?>" maxlength="200" autocomplete="off" aria-required="true" />
                            </td>
                        </tr>

                        <tr class="form-field">
                            <th scope="row" valign="top"><label for="title"><?php esc_html_e('Abbreviation', 'arcane'); ?></label></th>
                            <td>
                                <input name="abbr" id="abbr" type="text" class="regular-text" value="<?php echo esc_attr($abbr); ?>" maxlength="20" autocomplete="off" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row" valign="top"><label for="icon_file"><?php esc_html_e('Icon', 'arcane'); ?></label></th>
                            <td>
                                <input type="file" name="icon_file" id="icon_file" />

                                <?php if(!empty($attach)) : ?>
                                <div class="screenshot"><?php echo wp_kses($attach, $arcane_allowed); ?></div>
                                <div>
                                <label for="delete-image"><input type="checkbox" name="delete_image" id="delete-image" /> <?php esc_html_e('Delete Icon', 'arcane'); ?></label>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>


                        <tr>
                            <th scope="row" valign="top"><label for="icon_file"><?php esc_html_e('Banner', 'arcane'); ?></label></th>
                            <td>
                                <input type="file" name="g_banner_file" id="g_banner_file" />

                                <?php if(!empty($attach1)) : ?>
                                <div class="screenshot"><?php echo wp_kses($attach1, $arcane_allowed); ?></div>
                                <div>
                                <label for="delete-image1"><input type="checkbox" name="delete_image1" id="delete-image1" /> <?php esc_html_e('Delete Banner', 'arcane'); ?></label>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>


                        </table>

                        <p class="submit"><input type="submit" class="button-primary" name="submit" value="<?php echo esc_attr($page_submit); ?>" /></p>

                    </form>

            </div>

        <?php
    }

    function _get_file_content($filename) {

        WP_Filesystem();
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents($filename);

        if($content) {
            return $content;
        }

        return null;
    }

    /*
     * Maps managment
     */

    function on_admin_post_deletemaps()
    {
        if(!$this->acl_user_can('manage_games'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

        check_admin_referer('wp-teamwars-deletemaps');

        $referer = remove_query_arg(array('add', 'update'), $_REQUEST['_wp_http_referer']);

        if($_REQUEST['do_action'] == 'delete' || $_REQUEST['do_action2'] == 'delete') {
            extract($this->extract_args($_REQUEST, array('delete' => array())));

            $error = $this->delete_map($delete);
            $referer = add_query_arg('delete', $error, $referer);
        }

        wp_redirect($referer, $status = 302);
    }

    function on_edit_maps()
    {
        $game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
        $current_page = isset($_GET['paged']) ? $_GET['paged'] : 1;
        $limit = 10;
        $arcane_allowed = wp_kses_allowed_html( 'post' );

        $maps = $this->get_map('id=all&orderby=title&order=asc&game_id=' . $game_id . '&limit=' . $limit . '&offset=' . ($limit * ($current_page-1)));
        $stat = $this->get_map('id=all&game_id=' . $game_id . '&limit=' . $limit, true);

        $page_links = paginate_links( array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => esc_html__('&laquo;', 'arcane'),
                'next_text' => esc_html__('&raquo;', 'arcane'),
                'total' => $stat['total_pages'],
                'current' => $current_page
        ));

        $page_links_text = sprintf( '<span class="displaying-num">' . esc_html__( 'Displaying %s&#8211;%s of %s', 'arcane' ) . '</span>%s',
                number_format_i18n( (($current_page - 1) * $limit) + 1 ),
                number_format_i18n( min( $current_page * $limit, $stat['total_items'] ) ),
                '<span class="total-type-count">' . number_format_i18n( $stat['total_items'] ) . '</span>',
                $page_links
        );

        $table_columns = array('cb' => '<input type="checkbox" />',
                      'icon' => '',
                      'title' => esc_html__('Title', 'arcane'));

        if(isset($_GET['add'])) {
            $this->add_notice(esc_html__('Map is successfully added.', 'arcane'), 'updated');
        }

        if(isset($_GET['update'])) {
            $this->add_notice(esc_html__('Map is successfully updated.', 'arcane'), 'updated');
        }

        if(isset($_GET['delete'])) {
            $deleted = (int)$_GET['delete'];
            $this->add_notice(sprintf(_n('%d Map deleted.', '%d Maps deleted', $deleted, 'arcane'), $deleted), 'updated');
        }

        $this->print_notices();

    ?>
        <div class="wrap wp-cw-maps">
            <h2><?php esc_html_e('Maps', 'arcane'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&act=addmap&game_id=' . $game_id)); ?>" class="add-new-h2"><?php esc_html_e('Add New', 'arcane'); ?></a></h2>

            <div id="poststuff" class="metabox-holder">

                <div id="post-body">
                    <div id="post-body-content" class="has-sidebar-content">

                    <form id="wp-teamwars-manageform" action="admin-post.php" method="post">
                        <?php wp_nonce_field('wp-teamwars-deletemaps'); ?>

                        <input type="hidden" name="action" value="wp-teamwars-deletemaps" />
                        <input type="hidden" name="game_id" value="<?php echo esc_attr($game_id); ?>" />

                        <div class="tablenav">

                            <div class="alignleft actions">
                                <select name="do_action">
                                    <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                                </select>
                                <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction" id="wp-teamwars-doaction" class="button-secondary action" />
                            </div>

                            <div class="alignright actions">
                                <label class="screen-reader-text" for="maps-search-input"><?php esc_html_e('Search Maps:', 'arcane'); ?></label>
                                <input id="maps-search-input" name="s" value="" type="text" />

                                <input id="maps-search-submit" value="<?php esc_html_e('Search Maps', 'arcane'); ?>" class="button" type="button" />
                            </div>

                        <br class="clear" />

                        </div>

                        <div class="clear"></div>

                        <table class="widefat fixed" cellspacing="0">
                        <thead>
                        <tr>
                        <?php $this->print_table_header($table_columns); ?>
                        </tr>
                        </thead>

                        <tfoot>
                        <tr>
                        <?php $this->print_table_header($table_columns, false); ?>
                        </tr>
                        </tfoot>

                        <tbody>
                        <?php
                        $requesturi = admin_url('admin.php?page=wp-clanwars-games&act=maps');
                        if (filter_has_var(INPUT_SERVER, "REQUEST_URI")) {
                            $requesturi = filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
                        }
                        ?>
                        <?php foreach($maps as $i => $item) : ?>

                            <tr class="iedit<?php if($i % 2 == 0) echo ' alternate'; ?>">
                                <th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="<?php echo esc_attr($item->id); ?>" /></th>
                                <td class="column-icon media-icon">
                                    <?php $attach = wp_get_attachment_image($item->screenshot, 'thumbnail');
                                    if(!empty($attach)) echo wp_kses($attach,$arcane_allowed);
                                    ?>
                                </td>
                                <td class="title column-title">
                                    <a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&amp;act=editmap&amp;id=' . $item->id)); ?>" title="<?php echo sprintf(esc_html__('Edit &#8220;%s&#8221; Map', 'arcane'), esc_attr($item->title)); ?>"> <?php echo esc_html($item->title); ?></a><br />
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-games&amp;act=editmap&amp;id=' . $item->id)); ?>"><?php esc_html_e('Edit', 'arcane'); ?></a></span> | <span class="delete">
                                                <a href="<?php echo wp_nonce_url('admin-post.php?action=wp-teamwars-deletemaps&amp;do_action=delete&amp;delete[]=' . $item->id . '&amp;_wp_http_referer=' . urlencode($requesturi), 'wp-teamwars-deletemaps'); ?>"><?php esc_html_e('Delete', 'arcane'); ?></a></span>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                        </table>

                        <div class="tablenav">

                            <div class="tablenav-pages"><?php echo wp_kses($page_links_text,$arcane_allowed); ?></div>

                            <div class="alignleft actions">
                            <select name="do_action2">
                            <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                            </select>
                            <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction2" id="wp-teamwars-doaction2" class="button-secondary action" />
                            </div>

                            <br class="clear" />

                        </div>

                    </form>

                    </div>
                </div>
                <br class="clear"/>

            </div>

        </div>
<?php

    }

    function get_map($p, $count = false)
    {
        global $wpdb;

        extract($this->extract_args($p, array(
            'id' => false,
            'game_id' => false,
            'limit' => 0,
            'offset' => 0,
            'orderby' => 'id',
            'order' => 'ASC')));

        $limit_query = '';
        $order_query = '';

        $order = strtolower($order);
        if($order != 'asc' && $order != 'desc')
            $order = 'asc';

        $order_query = 'ORDER BY `' . $orderby . '` ' . $order;

        if($id != 'all' && $id !== false) {

            if(!is_array($id))
                $id = array($id);

            $id = array_map('intval', $id);
            $where_query = array();
            $where_query[] = 'id IN (' . implode(', ', $id) . ')';
        }

        if($game_id != 'all' && $game_id !== false) {

            if(!is_array($game_id))
                $game_id = array($game_id);
             if(!isset($where_query))$where_query = array();
            $game_id = array_map('intval', $game_id);
            $where_query[] = 'game_id IN (' . implode(', ', $game_id) . ')';
        }

        if($limit > 0) {
            $limit_query = $wpdb->prepare('LIMIT %d, %d', $offset, $limit);
        }

        if(!empty($where_query))
            $where_query = 'WHERE ' . implode(' AND ', $where_query);

        if($count) {

            $rslt = $wpdb->get_row('SELECT COUNT(id) AS m_count FROM `' . $this->tables['maps'] . '` ' . $where_query);

            $ret = array('total_items' => 0, 'total_pages' => 1);

            $ret['total_items'] = $rslt->m_count;

            if($limit > 0)
                $ret['total_pages'] = ceil($ret['total_items'] / $limit);

            return $ret;
        }

          if(!isset($where_query))$where_query = '';

        $rslt = $wpdb->get_results('SELECT * FROM `' . $this->tables['maps'] . '` ' . implode(' ', array($where_query, $order_query, $limit_query)));

        return $rslt;
    }

    function add_map($p)
    {
        global $wpdb;

        $data = $this->extract_args($p, array(
                    'title' => '',
                    'screenshot' => 0,
                    'game_id' => 0));

        if($wpdb->insert($this->tables['maps'], $data, array('%s', '%d', '%d')))
        {
            $insert_id = $wpdb->insert_id;

            return $insert_id;
        }

        return false;
    }

    function update_map($id, $p)
    {
        global $wpdb;

        $fields = array('title' => '%s', 'screenshot' => '%d', 'game_id' => '%d');

        $data = wp_parse_args($p, array());

        $update_data = array();
        $update_mask = array();

        foreach($fields as $fld => $mask) {
            if(isset($data[$fld])) {
                $update_data[$fld] = $data[$fld];
                $update_mask[] = $mask;
            }
        }

        $result = $wpdb->update($this->tables['maps'], $update_data, array('id' => $id), $update_mask, array('%d'));

        return $result;
    }

    function delete_map($id)
    {
        global $wpdb;

        if(!is_array($id))
            $id = array($id);

        $id = array_map('intval', $id);

        return $wpdb->query($wpdb->prepare('DELETE FROM `' . $this->tables['maps'] . '` WHERE id IN(%s)',implode(',', $id) ));
    }

    function delete_map_by_game($id)
    {
        global $wpdb;

        if(!is_array($id))
            $id = array($id);

        $id = array_map('intval', $id);

        return $wpdb->query($wpdb->prepare('DELETE FROM `' . $this->tables['maps'] . '` WHERE game_id IN(%s)',implode(',', $id) ));
    }

    function on_add_map()
    {
       $game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;

       $this->map_editor(esc_html__('Add Map', 'arcane'), 'wp-teamwars-addmap', esc_html__('Add Map', 'arcane'), $game_id);
    }

    function on_edit_map()
    {
       $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

       $this->map_editor(esc_html__('Edit Map', 'arcane'), 'wp-teamwars-editmap', esc_html__('Update Map', 'arcane'), 0, $id);
    }

    function page_not_found($title, $message) {

        echo '<div class="wrap"><h2>' . esc_attr($title) . '</h2>' . esc_attr($message) . '</div>';

    }

    function map_editor($page_title, $page_action, $page_submit, $game_id, $id = 0)
    {
        $defaults = array('title' => '', 'screenshot' => 0, 'abbr' => '', 'action' => '');
        $arcane_allowed = wp_kses_allowed_html( 'post' );
        if($id > 0) {
            $t = $this->get_map(array('id' => $id, 'game_id' => $game_id));

            if(!empty($t)){
                $data = (array)$t[0];
                $game_id = $data['game_id'];
            }
        }

        extract($this->extract_args(stripslashes_deep($_POST), $this->extract_args($data, $defaults)));

        $attach = wp_get_attachment_image($screenshot, 'thumbnail');

        $this->print_notices();

        ?>

            <div class="wrap wp-cw-mapeditor">
                <h2><?php echo esc_attr($page_title); ?></h2>

                    <form name="map-editor" id="map-editor" method="post" action="" enctype="multipart/form-data">

                        <input type="hidden" name="action" value="<?php echo esc_attr($page_action); ?>" />
                        <input type="hidden" name="game_id" value="<?php echo esc_attr($game_id); ?>" />
                        <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />

                        <?php wp_nonce_field($page_action); ?>

                        <table class="form-table">

                        <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="title"><span class="alignleft"><?php esc_html_e('Title', 'arcane'); ?></span><span class="alignright"><abbr title="<?php esc_html_e('required', 'arcane'); ?>" class="required">*</abbr></span><br class="clear" /></label></th>
                            <td>
                                <input name="title" id="title" type="text" class="regular-text" value="<?php echo esc_attr($title); ?>" maxlength="200" autocomplete="off" aria-required="true" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row" valign="top"><label for="screenshot_file"><?php esc_html_e('Screenshot', 'arcane'); ?></label></th>
                            <td>
                                <input type="file" name="screenshot_file" id="screenshot_file" />

                                <?php if(!empty($attach)) : ?>
                                <div class="screenshot"><?php echo wp_kses($attach,$arcane_allowed); ?></div>
                                <div>
                                <label for="delete-image"><input type="checkbox" name="delete_image" id="delete-image" /> <?php esc_html_e('Delete Screenshot', 'arcane'); ?></label>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>

                        </table>

                        <p class="submit"><input type="submit" class="button-primary" name="submit" value="<?php echo esc_attr($page_submit); ?>" /></p>

                    </form>

            </div>

        <?php
    }

    /*
     * Matches managment
     */

    function on_admin_post_deletematches()
    {
      //UPDATED TO POSTS
        if(!$this->acl_user_can('manage_matches'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

        check_admin_referer('wp-teamwars-deletematches');

        $referer = remove_query_arg(array('add', 'update'), $_REQUEST['_wp_http_referer']);

        if($_REQUEST['do_action'] == 'delete' || $_REQUEST['do_action2'] == 'delete') {
            extract($this->extract_args($_REQUEST, array('delete' => array())));

            $error = $this->delete_match($delete);
            $referer = add_query_arg('delete', $error, $referer);
        }

        wp_redirect($referer, $status = 302);
    }

    function current_time_fixed( $type, $gmt = 0 ) {
        $t = ( $gmt ) ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', ( time() + ( get_option( 'gmt_offset' ) * 3600 ) ) );
        switch ( $type ) {
            case 'mysql':
                return $t;
                break;
            case 'timestamp':
                return strtotime($t);
                break;
        }
    }

    function html_date_helper( $prefix, $time = 0, $tab_index = 0 )
    {
        global $wp_locale;
        date_default_timezone_set(get_option('timezone_string'));
        $tab_index_attribute = '';
        $tab_index = (int)$tab_index;
        if ($tab_index > 0)
            $tab_index_attribute = " tabindex=\"$tab_index\"";

        if($time == 0)
            $time_adj = current_time('timestamp', 1);
        else
            $time_adj = $time;

        $jj = date( 'd', $time_adj );
        $mm = date( 'm', $time_adj );
        $hh = date( 'H', $time_adj );
        $mn = date( 'i', $time_adj );
        $yy = date( 'Y', $time_adj );

        $month = "<select name=\"{$prefix}[mm]\"$tab_index_attribute>\n";
        for ( $i = 1; $i < 13; $i = $i +1 ) {
                $month .= "\t\t\t" . '<option value="' . zeroise($i, 2) . '"';
                if ( $i == $mm )
                        $month .= ' selected="selected"';
                $month .= '>' . $wp_locale->get_month( $i ) . "</option>\n";
        }
        $month .= '</select>';

        $day = '<input type="text" name="'.$prefix.'[jj]" value="' . $jj . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off"  />';
        $hour = '<input type="text" name="'.$prefix.'[hh]" value="' . $hh . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off"  />';
        $minute = '<input type="text" name="'.$prefix.'[mn]" value="' . $mn . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off"  />';
        $year = '<input type="text" name="'.$prefix.'[yy]" value="' . $yy . '" size="3" maxlength="4"' . $tab_index_attribute . ' autocomplete="off"  />';

        printf(before_last_bar(esc_html__('%1$s%5$s %2$s @ %3$s : %4$s|1: month input, 2: day input, 3: hour input, 4: minute input, 5: year input', 'arcane')), $month, $day, $hour, $minute, $year);
    }

    function date_array2time_helper($date)
    {
        if(is_array($date) &&
            isset($date['hh'], $date['mn'], $date['mm'], $date['jj'], $date['yy']))
        {
            return mktime($date['hh'], $date['mn'], 0, $date['mm'], $date['jj'], $date['yy']);
        }

        return $date;
    }



    function get_match($p, $count = false, $bez_turnira = false)
    {

        extract($this->extract_args($p, array(
            'to_date' => 0,
            'from_date' => 0,
            'id' => false,
            'game_id' => false,
            'sum_tickets' => false,
            'limit' => 0,
            'offset' => 0,
            'orderby' => 'ID',
            'order' => 'ASC',
            'locked' => 0,
            'search' => false,
            'status' => false )));

        if(is_numeric($id) AND ($id > 0)) {
            $obj_merged = (object) array_merge((array) get_post($id), (array) arcane_get_meta($id));

            if (((isset($obj_merged->team1_tickets)) AND (isset($obj_merged->team2_tickets)) AND (($obj_merged->team1_tickets == 0) OR ($obj_merged->team2_tickets == 0))) OR (!isset($obj_merged->team1_tickets) AND !isset($obj_merged->team2_tickets))) {

                if(!isset($obj_merged->ID) or empty($obj_merged->ID))$obj_merged->ID = 0;

              $tickets = $this->SumTickets($obj_merged->ID);

                  if ((isset($obj_merged->team1_tickets) && $obj_merged->team1_tickets != $tickets[0]) OR (isset($obj_merged->team2_tickets) && $obj_merged->team2_tickets != $tickets[1])){
                    update_post_meta($obj_merged->ID, 'team1_tickets', $tickets[0]);
                    update_post_meta($obj_merged->ID, 'team2_tickets', $tickets[1]);
                    $obj_merged->team1_tickets = $tickets[0];
                    $obj_merged->team2_tickets = $tickets[1];
                  }

            }

            return $obj_merged;
        }

        $order = strtoupper($order);
        if($order != 'ASC' && $order != 'DESC')
            $order = 'ASC';

            if ($count) {
            if ($search) {
              $args = array(
                'post_type' => 'matches',
                'posts_per_page' => -1,
                's' => $search,
                'post_status' => 'publish',
              );
            } else {
              $args = array(
                'post_type' => 'matches',
                'posts_per_page' => -1,
                'post_status' => 'publish',
              );

            }
            } else {
            if ($search) {
              $args = array(
                    'post_type' => 'matches',
                    'posts_per_page' => -1,
                    'offset' => $offset,
                    'post_status' => 'publish',
                    'orderby' => $orderby,
                    's' => $search,
                    'order' => $order,
              );
            } else {
              $args = array(
                    'post_type' => 'matches',
                    'posts_per_page' => $limit,
                    'offset' => $offset,
                    'post_status' => 'publish',
                    'orderby' => $orderby,
                    'order' => $order,
              );
            }
            }



        $temp_metas = array();


        $counter = 0;

        if($bez_turnira){
         $temp_metas[] = array(
                 'key' => 'tournament_id',
                'compare' => 'NOT EXISTS'
                );
        }

        if($status !== false ) {

            if(!is_array($status))
                $status = array($status);

            if (count ($status) > 1) {
              $temp = array();
              foreach ($status as $single) {
                $temp[] = array(
                  'key' => 'status',
                  'value' => $single,
                  'compare' => '='
                );
              }
              $temp['relation'] = 'OR';
              $temp_metas[] = $temp;
              $counter++;
            }elseif(count ($status) == 1){
                $temp = array();
              foreach ($status as $single) {
                $temp[] = array(
                  'key' => 'status',
                  'value' => $single,
                  'compare' => '='
                );
              }
              $temp_metas[] = $temp;
              $counter++;

            }
        }

        if($to_date > 0) {
          $temp_metas[] = array(
            'key' => 'date_unix',
            'value' => intval($to_date),
            'compare' => '<'
          );

        }

        if($from_date > 0) {
          $temp_metas[] = array(
            'key' => 'date_unix',
            'value' => intval($from_date),
            'compare' => '>='
          );

        }
        if($game_id != 'all' && $game_id !== false) {

            if(!is_array($game_id))
                $game_id = array($game_id);

            if (count ($game_id) > 1) {
              $temp = array();
              foreach ($game_id as $single) {
                $temp[] = array(
                  'key' => 'game_id',
                  'value' => $single,
                  'compare' => '='
                );
              }
              $temp['relation'] = 'OR';
              $temp_metas[] = $temp;
              $counter++;
            }elseif(count ($game_id)  == 1){
               $temp = array();
              foreach ($game_id as $single) {
                $temp[] = array(
                  'key' => 'game_id',
                  'value' => $single,
                  'compare' => '='
                );
              }
              $temp_metas[] = $temp;
              $counter++;
            }
        }


        if ($counter == 1) {
          $args['meta_query'] = $temp_metas;
        } elseif ($counter > 1) {
          $args['meta_query'] = $temp_metas;
          $args['meta_query']['relation'] = 'AND';
        }

        $posts = get_posts($args);


        if($count) {
          if (is_array($posts)) {
            $ret['total_items'] = count($posts);
            if($limit > 0)
                $ret['total_pages'] = ceil($ret['total_items'] / $limit);
            return $ret;
          } else {
            $ret['total_items'] = 0;
            $ret['total_pages'] = 0;
            return $ret;
          }

        }


        $returner = array();
        $counter = 0;
        foreach( $posts as $single) {
          $returner[$counter] = (object) array_merge((array) $single, (array) arcane_get_meta($single->ID));
          $counter ++;
        }

        return $returner;
    }

    function SumTickets($mid) {
      //UPDATED FOR POSTS
      if ($mid > 0) {
        $current = get_post_meta($mid, 'tickets', true);
        $data[0] = 0;
        $data[1] = 0;
        if (is_array($current) AND (count($current) > 0)) {
          foreach ($current as $single) {
            if (isset($single['tickets1'])) {
                $data[0] = $data[0] + intval($single['tickets1']);
            }
            if (isset($single['tickets2'])) {
                $data[1] = $data[1] + intval($single['tickets2']);
            }
          }
        }
        return $data;
      }
    }

    function update_match_post($match_id, $tid = 0, $type="team") {
        //UPDATED FOR POSTS

        $post_category = get_option(ARCANE_TEAMWARS_CATEGORY, -1);
        $postarr = array(
            'post_status' => 'publish',
            'post_content' => '',
            'post_excerpt' => '',
            'post_title' => '',
            'post_type' => 'matches',
            'comment_status' => 'open'

        );

        if($post_category != -1)
            $postarr['post_category'] = array((int)$post_category);
        $post = $this->get_match(array('id' => $match_id, 'sum_tickets' => true));

        $m = $post;

        if(!is_null($post)) {
            $postarr['ID'] = $post->ID;
        }

        if(isset($post->tournament_id) && !empty($post->tournament_id)){
            $tourn_id = $post->tournament_id;
            $games = get_post_meta($tourn_id, 'game_cache', true);

            if(is_array($games)){
                foreach ($games as &$game) {
	                if(is_array($game)) {
		                foreach ( $game as &$single_game ) {
			                if ( $single_game['match_post_id'] == $post->ID && ! empty( $post->date_unix ) ) {
				                if ( $post->date_unix != $single_game['time'] ) {
					                $single_game['time'] = $post->date_unix;
				                }
			                }
		                }
	                }
                }
            }

            update_post_meta($tourn_id, 'game_cache', $games);
        }

        $cache_particip = '';
        if(isset($post->tournament_participants))
        $cache_particip = $post->tournament_participants;
        if (isset($post->tournament_participants) && $post->tournament_participants == "team") {
          $t1a = $this->get_team(array('id' => $post->team1));
          $t1 = $t1a->post_title;
          $t2a = $this->get_team(array('id' => $post->team2));
          $t2 = $t2a->post_title;
        } else {

          $t1a = get_user_by('id', $post->team1);
          $t1 = '';
          if(isset($t1a->display_name))
          $t1 = $t1a->display_name;

          $t2a = get_user_by('id', $post->team2);
          $t2 = '';
          if(isset($t2a->display_name))
          $t2 = $t2a->display_name;
        }

        $scores = $this->SumTickets($match_id);
        $team1_title = $t1;
        $team2_title = $t2;

        $t1 = $scores[0];
        $t2 = $scores[1];
        $wl_class1 = $t1 < $t2 ? 'lose' : ($t1 > $t2 ? 'win' : '');
        $wl_class2 = $t1 > $t2 ? 'lose' : ($t1 < $t2 ? 'win' : '');
        if (!isset($post_content)) {
          $post_content = '';
        }
        $post_content .= '<div id="matches" class="tab-pane match-page">
        <div class="profile-fimage match-fimage ">
        <div class="mminfow"><div class="mminfo"><strong>'.$m->title.'</strong>  <i class="fas fa-calendar-alt"></i>  ' . date(get_option('date_format').' '.get_option('time_format'), strtotime($m->date)) . '</div></div>
        <div class="dots"></div>
        <img alt="img" class="attachment-small wp-post-image" src="'.arcane_return_game_banner($m->game_id).'">
        <div class="matched" id="mtch">';
        if(empty($match_status))$match_status='';
        if(($match_status == 'active' || $match_status == 'rejected' || $match_status == 'submitted1' || $match_status == 'submitted2' || $match_status == 'pending') && $admin){
        $post_content .= '<a  mid="'.$match_id.'" href="javascript:void(0);" class="ajaxdeletematch"><i data-original-title="Delete Match" data-toggle="tooltip" class="fas fa-times"></i></a>';
        }
        if(isset($admin) && $admin){
        $post_content .= '<a href="javascript:void(0);"><i data-original-title="Edit Match" data-toggle="tooltip" class="fas fa-cog"></i></a>';
        }

        $post_content .= '</div><div class="team-a">
            <div class="teamimgw">
            <img alt="img" src="'.arcane_return_team_image_big($m->team1, $type).'" />
            <div class="teammfs '.$wl_class1.'"><span>'.$t1.'</span></div>
            </div>
            <div class="pmi_title">'.  $team1_title . '</div>
        </div>
        <div class="mversus">'.esc_html__('vs', 'arcane').'</div>
        <div class="team-b">
            <div class="teamimgw">
            <img alt="img" src="'.arcane_return_team_image_big($m->team2, $type).'" />
            <div class="teammfs '.$wl_class2.'"><span>'.$t2.'</span></div>
            </div>
            <div class="pmi_title">'.  $team2_title . ' </div>
        </div>
        <div class="clear"></div>
        <div class="col-lg-12 col-md-12 nav-top-divider"></div>
    </div>
    <div class="col-lg-12 col-md-12 block ">
        <div id="score_fin" class="mcscalert">
          Score has been submitted by <a>teamnamea</a>! - <span>Accept the score?</span> <a class="ajaxsubmitscore" href="javascript:void(0);" req="accept_score" mid="'.$match_id.'"><i class="fas fa-check"></i></a> <a class="ajaxsubmitscore" href="javascript:void(0);" req="reject_score" mid="'.$match_id.'"><i class="fas fa-times"></i></a>
        </div>
    </div>
    <div class="col-lg-6 col-md-6 block mdescription">
        <div class="title-wrapper">
            <h3 class="widget-title"><i class="fas fa-bullhorn"></i>'.esc_html__(" Match description", "arcane").'</h3></div>
        <div class="wcontainer">'.wpautop(do_shortcode(stripslashes(wp_kses_post($m->description)))).'
        </div>
    </div>';

    $post_content .=
    '<div class="col-lg-6 col-md-6 block mmaps block">
        <div class="title-wrapper"><h3 class="widget-title"><i class="far fa-image"></i>'.esc_html__(" Maps", "arcane").'</h3></div>
        <ul>';


          $r = $this->get_rounds($match_id);

            $rounds = array();

            // group rounds by map
            foreach($r as $v) {

                if(!isset($rounds[$v->group_n]))
                    $rounds[$v->group_n] = array();

                $rounds[$v->group_n][] = $v;
            }


            // render maps/rounds
            foreach($rounds as $map_group) {

                $first = $map_group[0];
                $image = wp_get_attachment_image_src($first->screenshot);



                $post_content .= '<li>';

                if(!empty($image))
                $post_content .= '<img src="' . $image[0] . '" alt="' . esc_attr($first->title) . '" />';
                $post_content .= '<strong>' . esc_html($first->title) . '</strong>';
                $post_content .= '<div class="mscorew">';
                foreach($map_group as $round) {

                    $t1 = $round->tickets1;
                    $t2 = $round->tickets2;

                    $post_content .= '<div class="mscore">';
                    $post_content .= sprintf(esc_html__('%1$d:%2$d', 'arcane'), $t1, $t2);
                    $post_content .= '</div>';

                }
               $post_content .= '</div><div class="clear"></div></li>';
            }

            $post_content .='</ul></div>';

            $postarr['post_title'] = $m->title;
            $postarr['post_content'] = $post_content;
            $postarr['post_excerpt'] = '';
            $postarr['ID'] = $match_id;


            wp_update_post($postarr);
            update_post_meta($match_id, 'team1_tickets', $scores[0]);
            update_post_meta($match_id, 'team2_tickets', $scores[1]);
            $games = $this->get_game(array('id' => get_post_meta($match_id, 'game_id', true)));
            if (isset($games[0])) {
              update_post_meta($match_id, 'game_title', $games[0]->title);
              update_post_meta($match_id, 'game_icon', $games[0]->icon);
            }

          if ($tid > 0) {
            update_post_meta($match_id, 'match_status', 1);
            update_post_meta($match_id, 'tournament_id', $tid);
            arcane_create_match_third_place($tid,$match_id);
          }

          update_post_meta($match_id, 'tournament_participants', $cache_particip);

            return $match_id;



    }

    function add_match($p)
    {
        //CONVERTED TO POSTS

        $cpage= get_current_screen();
        if (is_object($cpage)) {
          if($cpage->base == 'teamwars_page_wp-teamwars-matches'){
            $data = $this->extract_args($p, array(
                        'title' => '',
                        'date' => current_time('timestamp', 1),
                        'date_unix' => '',
                        'post_id' => 0,
                        'team1' => 0,
                        'team2' => 0,
                        'game_id' => 0,
                        'match_status' => 0,
                        'description' => '',
                        'external_url' => '',
                        'status' => 'active',
                        'locked' => 0
                ));
            }else{
              $data = $this->extract_args($p, array(
                        'title' => '',
                        'date' => current_time('timestamp', 1),
                        'date_unix' => '',
                        'post_id' => 0,
                        'team1' => 0,
                        'team2' => 0,
                        'game_id' => 0,
                        'match_status' => 0,
                        'description' => '',
                        'external_url' => '',
                        'status' => 'pending',
                        'locked' => 0
               ));
            }
        } else {
          $data = $this->extract_args($p, array(
                    'title' => '',
                    'date' => current_time('timestamp', 1),
                    'date_unix' => '',
                    'post_id' => 0,
                    'team1' => 0,
                    'team2' => 0,
                    'game_id' => 0,
                    'match_status' => 0,
                    'description' => '',
                    'external_url' => '',
                    'status' => 'pending',
                    'locked' => 0
           ));

        }

        $post_category = get_option(ARCANE_TEAMWARS_CATEGORY, -1);
        $postarr = array(
            'post_status' => 'publish',
            'post_content' => '',
            'post_excerpt' => '',
            'post_title' => $data['title'],
            'post_name' => sanitize_title($data['title']),
            'post_type' => 'matches',
            'comment_status' => 'open'
        );
        if($post_category != -1){
            $postarr['post_category'] = array((int)$post_category);
        }

        $new_post_ID = wp_insert_post($postarr);
        if ($new_post_ID) {
          arcane_mass_update_post_meta($new_post_ID, $data);
          return $new_post_ID;
        } else {
          return false;
        }

        return $new_post_ID;
    }

    function update_match($id, $p)
    {
        global $ArcaneWpTeamWars;
        //CONVERTED TO POSTS
        $data = wp_parse_args($p, array());
        if(isset($data['external_url'])) {
            $data['external_url'] = esc_url_raw($data['external_url']);
        }

        arcane_mass_update_post_meta($id, $data);

        if (isset($data['status'])) {
            $tid = get_post_meta($id, 'tournament_id', true);

            $tournament = new arcane_tournaments();

            if ($tournament->LoadTournament($tid)) {
              $m = $this->get_match(array('id' => $id, 'sum_tickets' => true));

               if ( (isset($m->status)) AND (!empty($m->status)) AND (strtolower($m->status) == "done")) {
                $newdata['tid'] = $tid;
                $newdata['match_postid'] = $id;
                $newdata['match_id'] = $id;

                if ($tid > 0){
                    $obj_merged = (object) array_merge((array) get_post($m->ID), (array) arcane_get_meta($m->ID));
                    $tickets = $ArcaneWpTeamWars->SumTickets($obj_merged->ID);
                    $t1 = $tickets[0];
                    $t2 = $tickets[1];
                } else {

                    $t1 = $m->team1_tickets;
                    $t2 = $m->team2_tickets;

                }
                $newdata['team1_score'] = $t1;
                $newdata['team2_score'] = $t2;
                if ($t1 > $t2) {
                    $newdata['winner'] = 'team1';
                } elseif ($t1 == $t2) {
                    $newdata['winner'] = 'draw';
                } else {
                    //$t2 > $t1
                   $newdata['winner'] = 'team2';
                }
                if($data['status'] == 'done')
                $tournament->tournament->OnResultSubmit($newdata);

              } elseif ( (isset($m->status)) AND (!empty($m->status)) AND (strtolower($m->status) == "active")) {
                $newdata['tid'] = $tid;
                $newdata['match_postid'] = $id;
                $newdata['match_id'] = $id;
                 if($data['status'] == 'done')
                $tournament->tournament->OnResultSubmit($newdata);

              }
            }
        }

        return true;
    }

    function delete_match($id)
    {
      //CONVERTED TO POSTS
      if(!is_array($id))
          $id = array($id);

      $id = array_map('intval', $id);

      $this->delete_rounds_by_match($id);
      if (is_array($id)) {
        foreach ($id as $small_id) {
          wp_delete_post($small_id);
        }
      } else {
        wp_delete_post($id);
      }
      return true;
    }
	// pulze
    function lock_match($id)
    {
      //CONVERTED TO POSTS
      if(!is_array($id))
          $id = array($id);
      $id = array_map('intval', $id);
      $this->delete_rounds_by_match($id);
      if (is_array($id)) {
        foreach ($id as $small_id) {
          update_post_meta($small_id, "locked",true);
        }
      } else {
        update_post_meta($id, "locked",true);
      }
      return true;
    }
    function unlock_match($id)
    {
      //CONVERTED TO POSTS
      if(!is_array($id))
          $id = array($id);
      $id = array_map('intval', $id);
      $this->delete_rounds_by_match($id);
      if (is_array($id)) {
        foreach ($id as $small_id) {
          update_post_meta($small_id, "locked",false);
        }
      } else {
        update_post_meta($id, "locked",false);
      }
      return true;
    }
    function delete_match_by_team($id) {
        //CONVERTED TO POSTS

        if(!is_array($id))
            $id = array($id);

        $id = array_map('intval', $id);
        if (is_array($id)) {
          foreach ($id as $small_id) {
            $args = array(
              'post_type' => 'matches',
              'posts_per_page' => -1,
              'post_status' => 'any',
              'meta_query' => array(
                'relation' => 'OR',
                    array(
                        'key' => 'team1',
                        'value' => $small_id,
                        'compare' => '=',
                        'type' => 'numeric',
                    ),
                array(
                  'key' => 'team2',
                  'value' => $small_id,
                  'compare' => '=',
                  'type' => 'numeric',
                ),
                )
            );

            $posts = get_posts($args);

            if (is_array($posts) AND (count($posts) > 0) ) {
              foreach ($posts as $post) {
                wp_delete_post($post->ID);
              }
            }
          }
        }
        return true;
    }

    function delete_match_by_game($id) {
        //UPDATED FOR POSTS
        $args = array(
          'post_type' => 'matches',
          'meta_key' => 'game_id',
          'meta_value' => $id
        );
        $posts = get_posts($args);
        if (is_array($posts)) {
          foreach ($posts as $post) {
            wp_delete_post($post->ID);
          }
        }
    }

    function get_rounds($match_id)
    {
      //UPDATED FOR POSTS

      $rounds =  get_post_meta($match_id, 'tickets', true);
      if (is_array($rounds)) {
        $temparray = array();
        foreach ($rounds as $round) {
          $m = $this->get_map(array('id' => $round['map_id']));
          $round['title'] = $m[0]->title;
          $round['screenshot'] = $m[0]->screenshot;

          $temparray[] = (object)$round;
        }
        return $temparray;
      } else {
        return array();
      }

    }

    function add_round($p)
    {
      //UPDATED TO POSTS
      $data = $this->extract_args($p, array(
                    'match_id' => 0,
                    'group_n' => 0,
                    'map_id' => 0,
                    'tickets1' => 0,
                    'tickets2' => 0
      ));
        if(is_admin() && ($data['tickets1'] != 0 or $data['tickets2'] != 0)){
        update_post_meta($data['match_id'], 'status', 'done');
        }
      $tickets = get_post_meta($data['match_id'], 'tickets', true);

      if (is_array($tickets)) {
        $counter = count($tickets);
        //find new empty ticket ID
        while (isset($tickets[$counter])) {
          $counter++;
        }
        $newdata = array_map('intval', $data);
        $tickets[$counter] = $newdata;
        if(update_post_meta($data['match_id'], 'tickets', $tickets)) {
          return $counter;
        } else {
          return false;
        }
      } else {
        $tickets = array();
        $tickets[1] = array_map('intval', $data);

        if(update_post_meta($data['match_id'], 'tickets', $tickets)) {
          return 1;
        } else {
          return false;
        }
      }
    }

    function update_round($id, $p)
    {
        //UPDATED TO POSTS
        $data = wp_parse_args($p, array());



        if(is_admin() && ($data['tickets1'] != 0 or $data['tickets2'] != 0)){
             update_post_meta($data['match_id'], 'status', 'done');
        }

        $tickets = get_post_meta($data['match_id'], 'tickets', true);

        if (is_array($tickets)) {
          $newdata = array_map('intval', $data);

          $tickets[$id] = $newdata;

          if(update_post_meta($data['match_id'], 'tickets', $tickets)) {
            return $counter;
          } else {
            return false;
          }
        } else {
          return false;
        }

    }

    function delete_rounds_not_in($match_id, $id)
    {
      //UPDATED FOR POSTS
      if(!is_array($id))
          $id = array($id);

        $newrounds = array();
        $rounds = get_post_meta($match_id, 'tickets', true);
        if (is_array($rounds)) {
          foreach ($rounds as $key => $value) {
            if (in_array($key, $id)) {
              $newrounds[$key] = $value;
            }
          }
        }
        $reset = array_values($newrounds);
        update_post_meta($match_id, 'tickets', $reset);
        return true;
    }

    function delete_rounds_by_match($match_id)
    {
      //UPDATED FOR POSTS
        update_post_meta($match_id, 'tickets', '');
        return true;
    }

    function on_add_match()
    {
        return $this->match_editor(esc_html__('Add Match', 'arcane'), 'wp-teamwars-matches', esc_html__('Add Match', 'arcane'));
    }

    function on_edit_match()
    {
        $id = isset($_GET['id']) ? $_GET['id'] : 0;

        return $this->match_editor(esc_html__('Edit Match', 'arcane'), 'wp-teamwars-matches', esc_html__('Edit Match', 'arcane'), $id);
    }

    function on_ajax_get_maps()
    {
    /*  if(!$this->acl_user_can('manage_games') &&
           !$this->acl_user_can('manage_matches'))
            wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );
    */

        $game_id = isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0;

        if($game_id > 0) {
            $maps = $this->get_map(array('game_id' => $game_id, 'order' => 'asc', 'orderby' => 'title'));

            for($i = 0; $i < sizeof($maps); $i++) {
                $url = wp_get_attachment_thumb_url($maps[$i]->screenshot);

                $maps[$i]->screenshot_url = !empty($url) ? $url : '';
            }

            echo json_encode($maps); die();
        }
    }

    function match_editor($page_title, $page_action, $page_submit, $id = 0)
    {

      //UPDATED FOR POSTS
        $data = array();
        $current_time = current_time('timestamp', 1);
        $post_id = 0;

        $defaults = array('game_id' => 0,
            'title' => '',
            'team1' => 0,
            'team2' => 0,
            'scores' => array(),
            'match_status' => 0,
            'action' => '',
            'description' => '',
            'external_url' => '',
            'status' => '',
            'date' => array('mm' => date('m', $current_time),
                            'yy' => date('Y', $current_time),
                            'jj' => date('j', $current_time),
                            'hh' => date('H', $current_time),
                            'mn' => date('i', $current_time)),
            'reported_reason' => '',
            'locked' => 0);

        if($id > 0) {
            $t = $this->get_match(array('id' => $id));
            $keep_match = $t;

            if(!empty($t)){
                $data = (array)$t;

	            date_default_timezone_set(arcane_timezone_string());
                $data['id'] = $data['ID'];
                $data['date'] = strtotime($data['date']);
                $data['scores'] = array();

                $post_id = $data['id'];
                $rounds = $this->get_rounds($data['id']);

                $data['participants'] = get_post_meta($data['id'], 'tournament_participants', true);
                $data['tid'] = get_post_meta($data['id'], 'tournament_id', true);

                if (is_array($rounds)) {

                foreach($rounds as $round) {
                    $data['scores'][$round->group_n]['map_id'] = $round->map_id;
                    $data['scores'][$round->group_n]['round_id'][] = $round->id;
                    $data['scores'][$round->group_n]['team1'][] = $round->tickets1;
                    $data['scores'][$round->group_n]['team2'][] = $round->tickets2;
                }
              }
            }
        }

        $games = $this->get_game(array('id' => $this->acl_user_can('which_games'), 'orderby' => 'title', 'order' => 'asc'));
        $teams = $this->get_team('id=all&orderby=post_title&order=asc');

        extract($this->extract_args(stripslashes_deep($_POST), $this->extract_args($data, $defaults)));


        $date = $this->date_array2time_helper($date);

        if(isset($_GET['update'])) {
            $this->add_notice(esc_html__('Match is successfully updated.', 'arcane'), 'updated');
        }

        $this->print_notices();
        if (!isset($data['participants'])) {
            $data['participants'] = get_post_meta($post_id, 'tournament_participants', true);
        }

        ?>

            <div class="wrap wp-cw-matcheditor">

                <h2><?php echo esc_attr($page_title); ?>
                <?php if($post_id) : ?>
                <ul class="linkbar">
                    <li class="post-link"><a href="<?php echo esc_attr(get_permalink($post_id)); ?>" target="_blank" class="icon-link"><?php echo esc_attr($post_id); ?></a></li>
                    <li class="post-comments"><a href="<?php echo esc_url(get_comments_link($post_id)); ?>" target="_blank"><?php echo esc_attr(get_comments_number($post_id)); ?></a></li>
                </ul>
                <?php endif; ?>

                </h2>

                    <form name="match-editor" id="match-editor" method="post" action="" enctype="multipart/form-data">

                        <input type="hidden" name="action" value="<?php echo esc_attr($page_action); ?>" />
                        <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />

                        <?php wp_nonce_field($page_action); ?>

                        <table class="form-table">

                        <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="game_id"><?php esc_html_e('Game', 'arcane'); ?></label></th>
                            <td>
                                <?php if (isset($data['tid']) && ($data['tid'] > 0)) { echo '<div class="nestani">';} ?>
                                <select id="game_id" name="game_id" >
                                    <?php foreach($games as $item) : ?>
                                    <option value="<?php echo esc_attr($item->id); ?>"<?php selected($item->id, $game_id); ?>><?php echo esc_html($item->title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                               <?php if (isset($data['tid']) && ($data['tid'] > 0)) { echo '</div>';} ?>
                                <?php if (!isset($data['tid']) OR !($data['tid'] > 0)) { ?><p class="description"><?php esc_html_e('The scores will be removed on game change.', 'arcane'); ?></p><?php }
                                else { ?>
                                    <p class="description"><?php esc_html_e('Tournament games cannot have games changed.', 'arcane'); ?></p>
                                <?php }
                                ?>
                            </td>
                        </tr>

                        <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="title"><?php esc_html_e('Title', 'arcane'); ?></label></th>
                            <td>
                                <input name="title" id="title" type="text" value="<?php echo esc_attr($title); ?>" maxlength="200" autocomplete="off" aria-required="true" />
                            </td>
                        </tr>

                        <tr class="form-field">
                            <th scope="row" valign="top"><label for="description"><?php esc_html_e('Description', 'arcane'); ?></label></th>
                            <td>
                                <?php $settings = array( 'textarea_name' => 'description' );
                                wp_editor( $description, 'description', $settings ); ?>
                            </td>
                        </tr>
                        <?php
                         if (!(isset($data['tid']) AND ($data['tid'] > 0))) {
                            ?>
                        <tr class="form-field">
                            <th scope="row" valign="top"><label for="external_url"><?php esc_html_e('External URL', 'arcane'); ?></label></th>
                            <td>
                                <input type="text" name="external_url" id="external_url" value="<?php echo esc_attr($external_url); ?>" />

                                <p class="description"><?php esc_html_e('Enter league URL or external match URL.', 'arcane'); ?></p>
                            </td>
                        </tr>

                        <tr class="form-required">
                            <th scope="row" valign="top"><label for=""><?php esc_html_e('Match type', 'arcane'); ?></label></th>
                            <td>
                                <?php foreach($this->match_status as $index => $text) : ?>
                                <label for="match_status_<?php echo esc_attr($index); ?>"><input type="radio" value="<?php echo esc_attr($index); ?>" name="match_status" id="match_status_<?php echo esc_attr($index); ?>"<?php checked($index, $match_status, true); ?> /> <?php echo esc_attr($text); ?></label><br/>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php } ?>
                        <?php
                            if (!(isset($data['tid']) && ($data['tid'] > 0))) {

                        ?>
                         <tr class="form-required">
                            <th scope="row" valign="top"><label for=""><?php esc_html_e('Participant type', 'arcane'); ?></label></th>
                            <td>
                                <label for="match_type_game"><input type="radio" <?php if ($data['participants'] == 'team') { echo 'checked="checked"'; } ?> name="match_game_type" class='matchgametype' value='team'/><?php esc_html_e('Team match', 'arcane'); ?></label><br/>
                                <label for="match_type_game"><input type="radio" <?php if ($data['participants'] == 'user') { echo 'checked="checked"'; } ?> name="match_game_type" class='matchgametype' value='user'/><?php esc_html_e('Individual match', 'arcane'); ?></label><br/>
                            </td>
                        </tr>
                         <?php
                       } else {
                         echo '<input type="hidden" name="match_game_type" class="matchgametype" value="'.esc_attr($data['participants']).'" /><br/>';
                       }
                        ?>

                        <tr class="form-required">
                            <th scope="row" valign="top"><label for=""><?php esc_html_e('Date', 'arcane'); ?></label></th>
                            <td>
                                <?php $this->html_date_helper('date', $date); ?>
                            </td>
                        </tr>

                        <tr class="form-required">
                            <th scope="row" valign="top"></th>
                            <td>
                                <div class="match-results" id="matchsite">

                                    <div class="teams">
                                    <?php if (isset($data['tid']) AND ($data['tid'] > 0)) {
                                        //is a tournament game
                                        if ($data['participants'] != 'team') {
                                          $u1 = get_user_by('id', $team1);
                                          $u2 = get_user_by('id', $team2);
                                          echo esc_html__('Player', 'arcane')." 1: ".esc_attr($u1->display_name)."<br />";
                                          echo esc_html__('Player', 'arcane')." 2: ".esc_attr($u2->display_name)."<br />";
                                            $allusers = get_users();
                                        ?>
                                            <div id="userselector">
                                            <select name="team1-user" class="team-select-user">
                                            <?php foreach($allusers as $t) : ?>
                                                <option value="<?php echo esc_attr($t->ID); ?>"<?php selected($t->ID, $team1); ?>><?php echo esc_attr($t->display_name); ?></option>
                                            <?php endforeach; ?>
                                            </select>&nbsp;<?php esc_html_e('vs', 'arcane'); ?>&nbsp;
                                            <select name="team2-user" class="team-select-user">
                                            <?php foreach($allusers as $t) : ?>
                                                <option value="<?php echo esc_attr($t->ID); ?>"<?php selected($t->ID, $team2); ?>><?php echo esc_attr($t->display_name); ?></option>
                                            <?php endforeach; ?>
                                            </select>
                                            </div>
                                        <?php
                                        } else {
                                          $u1 = get_post($team1);
                                          $u2 =get_post($team2);
                                          echo esc_html__('Player', 'arcane')." 1: ".esc_attr($u1->post_title)."<br />";
                                          echo esc_html__('Player', 'arcane')." 2: ".esc_attr($u2->post_title)."<br />";
                                        ?>
                                            <div id="teamselector">
                                            <select name="team1-team" class="team-select-team">
                                            <?php foreach($teams as $t) : ?>
                                                <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $team1 > 0 ? ($t->ID == $team1) : $t->home_team, true); ?>><?php echo esc_attr($t->title); ?></option>
                                            <?php endforeach; ?>
                                            </select>&nbsp;<?php esc_html_e('vs', 'arcane'); ?>&nbsp;
                                            <select name="team2-team" class="team-select-team">
                                            <?php foreach($teams as $t) : ?>
                                                <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $t->ID==$team2, true); ?>><?php echo esc_attr($t->title); ?></option>
                                            <?php endforeach; ?>
                                            </select>
                                            </div>
                                        <?php

                                        }

                                        ?>

                                    <?php
                                    } else {

                                        //is not, first show team selector
                                        ?>

                                        <div id="teamselector">
                                        <select name="team1-team" class="team-select-team">

                                        <?php

                                        foreach($teams as $t) :
                                        ?>
                                            <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $t->ID==$team1, true); ?>><?php echo esc_attr($t->title); ?></option>
                                        <?php endforeach; ?>
                                        </select>&nbsp;<?php esc_html_e('vs', 'arcane'); ?>&nbsp;
                                        <select name="team2-team" class="team-select-team">
                                        <?php foreach($teams as $t) : ?>
                                            <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $t->ID==$team2, true); ?>><?php echo esc_attr($t->title); ?></option>
                                        <?php endforeach; ?>

                                        </select>
                                        </div>
                                       <!-- <?php
                                        $allusers = get_users();

                                        ?>
                                        <div id="userselector">
                                        <select name="team1-user" class="team-select-user">

                                        <?php foreach($allusers as $t) : ?>
                                            <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $team1 > 0 ? ($t->ID == $team1) : $t->home_team, true); ?>><?php echo esc_attr($t->display_name); ?></option>
                                        <?php endforeach; ?>
                                        </select>&nbsp;<?php esc_html_e('vs', 'arcane'); ?>&nbsp;
                                        <select name="team2-user" class="team-select-user">
                                        <?php foreach($allusers as $t) : ?>
                                            <option value="<?php echo esc_attr($t->ID); ?>"<?php selected(true, $t->ID==$team2, true); ?>><?php echo esc_attr($t->display_name); ?></option>
                                        <?php endforeach; ?>

                                        </select>
                                        </div> -->
                                    </div>

                                    <div class="team2-inline">
                                        <label for="new_team_title"><?php esc_html_e('or just type opponent team here:', 'arcane'); ?></label><br/>
                                        <input name="new_team_title" id="new_team_title" type="text" value="" maxlength="200" autocomplete="off" aria-required="true" />

                                    </div>
                                    <br class="clear"/>
                                        <?php
                                    }
                                    ?>


                                    <div id="mapsite"></div>

                                    <div class="add-map" id="wp-cw-addmap">
                                        <input type="button" class="button button-secondary" value="<?php esc_html_e('Add map', 'arcane'); ?>" />
                                    </div>

                                </div>
                            </td>
                        </tr>




                        <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="locked"><?php esc_html_e('Lock this match', 'arcane'); ?></label></th>
                            <td>
                                  <input type="checkbox" name="locked" id="locked" value="1" <?php if($locked == 1){ ?> checked <?php } ?> >
                                  <p class="description"><?php esc_html_e('Lock it. Prevent users from deleting/editing/flagging and submitting score.', 'arcane'); ?></p>
                            </td>
                        </tr>
                        <?php if(isset($keep_match->status) )$status = $keep_match->status; ?>

                        <?php if($status == 'submitted1' or $status == 'submitted2'){ ?>
                         <tr class="form-field form-required">
                            <th scope="row" valign="top"><label for="locked"><?php esc_html_e('Score submitted by', 'arcane'); ?></label></th>
                            <td>
                                 <p class="description"><?php if($status == 'submitted1'){
                                    $name = arcane_return_team_name_by_team_id($team1);
                                    echo esc_attr($name);
                                 }elseif($status == 'submitted2'){
                                    $name = arcane_return_team_name_by_team_id($team2);
                                    echo esc_attr($name);
                                 }   ?></p>
                            </td>
                        </tr>
                        <?php } ?>

                        <?php if($status == 'reported1' or $status == 'reported2'){ ?>
                            <tr class="form-field">
                             <th scope="row" valign="top"><label for=""><?php esc_html_e('Reported by', 'arcane'); ?></label></th>
                              <td>
                                <?php if($status == 'reported1'){
                                    $name = arcane_return_team_name_by_team_id($team1);
                                    echo esc_attr($name);
                                }elseif($status == 'reported2'){
                                    $name = arcane_return_team_name_by_team_id($team2);
                                    echo esc_attr($name);
                                } ?>
                              </td>
                            </tr>
                            <tr class="form-field">
                                <th scope="row" valign="top"><label for=""><?php esc_html_e('Reason for report', 'arcane'); ?></label></th>
                                <td>
                                    <textarea name="reason" id="reason" value="<?php echo esc_attr($reported_reason); ?>" cols="25" rows="10" autocomplete="off" aria-required="true" ><?php echo esc_attr($reported_reason); ?></textarea>
                                </td>
                            </tr>
                        <?php } ?>
                        </table>

                        <p class="submit"><input type="submit" class="button-primary" id="wp-cw-submit" name="submit" value="<?php echo esc_attr($page_submit); ?>" />

                        <?php if($status == 'reported1' or $status == 'reported2'){ ?>

                        <input type="submit" class="button-primary" id="wp-cw-submit-resolve" name="submit-resolve" value="<?php esc_html_e('Resolve', 'arcane'); ?>" />

                        <?php } ?>

                        </p>
                    </form>

            </div>

        <?php
    }

    function quick_pick_team($title) {
        $team = $this->get_team(array('title' => $title, 'limit' => 1));
        $team_id = 0;
        if(empty($team)) {
            $new_team_id = $this->add_team(array('title' => $title));
            if($new_team_id !== false)
                $team_id = $new_team_id;
        } else {
            $team_id = $team[0]->id;
        }

        return $team_id;
    }

    function on_load_manage_matches()
    {

        $id = isset($_REQUEST['id']) ? $_GET['id'] : 0;
        $act = isset($_GET['act']) ? $_GET['act'] : '';


        // Check match is really exists
        if($act == 'edit') {
            $m = $this->get_match(array('id' => $id));
            if($id != 0 && empty($m))
                wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

            if(!$this->acl_user_can('manage_game', $m->game_id))
                wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );
        }

        if(sizeof($_POST) > 0)
        {


            if(isset($_POST['game_id']) && !$this->acl_user_can('manage_game', $_POST['game_id']))
                wp_die( esc_html__('Cheatin&#8217; uh?', 'arcane') );

            switch($act) {

                case 'add':

                    extract($this->extract_args(stripslashes_deep($_POST), array(
                        'game_id' => 0,
                        'title' => '',
                        'description' => '',
                        'external_url' => '',
                        'date' => current_time('timestamp', 1),
                        'team1' => 0,
                        'team2' => 0,
                        'scores' => array(),
                        'new_team_title' => '',
                        'match_status' => 0,
                        'locked' => 0
                        )));

                    $date = $this->date_array2time_helper($date);

                    if(!empty($new_team_title)) {
                        $pickteam = $this->quick_pick_team($new_team_title);

                        if($pickteam > 0)
                            $team2 = $pickteam;
                    }
                    if ($_POST['match_game_type'] == 'team' or $_POST['match_game_type'] === NULL)  {
                        $team1 = $_POST['team1-team'];
                        $team2 = $_POST['team2-team'];
                    } else {
                        $team1 = $_POST['team1-user'];
                        $team2 = $_POST['team2-user'];
                    }
                    $match_id = $this->add_match(array(
                            'title' => $title,
                            'date' => date('Y-m-d H:i:s', $date),
                            'date_unix' => $date,
                            'post_id' => 0,
                            'team1' => $team1,
                            'team2' => $team2,
                            'game_id' => $game_id,
                            'match_status' => $match_status,
                            'description' => $description,
                            'locked' => $locked
                    ));

                    if($match_id) {

                        foreach($scores as $round_group => $r) {
                            for($i = 0; $i < sizeof($r['team1']); $i++) {
                                $this->add_round(array('match_id' => $match_id,
                                    'group_n' => abs($round_group),
                                    'map_id' => $r['map_id'],
                                    'tickets1' => $r['team1'][$i],
                                    'tickets2' => $r['team2'][$i]
                                    ));
                            }
                        }


                        if ($_POST['match_game_type'] == 'team' or $_POST['match_game_type'] === NULL)  {

                            $this->update_match_post($match_id, 0, 'team');
                        } else {
                            $this->update_match_post($match_id, 0, 'user');
                        }
                        wp_redirect(admin_url('admin.php?page=wp-teamwars-matches&add=1'), $status = 302);
                        exit();
                    } else
                        $this->add_notice(esc_html__('An error occurred.', 'arcane'), 'error');

                break;

            case 'edit':

                    $pid = $_POST['id'];

                    $tuid =  get_post_meta($pid, 'tournament_id', true);

                    $tournament_timezone = get_post_meta($tuid, 'tournament_timezone' , true);
                    if(!$tournament_timezone) {
                        $timezone_string = arcane_timezone_string();
                        $tournament_timezone = $timezone_string ? $timezone_string : 'UTC';
                    }
                    date_default_timezone_set($tournament_timezone);

                    extract($this->extract_args(stripslashes_deep($_POST), array(
                        'id' => 0,
                        'game_id' => 0,
                        'title' => '',
                        'description' => '',
                        'external_url' => '',
                        'date' => current_time('timestamp', 1),
                        'team1' => 0,
                        'team2' => 0,
                        'new_team_title' => '',
                        'match_status' => 0,
                        'scores' => array(),
                        'locked' => 0
                        )));


                    update_post_meta($pid, 'tournament_participants', $_POST['match_game_type']);
                    if (($_POST['match_game_type'] == 'team') or !isset($_POST['match_game_type']) )  {
                        $team1 = $_POST['team1-team'];
                        $team2 = $_POST['team2-team'];
                    } else {
                        $team1 = $_POST['team1-user'];
                        $team2 = $_POST['team2-user'];
                    }

                    if(isset($_POST) && $_POST['submit-resolve'] == 'Resolve'){
                       $this->update_match($id, array(
                            'title' => $title,
                            'date' => date('Y-m-d H:i:s', strtotime(mktime($date['hh'], $date['mn'], 0, $date['mm'], $date['jj'], $date['yy'])) ),
                            'date_unix' => mktime($date['hh'], $date['mn'], 0, $date['mm'], $date['jj'], $date['yy']),
                            'team1' => $team1,
                            'team2' => $team2,
                            'game_id' => $game_id,
                            'match_status' => $match_status,
                            'description' => $description,
                            'external_url' => $external_url,
                            'status' => 'active',
                            'locked' => $locked

                        ));

                    }else{
                        $datum = date('Y-m-d H:i:s', mktime($date['hh'], $date['mn'], 0, $date['mm'], $date['jj'], $date['yy']) );
                        $this->update_match($id, array(
                            'title' => $title,
                            'date' => $datum,
                            'date_unix' => mktime($date['hh'], $date['mn'], 0, $date['mm'], $date['jj'], $date['yy']),
                            'team1' => $team1,
                            'team2' => $team2,
                            'game_id' => $game_id,
                            'match_status' => $match_status,
                            'description' => $description,
                            'external_url' => $external_url,
                            'locked' => $locked

                        ));

                    }
                    $date = $this->date_array2time_helper($date);

                    if(!empty($new_team_title)) {
                        $pickteam = $this->quick_pick_team($new_team_title);

                        if($pickteam > 0)
                            $team2 = $pickteam;
                    }


                    $rounds_not_in = array();

                    foreach($scores as $round_group => $r) {
                        for($i = 0; $i < sizeof($r['team1']); $i++) {
                            $round_id = $r['round_id'][$i];
                            $round_data = array('match_id' => $id,
                                    'group_n' => abs($round_group),
                                    'map_id' => $r['map_id'],
                                    'tickets1' => $r['team1'][$i],
                                    'tickets2' => $r['team2'][$i]
                                    );

                            if($round_id > 0) {
                                $this->update_round($round_id, $round_data);
                                $rounds_not_in[] = $round_id;
                            } else {
                                $new_round = $this->add_round($round_data);
                                if($new_round !== false)
                                    $rounds_not_in[] = $new_round;
                            }
                        }
                    }

	                $tid = get_post_meta($pid, 'tournament_id', true);

                    $this->delete_rounds_not_in($id, $rounds_not_in);

                    $this->update_match_post($id,$tid);

                    $match = $this->get_match(array('id' => $id, 'sum_tickets' => true));



                    $tournament = new arcane_tournaments();
                    if ($tournament->LoadTournament($tid)) {
                      if ( (isset($match->status)) AND (!empty($match->status)) AND (strtolower($match->status) == "done")) {
                        $newdata['tid'] = $tid;
                        $newdata['match_postid'] = $pid;
                        $newdata['match_id'] = $id;
                        $newdata['team1_score'] = $match->team1_tickets;
                        $newdata['team2_score'] = $match->team2_tickets;
                        if ($match->team1_tickets > $match->team2_tickets) {
                            $newdata['winner'] = 'team1';
                        } elseif ($match->team1_tickets == $match->team2_tickets) {
                            $newdata['winner'] = 'draw';
                        } else {
                            //$t2 > $t1
                           $newdata['winner'] = 'team2';
                        }
                        $tournament->tournament->OnResultSubmit($newdata);
                      }
                    }

                    wp_redirect(admin_url('admin.php?page=wp-teamwars-matches&act=edit&id=' . $id . '&update=1'), $status = 302);
                    exit();

                break;
            }
        }
    }

    function on_shortcode($atts) {

        $output = '';

        extract(shortcode_atts(array('per_page' => 20), $atts));

        $per_page = abs($per_page);
        $current_page = get_query_var('paged');
        $now = current_time('timestamp', 1);
        $current_game = isset($_GET['game']) ? $_GET['game'] : false;

        $games = $this->get_game('id=all&orderby=title&order=asc');

        if($current_page < 1)
            $current_page = 1;

        $p = array(
            'limit' => $per_page,
            'sum_tickets' => true,
            'game_id' => $current_game,
            'offset' => ($current_page-1) * $per_page,
            'status' => array('active', 'done')
        );

        $matches = $this->get_match($p, false, true);

        $stat = $this->get_match($p, true, true);

        $page_links = paginate_links( array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => esc_html__('&laquo;', 'arcane'),
                'next_text' => esc_html__('&raquo;', 'arcane'),
                'total' => $stat['total_pages'],
                'current' => $current_page
        ));

        $page_links_text = sprintf( '<span class="displaying-num">' . esc_html__( 'Displaying %s&#8211;%s of %s', 'arcane' ) . '</span>%s',
                number_format_i18n( (($current_page - 1) * $per_page) + 1 ),
                number_format_i18n( min( $current_page * $per_page, $stat['total_items'] ) ),
                '<span class="total-type-count">' . number_format_i18n( $stat['total_items'] ) . '</span>',
                $page_links
        );


        $output .= '<div class="teamwarlist-page widget">';
        $output .= '<ul class="teamwar-list">';
        $output .= '<li><ul class="tabs">';

        if(count($games) > 1){
        $obj = new stdClass();
        $obj->id = 0;
        $obj->title = esc_html__('All', 'arcane');
        $obj->abbr = esc_html__('All', 'arcane');
        $obj->icon = 0;

        array_unshift($games, $obj);
          }

        for($i = 0; $i < sizeof($games); $i++) :
            $game = $games[$i];
            $link = ($game->id == 0) ? 'all' : 'game-' . $game->id;
         if($i == 0){$class = 'selected';}else{$class = '';}
         $p = array( 'game_id' => $game->id,'status' => array('active', 'done'));
         $matches_tab = $this->get_match($p, false);
          if(!empty($matches_tab)){
        $output .= '<li class="'.$class.'"><a href="#'.$link.'" title="'.esc_attr($game->title).'">'.esc_html($game->abbr).'</a></li>';
          }
         endfor;
      $output .= '</ul> <div class="clear"></div>
    </li>';
        // generate table content
        $j=0;
        foreach($matches as $index => $match) {
            if($match->status == 'active' || $match->status == 'done'){
            if($i % 2 != 0) $alt = ' alt';
            $output .= '<li title="' . esc_attr($match->title) . '" class="teamwar-item '.$alt.' match ' . ' game-'.$match->game_id.' ">';

            // output match status
            $is_upcoming = false;
            $t1 = $match->team1_tickets;
            $t2 = $match->team2_tickets;

            if(isset($match->date_unix) && !empty($match->date_unix)){
               $date = date_i18n(get_option('date_format') . ', ' . get_option('time_format'), $match->date_unix);
               $timestamp = $match->date_unix;
            }else{
               $date = mysql2date(get_option('date_format') . ', ' . get_option('time_format'), $match->date);
               $timestamp = mysql2date('U', $match->date);
            }

            global $wpdb;
            $table = array(
                'teams' => 'cw_teams',
                'games' => 'cw_games',
            );
            $table = array_map('arcane_table_prefix', $table);
            $team1id = $match->team1;
            $team2id = $match->team2;

            if ($match->tournament_participants == "team" or $match->tournament_participants == NULL) {
              //teamtype
              $img_url1 = arcane_return_team_image($match->team1);
              $image1 = arcane_aq_resize( $img_url1, 50, 50, true, true, true ); //resize & crop img
              $match->team1_title = get_the_title($match->team1);


              $img_url2 = arcane_return_team_image($match->team2);
              $image2 = arcane_aq_resize( $img_url2, 50, 50, true, true, true ); //resize & crop img
              $match->team2_title = get_the_title($match->team2);
            } else {
              $img_url1 = get_user_meta($match->team1, 'profile_photo', true);
              if (!empty($img_url1)) {
                $image1 = arcane_aq_resize( $img_url1, 50, 50, true, true, true ); //resize & crop img
              }
              $match->team1_title = get_the_author_meta("display_name", $match->team1);

              $img_url2 = get_user_meta($match->team2, 'profile_photo', true);
              if (!empty($img_url2)) {
                $image2 = arcane_aq_resize( $img_url2, 50, 50, true, true, true ); //resize & crop img
              }
              $match->team2_title = get_the_author_meta("display_name", $match->team2);
            }

            $is_upcoming = $timestamp > $now;
            $is_playing = ( ($now > $timestamp && $now < $timestamp + 3600) && ($t1 == 0 && $t2 == 0) || ($match->status == 'active') && ($t1 == 0 && $t2 == 0) );

            // output game icon
            $game_icon = wp_get_attachment_url($match->game_icon);

            // teams
            $output .= '<div class="wrap"><a href="' . get_permalink($match->ID) . '" data-toggle="tooltip" data-original-title="' . esc_attr($match->title) . '">';
            if($is_upcoming) :
                $output .= '<div class="upcoming">' . esc_html__('Upcoming', 'arcane') . '</div>';
            elseif($is_playing) :
                $output .= '<div class="playing">' . esc_html__('Playing', 'arcane') . '</div>';
            else :
                $output .= '<div class="scores">' . sprintf(esc_html__('%1$d:%2$d', 'arcane'), $t1, $t2) . '</div>';
            endif;

            $output .= '<div class="match-wrap">';

            if(!isset($image1) or empty($image1)){ $image1 = get_template_directory_uri().'/img/defaults/default-team-50x50.jpg';  }
            if(!isset($image2) or empty($image2)){ $image2 = get_template_directory_uri().'/img/defaults/default-team-50x50.jpg';  }

            $output .= '<img src="'.$image1.'" class="team1img" alt="'.esc_html($match->team2_title).'">';
            $output .= '<div class="vs">'.esc_html__("VS", "arcane").'</div><div class="opponent-team"><img src="'.$image2.'" class="team1img" alt="'.esc_html($match->team2_title).'"></div>';
            $team2_title = esc_html($match->team2_title);
            $output .= '<div class="clear"></div>';

            $output .= '</div>';

            $output .= '<div class="date"><strong>'  . esc_attr($match->title) . '</strong>'. esc_html($date)  . '</div>';
            $output .= '<div class="clear"></div>';


            $output .= '</a>';
            $output .= '</div>';
            $output .= '</li>';
        $j++;
        }}

        $output .= '</ul>';
        $output .= '</div>';
        $output .= '<div class="wp-teamwars-pagination clearfix">' .$page_links_text . '</div>';

        return $output;

    }

    function on_manage_matches()
    {
      //tusi
        $act = isset($_GET['act']) ? $_GET['act'] : '';
        $current_page = isset($_GET['paged']) ? $_GET['paged'] : 1;
        $limit = 10;
        $game_filter = $this->acl_user_can('which_games');
        $arcane_allowed = wp_kses_allowed_html( 'post' );
        if(isset($_GET['s']))
        $search = $_GET['s'];
        if (isset($search)) {
          $search = urldecode($search);
          $search = sanitize_text_field($search);
        } else {
          $search = "";
        }
        switch($act) {
            case 'add':
                return $this->on_add_match();
                break;
            case 'edit':
                return $this->on_edit_match();
                break;
        }

        $stat_condition = array(
            'id' => 'all',
            'game_id' => $game_filter,
            'limit' => $limit
        );

        $condition = array(
            'id' => 'all', 'game_id' => $game_filter, 'sum_tickets' => true,
            'orderby' => 'id', 'order' => 'desc', 'search' =>$search,
            'limit' => $limit, 'offset' => ($limit * ($current_page-1))

        );

        $matches = $this->get_match($condition);
        $stat = $this->get_match($stat_condition, true);
        if (!empty($search)) {

          $filtered = array();
          foreach ($matches as $match) {

            if (strpos (strtolower($match->post_title), strtolower($search)) !== false) {
              $filtered[] = $match;
            }
          }
          $matches = $filtered;
          $stat['total_items'] = count($matches);
          if($limit > 0)
              $stat['total_pages'] = ceil($stat['total_items'] / $limit);
        }


        $page_links = paginate_links( array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => esc_html__('&laquo;', 'arcane'),
                'next_text' => esc_html__('&raquo;', 'arcane'),
                'total' => $stat['total_pages'],
                'current' => $current_page
        ));

        $page_links_text = sprintf( '<span class="displaying-num">' . esc_html__( 'Displaying %s&#8211;%s of %s', 'arcane' ) . '</span>%s',
                number_format_i18n( (($current_page - 1) * $limit) + 1 ),
                number_format_i18n( min( $current_page * $limit, $stat['total_items'] ) ),
                '<span class="total-type-count">' . number_format_i18n( $stat['total_items'] ) . '</span>',
                $page_links
        );

        $table_columns = array('cb' => '<input type="checkbox" />',
                      'title' => esc_html__('Title', 'arcane'),
                      'game_title' => esc_html__('Game', 'arcane'),
                      'date' => esc_html__('Date', 'arcane'),
                      'match_status' => esc_html__('Match status', 'arcane'),
                      'team1' => esc_html__('Team 1', 'arcane'),
                      'team2' => esc_html__('Team 2', 'arcane'),
                      'tickets' => esc_html__('Tickets', 'arcane'),
                      'reported' => esc_html__('Reported', 'arcane'),
                      'locked' => esc_html__('Locked', 'arcane'),
                      'submitted' => esc_html__('Score submitted by', 'arcane'));

        if(isset($_GET['add'])) {
            $this->add_notice(esc_html__('Match is successfully added.', 'arcane'), 'updated');
        }
		//pulze
        if(isset($_GET['locked'])) {
            $this->add_notice(esc_html__('Match is successfully locked.', 'arcane'), 'updated');
        }

        if(isset($_GET['delete'])) {
            $deleted = (int)$_GET['delete'];
            $this->add_notice(sprintf(_n('%d Match deleted.', '%d Matches deleted', $deleted, 'arcane'), $deleted), 'updated');
        }
		if(isset($_GET['lock'])) {
            $deleted = (int)$_GET['lock'];
            $this->add_notice(sprintf(_n('%d Match locked.', '%d Matches locked', $deleted, 'arcane'), $deleted), 'updated');
        }
        if(isset($_GET['unlock'])) {
            $deleted = (int)$_GET['unlock'];
            $this->add_notice(sprintf(_n('%d Match unlocked.', '%d Matches unlocked', $deleted, 'arcane'), $deleted), 'updated');
        }

        $this->print_notices();

    ?>
        <div class="wrap wp-cw-matches">
            <h2><?php esc_html_e('Matches', 'arcane'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-matches&act=add')); ?>" class="add-new-h2"><?php esc_html_e('Add New', 'arcane'); ?></a></h2>

            <div id="poststuff" class="metabox-holder">

                <div id="post-body">
                    <div id="post-body-content" class="has-sidebar-content">

                    <form id="posts-filter" >
                         <div class="alignright actions">
                                <label class="screen-reader-text" for="matches-search-input"><?php esc_html_e('Search Matches:', 'arcane'); ?></label>
                                <input id="matches-search-input" name="s" value="<?php if(isset($search)) echo esc_html($search); ?>" type="text" onkeypress="GoSubmit(event);" />

                                <input id="matches-search-submit" value="<?php esc_html_e('Search Matches', 'arcane'); ?>" class="button" type="button" />
                                <input name="post_type" class="post_type_matches" value="matches" type="hidden">

                            </div>
                    </form>
                    <form id="wp-teamwars-manageform" action="admin-post.php" method="post">
                        <?php wp_nonce_field('wp-teamwars-deletematches'); ?>

                        <input type="hidden" name="action" value="wp-teamwars-deletematches" />

                        <div class="tablenav">

                            <div class="alignleft actions">
                                <select name="do_action">
                                    <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
									<option value="lock"><?php esc_html_e('Lock', 'arcane'); ?></option>
                                    <option value="unlock"><?php esc_html_e('Unlock', 'arcane'); ?></option>
                                </select>
                                <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction" id="wp-teamwars-doaction" class="button-secondary action" />
                            </div>



                        <br class="clear" />

                        </div>

                        <div class="clear"></div>

                        <table class="widefat fixed" cellspacing="0">
                        <thead>
                        <tr>
                        <?php $this->print_table_header($table_columns); ?>
                        </tr>
                        </thead>

                        <tfoot>
                        <tr>
                        <?php $this->print_table_header($table_columns, false); ?>
                        </tr>
                        </tfoot>

                        <tbody>

                        <?php foreach($matches as $i => $item) : ?>

                            <?php
                            // if the match has no title so set default one

                            $item->title = $item->post_title;
                            $tparticipants = $item->tournament_participants;

                            $tid = 0;
                            if(isset($item->tournament_id))
                            $tid = $item->tournament_id;


                            if ( strpos( strtolower( $tparticipants ), 'user' ) === false ) {
                                $is_user_type = false;
                                if ( isset( $item->team1_title ) && strlen( $item->team1_title ) < 3 ) {
                                    if ( $item->team1 > 0 ) {
                                        $item->team1_title = get_the_title( $item->team1 );
                                        $item->team1_logo  = esc_url( arcane_return_team_image_big( $item->team1 ) );
                                    }
                                }
                                if ( isset( $item->team2_title ) && strlen( $item->team2_title ) < 3 ) {
                                    if ( $item->team2 > 0 ) {
                                        $item->team2_title = get_the_title( $item->team2 );
                                        $item->team2_logo  = esc_url( arcane_return_team_image_big( $item->team2 ) );
                                    }
                                }
                            } else {
                                $is_user_type = true;
                            }


                            if(empty($item->post_title))
                                $item->post_title = esc_html__('Regular match', 'arcane');


                            $requesturi = admin_url('admin.php?page=wp-teamwars-matches');
                            if (filter_has_var(INPUT_SERVER, "REQUEST_URI")) {
                                $requesturi = filter_input(INPUT_SERVER, "REQUEST_URI", FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
                            }

                            ?>

                            <tr class="iedit<?php if($i % 2 == 0) echo ' alternate'; ?>">
                                <th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="<?php echo esc_attr($item->ID); ?>" /></th>
                                <td class="title column-title">
                                    <a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-matches&amp;act=edit&amp;id=' . $item->ID)); ?>" title="<?php echo sprintf(esc_html__('Edit &#8220;%s&#8221; Match', 'arcane'), esc_attr($item->post_title)); ?>"><?php echo esc_html($item->post_title); ?></a><br />
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-teamwars-matches&amp;act=edit&amp;id=' . $item->ID)); ?>"><?php esc_html_e('Edit', 'arcane'); ?></a></span> | <span class="delete">
                                                <a href="<?php echo wp_nonce_url('admin-post.php?action=wp-teamwars-deletematches&amp;do_action=delete&amp;delete[]=' . $item->ID . '&amp;_wp_http_referer=' . urlencode($requesturi), 'wp-teamwars-deletematches'); ?>"><?php esc_html_e('Delete', 'arcane'); ?></a></span>
                                    </div>
                                </td>
                                <td class="game_title column-game_title">
                                    <?php
                                    $game_icon = '';
                                    if(isset($item->game_id))
                                    $game_icon = arcane_return_game_image($item->game_id);
                                    if($game_icon !== false) :
                                    ?>
                                    <img src="<?php echo esc_url($game_icon); ?>" alt="<?php if(isset($item->game_title))echo esc_attr($item->game_title); ?>" class="icon" />
                                    <?php endif; ?>

                                </td>
                                <td class="date column-date">
                                    <?php
                                     if(isset($item->date_unix) && !empty($item->date_unix)){
                                       $date = date_i18n(get_option('date_format') . ', ' . get_option('time_format'), $item->date_unix);

                                    }else{
                                       $date = mysql2date(get_option('date_format') . ', ' . get_option('time_format'), $item->date);

                                    }
                                    echo esc_attr($date); ?>
                                </td>
                                <td class="match_status column-match_status">
                                    <?php
                                    $n = $item->match_status;

                                    if(isset($this->match_status[$n]) && $tid == 0)
                                        echo esc_attr($this->match_status[$n]);
                                    ?>
                                </td>
                                <td class="team1 column-team1">
                                    <?php
                                    if ($is_user_type == false) {
                                        if(isset($item->team1))
                                        echo esc_html(arcane_return_team_name_by_team_id($item->team1));
                                    } else {
                                        $user1 = get_user_by('id', $item->team1);
                                        if(isset($user1->display_name))
                                        echo esc_html($user1->display_name);
                                    }


                                    ?>
                                </td>
                                <td class="team2 column-team2">
                                    <?php
                                    if ($is_user_type == false) {
                                        if(isset($item->team2))
                                        echo esc_html(arcane_return_team_name_by_team_id($item->team2));
                                    } else {
                                        $user2 = get_user_by('id', $item->team2);
                                        if(isset($user2->display_name))
                                        echo esc_html($user2->display_name);
                                    } ?>
                                </td>
                                <td class="tickets column-tickets">
                                    <?php if(!isset($item->team1_tickets)) $item->team1_tickets = 0; ?>
                                    <?php if(!isset($item->team2_tickets)) $item->team2_tickets = 0; ?>
                                    <?php echo sprintf('%s:%s', $item->team1_tickets, $item->team2_tickets); ?>
                                </td>
                                <td class="reported column-reported">
                                    <?php if($item->status == 'reported1' or $item->status == 'reported2'){ ?><i class="fas fa-exclamation-triangle"></i>&nbsp;<?php esc_html_e('Reported','arcane'); ?><?php } ?>
                            </td>
                                <td class="match_status column-match_status">
                                    <?php if($item->locked == 1){ esc_html_e('Yes', 'arcane');}else{esc_html_e('No', 'arcane'); } ?>
                                </td>

                                <td class="match_status column-match_status">

                                    <?php if($item->status == 'submitted1'){
                                            if ($is_user_type == false) {
                                                if(isset($item->team1))
                                                    echo esc_html(arcane_return_team_name_by_team_id($item->team1));
                                            } else {
                                                $user1 = get_user_by('id', $item->team1);
                                                if(isset($user1->display_name))
                                                    echo esc_html($user1->display_name);
                                            }

                                        }elseif($item->status == 'submitted2'){
                                            if ($is_user_type == false) {
                                                if(isset($item->team2))
                                                    echo esc_html(arcane_return_team_name_by_team_id($item->team2));
                                            } else {
	                                            $user2 = get_user_by('id', $item->team2);
                                                if(isset($user2->display_name))
                                                    echo esc_html($user2->display_name);
                                            }
                                        } ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                        </table>

                        <div class="tablenav">

                            <div class="tablenav-pages"><?php echo wp_kses($page_links_text,$arcane_allowed); ?></div>

                            <div class="alignleft actions">
                            <select name="do_action2">
                            <option value="" selected="selected"><?php esc_html_e('Bulk Actions', 'arcane'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                            </select>
                            <input type="submit" value="<?php esc_html_e('Apply', 'arcane'); ?>" name="doaction2" id="wp-teamwars-doaction2" class="button-secondary action" />
                            </div>

                            <br class="clear" />

                        </div>

                    </form>

                    </div>
                </div>
                <br class="clear"/>

            </div>
        </div>
    <?php
    }

    function on_admin_post_settings() {
        global $wpdb;

        if(!current_user_can('manage_options'))
            wp_die(esc_html__('Cheatin&#8217; uh?', 'arcane'));

        check_admin_referer('wp-teamwars-settings');

        if(isset($_POST['category']))
            update_option(ARCANE_TEAMWARS_CATEGORY, (int)$_POST['category']);

        update_option(ARCANE_TEAMWARS_DEFAULTCSS, isset($_POST['enable_default_styles']));

        $url = add_query_arg('saved', 'true', $_POST['_wp_http_referer']);

        wp_redirect($url, $status = 302);
    }

    function on_admin_post_acl() {
        global $wpdb;

        if(!current_user_can('manage_options'))
            wp_die(esc_html__('Cheatin&#8217; uh?', 'arcane'));

        check_admin_referer('wp-teamwars-acl');

        if(isset($_POST['user'])) {
            $user_id = (int)$_POST['user'];
            $data = array();

            if(isset($_POST['permissions']))
                $data['permissions'] = $_POST['permissions'];

            if(isset($_POST['games']))
                $data['games'] = $_POST['games'];

            $this->acl_update($user_id, $data);
        }

        $url = add_query_arg('saved', 'true', $_POST['_wp_http_referer']);

        wp_redirect($url, $status = 302);
    } 

    function on_admin_post_deleteacl() {
        global $wpdb;

        if(!current_user_can('manage_options'))
            wp_die(esc_html__('Cheatin&#8217; uh?', 'arcane'));

        check_admin_referer('wp-teamwars-deleteacl');

        extract($this->extract_args($_POST, array(
            'doaction' => '', 'doaction2' => '',
            'users' => array()
            )));

        $url = $_POST['_wp_http_referer'];

        if($doaction == 'delete' || $doaction2 == 'delete') {

            $users = array_unique(array_values($users));

            foreach($users as $key => $user_id)
                $this->acl_delete($user_id);

            $url = add_query_arg('saved', 'true', $url);
        }

        wp_redirect($url, $status = 302);
    }

    function on_admin_post_import() {
        if(!current_user_can('manage_options'))
            wp_die(esc_html__('Cheatin&#8217; uh?', 'arcane'));

        check_admin_referer('wp-teamwars-import');

        extract($this->extract_args($_POST, array('import' => '', 'items' => array())));

        $url = remove_query_arg(array('upload', 'import'), $_POST['_wp_http_referer']);

        switch($import) {
            case 'upload':
                if(isset($_FILES['userfile'])) {
                    $file = $_FILES['userfile'];

                    if($file['error'] == 0) {
                        $content = $this->_get_file_content($file['tmp_name']);

                        $result = $this->import_games($content);
                        $url = add_query_arg('import', $result, $url);
                    } else {
                        $url = add_query_arg('upload', 'error', $url);
                    }
                }
                break;

            case 'available':

                $available_games = $this->get_available_games();

                foreach($items as $item) {
                    if(isset($available_games[$item])) {
                        $r = $available_games[$item];

                        $content = $this->_get_file_content(trailingslashit(get_theme_file_path('addons/team-wars/import')) . $r->package);
                      $this->import_games($content);
                    }
                }

                $url = add_query_arg('import', true, $url);

                break;
        }

        wp_redirect($url, $status = 302);
    }

    function on_settings() {

        $table_columns = array('cb' => '<input type="checkbox" />',
                      'user_login' => esc_html__('User Login', 'arcane'),
                      'user_permissions' => esc_html__('Permissions', 'arcane')
                );

        $games = $this->get_game('id=all');
        if(count($games) > 1){
        $obj = new stdClass();
        $obj->id = 0;
        $obj->title = esc_html__('All', 'arcane');
        $obj->abbr = esc_html__('All', 'arcane');
        $obj->icon = 0;

        array_unshift($games, $obj);
        }

    ?>
    <div class="wrap wp-cw-settings">

        <h2><?php esc_html_e('Settings', 'arcane'); ?></h2>

        <?php if(isset($_GET['saved'])) : ?>
        <div class="updated fade"><p><?php esc_html_e('Settings saved.', 'arcane'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="admin-post.php">
            <?php wp_nonce_field('wp-teamwars-settings'); ?>
            <input type="hidden" name="action" value="wp-teamwars-settings" />

             <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Matches Category', 'arcane'); ?></th>
                    <td>
                        <?php

                        $selected = get_option(ARCANE_TEAMWARS_CATEGORY, -1);

                        wp_dropdown_categories(
                                array('name' => 'category',
                                      'hierarchical' => true,
                                      'show_option_none' => esc_html__('None', 'arcane'),
                                      'hide_empty' => 0,
                                      'hide_if_empty' => 0,
                                      'selected' => $selected)
                                );

                        ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('Enable default styles', 'arcane'); ?></th>
                    <td><input type="checkbox" name="enable_default_styles" value="true"<?php checked(get_option(ARCANE_TEAMWARS_DEFAULTCSS), true); ?> /></td>
                </tr>
             </table>

            <p class="submit">
                <input class="button-secondary" value="<?php esc_html_e('Save Changes', 'arcane'); ?>" type="submit" />
            </p>

        </form>

        <h2><?php esc_html_e('User Access', 'arcane'); ?></h2>

        <div id="col-container">

            <div id="col-right">
            <div class="col-wrap">

            <form method="post" action="admin-post.php">
                <?php wp_nonce_field('wp-teamwars-deleteacl'); ?>
                <input type="hidden" name="action" value="wp-teamwars-deleteacl" />

                <div class="tablenav">
                    <div class="alignleft actions">
                    <select name="doaction">
                        <option value="" selected="selected"><?php esc_html_e('Actions', 'arcane'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                    </select>
                    <input value="<?php esc_html_e('Apply', 'arcane'); ?>" class="button-secondary action" type="submit" />
                    </div>
                    <br class="clear" />
                </div>


                <table class="widefat fixed" cellspacing="0">
                    <thead>
                    <tr>
                        <?php $this->print_table_header($table_columns); ?>
                    </tr>
                    </thead>

                    <tfoot>
                    <tr>
                        <?php $this->print_table_header($table_columns, false); ?>
                    </tr>
                    </tfoot>

                    <tbody>
                        <?php

                        $acl = $this->acl_get();

                        $keys = array_keys($acl);

                        for($i = 0; $i < sizeof($keys); $i++) :

                            $user_id = $keys[$i];
                            $user_acl = $acl[$user_id];
                            $user = get_userdata($user_id);

                        ?>

                        <tr<?php if($i % 2 == 0) : ?> class="alternate"<?php endif; ?>>
                            <th class="check-column"><input type="checkbox" class="check" name="users[]" value="<?php echo esc_attr($user_id); ?>" /></th>
                            <td><?php echo esc_attr($user->user_login); ?></td>
                            <td>
                                <?php foreach($user_acl['permissions'] as $name => $is_allowed) : ?>
                                <ul>
                                    <li><?php echo esc_attr($this->acl_keys[$name]); ?>: <?php echo (esc_html($is_allowed)) ? esc_html__('Yes', 'arcane') : esc_html__('No', 'arcane'); ?></li>
                                </ul>
                                <?php endforeach; ?>

                                <?php
                                    $allowed_games = $this->acl_user_can('which_games', false, $user_id);
                                    $user_games = $this->get_game(array('id' => $allowed_games, 'orderby' => 'title', 'order' => 'asc'));

                                    if($allowed_games == 'all') {
                                        echo esc_html__('All', 'arcane');
                                    }
                                ?>

                                    <?php foreach($user_games as $game) :

                                        $game_icon = wp_get_attachment_url($game->icon);

                                        if($game_icon !== false) {
                                            echo '<img src="' . $game_icon . '" alt="' . esc_attr($game->title) . '" class="icon" /> ';
                                        } else {
                                            echo esc_html(empty($game->abbr) ? $game->title : $game->abbr);
                                        }

                                    endforeach; ?>
                            </td>
                        </tr>

                        <?php endfor; ?>
                    </tbody>
                </table>

                <div class="tablenav">
                    <div class="alignleft actions">
                    <select name="doaction2">
                        <option value="" selected="selected"><?php esc_html_e('Actions', 'arcane'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete', 'arcane'); ?></option>
                    </select>
                    <input value="<?php esc_html_e('Apply', 'arcane'); ?>" class="button-secondary action" type="submit" />
                    </div>
                    <br class="clear" />
                </div>

            </form>

            </div></div>

            <div id="col-left">
            <div class="col-wrap">

            <h3><?php esc_html_e('Add New User', 'arcane'); ?></h3>

            <form class="form-wrap" method="post" action="admin-post.php">
                <?php wp_nonce_field('wp-teamwars-acl'); ?>
                <input type="hidden" name="action" value="wp-teamwars-acl" />

                <div class="form-field">
                    <label for="user"><?php esc_html_e('User', 'arcane'); ?></label>
                    <?php wp_dropdown_users('name=user'); ?>
                </div>

                <div class="form-field">
                    <label><?php esc_html_e('Allow user manage specified games only:', 'arcane'); ?></label>
                    <ul class="listbox">
                        <?php foreach($games as $g) : ?>
                        <li><label for="game_<?php echo esc_attr($g->id); ?>"><input type="checkbox" name="games[]" id="game_<?php echo esc_attr($g->id); ?>" value="<?php echo esc_attr($g->id); ?>" /> <?php echo esc_html($g->title); ?></label></li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="description"><?php esc_html_e('User can create new games <strong>only if &ldquo;All&rdquo; option is checked.</strong>', 'arcane'); ?></p>
                </div>

                <div class="form-field">
                    <label><?php esc_html_e('Allow user:', 'arcane'); ?></label>
                    <ul class="listbox">
                        <?php foreach($this->acl_keys as $key => $title) : ?>
                        <li><label for="<?php echo esc_attr($key); ?>"><input type="checkbox" class="check" name="permissions[<?php echo esc_attr($key); ?>]" value="1" id="<?php echo esc_attr($key); ?>" /> <?php echo esc_attr($title); ?></label></li>
                        <?php endforeach; ?>

                    </ul>
                </div>

                <p class="submit">
                    <input type="submit" class="button-secondary" value="<?php esc_html_e('Add User', 'arcane'); ?>" />
                </p>
            </form>

            </div></div>

        </div>

    </div>

    <?php

    }

    function get_available_games() {
        $content = $this->_get_file_content(trailingslashit(get_theme_file_path('addons/team-wars/import')) . 'import.json');

        if($content)
            return json_decode($content);

        return false;
    }

    function is_game_installed($title, $abbr = '', $objects = false) {

        if(!is_array($objects))
            $objects = $this->get_game('');

        foreach($objects as $p) {
            if(preg_match('#' . preg_quote($abbr, '#') . '#i', $p->abbr))
                return $p;

            if(preg_match('#' . preg_quote($title, '#') . '#i', $p->title))
                return $p;
        }

        return false;
    }

    function on_import() {

        $import_list = $this->get_available_games();
        $installed_games = $this->get_game('');

        if(isset($_GET['upload']))
            $this->add_notice(esc_html__('An upload error occurred while import.', 'arcane'), 'error');

        if(isset($_GET['import']))
            $this->add_notice($_GET['import'] ? esc_html__('File successfully imported.', 'arcane') : esc_html__('An error occurred while import.', 'arcane'), $_GET['import'] ? 'updated' : 'error');

        echo esc_attr($this->print_notices());

        ?>
        <div class="wrap">
            <div id="icon-tools" class="icon32"><br></div>
            <h2><?php esc_html_e('Import games', 'arcane'); ?></h2>

            <form id="wp-cw-import" method="post" action="admin-post.php" enctype="multipart/form-data">


                <input type="hidden" name="action" value="wp-teamwars-import" />
                <?php wp_nonce_field('wp-teamwars-import'); ?>


                <p><label for="upload"><input type="radio" name="import" id="upload" value="upload" checked="checked" /> <?php esc_html_e('Upload Package (gz file)', 'arcane'); ?></label></p>

                <p><input type="file" name="userfile" /></p>

                <?php if(!empty($import_list)) : ?>

                <p><label for="available"><input type="radio" name="import" id="available" value="available" /> <?php esc_html_e('Import Available Packages', 'arcane'); ?></label></p>

                    <ul class="available-games">

                    <?php foreach($import_list as $index => $game) :

                        $installed = $this->is_game_installed($game->title, $game->abbr, $installed_games);

                    ?>

                        <li>
                            <label for="game-<?php echo esc_attr($index); ?>">
                                <input type="checkbox" name="items[]" id="game-<?php echo esc_attr($index); ?>" value="<?php echo esc_attr($index); ?>" /> <img src="<?php echo esc_attr(trailingslashit(get_theme_file_uri('addons/team-wars/import')) . $game->icon); ?>" alt="<?php echo esc_attr($game->title); ?>" /> <?php echo esc_html($game->title); ?>
                                <?php if($installed !== false) : ?>
                                <span class="description"><?php esc_html_e('installed', 'arcane'); ?></span>
                                <?php endif; ?>
                            </label>
                        </li>

                    <?php endforeach; ?>

                    </ul>

                <?php endif; ?>

                <p class="submit"><input type="submit" class="button-secondary" value="<?php esc_html_e('Import', 'arcane'); ?>" /></p>

            </form>

        </div>
        <?php

    }

}

/*
 * Initialization
 */

$ArcaneWpTeamWars = new Arcane_TeamWars();
add_action('init', array(&$ArcaneWpTeamWars, 'on_init'));


?>