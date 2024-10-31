<?php
/*
Plugin Name: Post Levels
Plugin URI: http://fortes.com/projects/wordpress/postlevels
Description: Show private posts if user's level is greater than or equal to the post level.
Version: 1.1.1
Author: Filipe Fortes
Author URI: http://fortes.com/

This work is dedicated to the public domain -- see 
http://creativecommons.org/licenses/publicdomain/ for details

Known Issues:

 - This plugin requires version 2.1 of WordPress. For version 2.0, see
   http://svn.wp-plugins.org/post-levels/tags/release-1.0/
   
 - Category listing will only be correct if you include post counts
*/


// Just in case someone's loaded up the page standalone for whatever reason,
// make sure it doesn't crash in an ugly way
if (!function_exists('add_action'))
{
  die("This page must be loaded as part of WordPress");
}

// Defer setup function until pluggable functions have been loaded
// (required since we need to call get_currentuserinfo)
add_action('init', 'postlevels_setup');


// Initialize plugin variables and functions, called during the 
// 'init' action by WordPress
function postlevels_setup()
{
  // Setup options stored in database
  postlevels_setup_options();
  
  // Defer admin menu setup to only run if we're in admin section
  add_action('admin_menu', 'postlevels_admin_hook');

  // Don't bother with anything more unless someone is logged in
  if (is_user_logged_in())
  {
    // Make sure private pages aren't cached publicly
    header('Cache-Control: private');
    header('Pragma: no-cache');

    // Initialize global variables      
    global $postlevels_post_key, $postlevels_user_key, $postlevels_current_user_level;
    $postlevels_post_key = get_option('postlevels_post_key');
    $postlevels_user_key = get_option('postlevels_user_key');
    $postlevels_current_user_level = postlevels_get_user_level();

    // Setup filters

    // Don't use these filters in the admin area
    if (!is_admin())
    {
      add_filter('query', 'postlevels_query');
      add_filter('posts_distinct', 'postlevels_posts_distinct');
      add_filter('posts_groupby', 'postlevels_posts_groupby');
      add_filter('user_has_cap', 'postlevels_has_cap', 10, 3);
      add_filter('the_title', 'postlevels_the_title', 10, 2);
    }
    
    // In case WP ever defines this function for us, check
    // if it exists first
    if (!function_exists('is_private'))
    {
      // Whether the current post is private (i.e. not level 0)
      function is_private()
      {
        global $post; 
        return ($post->post_status == 'private');
      }
    }

    // Whether the current posts is of the given level
    function is_post_level($level = 0)
    {
      global $postlevels_post_key;
      $val = get_post_custom_values($postlevels_post_key);
      if (!empty($val))
        return ($val == $level);
      else
        return ($level == 0);
    }
  }
  else
  {
    // Not logged in, define fast functions that don't use the DB
    // in case they're used in the templates
    function is_post_level($level = 0) { return ($level == 0); }
    if (!function_exists('is_private'))
    {
      function is_private() { return false; }
    }
    
    // Perhaps we're supposed to show previews?
    global $postlevels_post_preview;
    $postlevels_post_preview = get_option('postlevels_post_preview');
    if (($postlevels_post_preview == 'title') || ($postlevels_post_preview == 'excerpt') || ($postlevels_post_preview == 'teaser'))
    {
      // Initialize global variables      
      global $postlevels_post_key, $postlevels_user_key, $postlevels_current_user_level;
      $postlevels_post_key = get_option('postlevels_post_key');
      $postlevels_user_key = get_option('postlevels_user_key');
      $postlevels_current_user_level = get_option('postlevels_default_user_level');

      // Setup filters
      add_filter('query', 'postlevels_query');
      add_filter('the_content', 'postlevels_the_content');
      add_filter('the_title', 'postlevels_the_title');
      add_filter('get_comment_text', 'postlevels_get_comment_text');
      if ($postlevels_post_preview == 'title')
        add_filter('get_the_excerpt', 'postlevels_the_excerpt');
    }
    
  }  
}

// Creates the database-stored options for the plugins that customize
// the plugin's behavior
function postlevels_setup_options()
{
  add_option('postlevels_post_key', 'post_level', 
             "The name of the custom field used for specifiying a post's level",
             'yes');
               
  add_option('postlevels_user_key', 'postlevels_user_level', 
             "The name of the custom field used for specifiying a user's privacy level",
             'yes');

  add_option('postlevels_default_post_level', '0',
             "Default post level for private posts",
             'yes');

  add_option('postlevels_default_user_level', '0',
             "Default user privacy level for users",
             'yes');

  add_option('postlevels_private_before_title', '',
             "Default text placed before the title of a private post",
             'yes');
    
  add_option('postlevels_private_after_title', '',
             "Default text placed after the title of a private post",
             'yes');
             
  add_option('postlevels_post_preview', 'none',
             "Whether users see a preview of private posts",
             'yes');
}

function postlevels_posts_distinct($distinct)
{
  return ($distinct == '') ? 'DISTINCT' : $distinct;
}

function postlevels_posts_groupby($groupby)
{
  global $wpdb;
  return ($groupby == '') ? $wpdb->posts.'.ID' : $groupby;
}

function postlevels_get_comment_text($text)
{
  global $post; 
  if ($post->post_status != 'private')
    return $text;
  
  return "<p>You must be logged in to see this private comment</p>";
  
}

function postlevels_the_content($content)
{
  global $postlevels_post_preview, $post;
  
  if($post->post_status != 'private')
    return $content;
  
  if (($postlevels_post_preview == 'teaser') && preg_match('/<!--more(.+?)?-->/', $post->post_content))
  {
    return $content;
  }
  else if (($postlevels_post_preview == 'excerpt') && ($post->post_excerpt != ''))
  {
    // Ideally, we would use get_the_excerpt, but that causes issues
    return "<p class='privacy-excerpt'>" . $post->post_excerpt . '</p>';
  }
  else
  {
    return '<p class="privacy-notice">You must be logged in to see this private post</p>';
  }
}

function postlevels_the_excerpt($excerpt)
{
  global $postlevels_post_preview, $post;
  
  if (($post->post_status != 'private'))
    return $excerpt;
  
  return "<p>You must be logged in to see this private post</p>";
}

// Edit the many SQL queries that do not have specific filters
// This is almost guaranteed to break some day
function postlevels_query($sql)
{
  global $postlevels_current_user_level, $postlevels_post_key, $wpdb;
  
  // Hack, for now
  if (postlevels_query_match($sql))
  {
    // Collect the cleanup hacks in one place ...
    $sql = postlevels_query_cleanup($sql);
  
    // Add the join
    $sql = preg_replace("/([\s|,]){$wpdb->posts}([\s|,])/", 
                       "$1({$wpdb->posts} LEFT JOIN {$wpdb->postmeta} as pl_{$wpdb->postmeta} ON ({$wpdb->posts}.ID = pl_{$wpdb->postmeta}.post_id))$2", 
                       $sql);
    
    // Modify the where clause
    $sql = preg_replace("/({$wpdb->posts}\.)?post_status[\s]*=[\s]*[\'|\"]publish[\'|\"]/", " ({$wpdb->posts}.post_status = 'publish' OR ({$wpdb->posts}.post_status = 'private' AND (pl_{$wpdb->postmeta}.meta_key = '$postlevels_post_key' AND pl_{$wpdb->postmeta}.meta_value <= $postlevels_current_user_level )))", $sql);
    
    // Check for distinct
    if (strpos(strtoupper($sql), "DISTINCT") === false)
    {
      $sql = str_replace("{$wpdb->posts}.*", "DISTINCT {$wpdb->posts}.*", $sql);
      $sql = preg_replace("/[\s]\*/", " DISTINCT {$wpdb->posts}.*", $sql);
    }
    
    
  }
  
  return $sql;
}

function postlevels_query_cleanup($sql)
{
  global $wpdb;
  
  // Watch out for ambiguity with post2cat
  if (strpos($sql, "post2cat") !== false)
  {
    $sql = preg_replace("/[\s]post_id/", " {$wpdb->post2cat}.post_id", $sql);
  }
  
  // For extended live archives only
  if (function_exists('af_ela_super_archive'))
  {
    // ELA uses p as a shorthand for the posts table ... undo that work
    $sql = preg_replace("/[\s]p[\s]/", ' ', $sql);
    $sql = preg_replace("/([\s|(])p\./", "$1{$wpdb->posts}.", $sql);
  }
  
  if (is_search() && function_exists('UTW_ShowTagsForCurrentPost'))
  {
    // UTW's INNER join causes issues ... maybe this will be fixed in the next version?
    $sql = str_replace("INNER JOIN", "LEFT JOIN", $sql);
  }
  
  return $sql;
}

// Tells us whether or not we should edit the query
function postlevels_query_match($sql)
{
  global $wpdb;
  return ((preg_match("/post_status[\s]*=[\s]*[\'|\"]publish[\'|\"]/", $sql)) && (preg_match("/[\s|,]{$wpdb->posts}[\s|,]/", $sql)));
}

// Enable users with the right level to read a private post
// This is required because single post viewing has special logic
// $allcaps = Capabilities the user currently has
// $caps = Primitive capabilities being tested / requested
// $args = array with:
// $args[0] = original meta capability requested
// $args[1] = user being tested
// $args[2] = post id to view
// See code for assumptions
function postlevels_has_cap($allcaps, $caps, $args)
{
  // This handler is only set up to deal with certain
  // capabilities. Ignore all other calls into here.
  if (!in_array('read_private_posts', $caps) && !in_array('read_private_pages', $caps))
  {
    // These aren't the droids you're looking for
    return $allcaps;
  }
  
  global $postlevels_post_key, $postlevels_user_key;
  
  // As of WP 2.0, read_private_post is only requested when viewing
  // a single post. When this happens, the args[] array has three values,
  // as shown above. If WP changes the args[], this plugin will break
  // UPDATE: WP 2.1 has read_private_pages
  
  // The level of the post being tested
  $post_level = get_post_meta($args[2], $postlevels_post_key, true);
  if ($post_level == '')
  {
    $post_level = get_option('postlevels_default_post_level');
  }

  // The level of the user being tested
  $user_level = get_usermeta($args[1], $postlevels_user_key);
  if ($user_level == '')
  {
    $user_level = get_option('postlevels_default_user_level');
  }

  // Add the capabilities
  $allcaps['read_private_posts'] = $allcaps['read_private_pages'] = ($user_level >= $post_level);
  return $allcaps;
}

// Modifies the text of a private post
function postlevels_the_title($title, $incomingpost = null)
{
  // It's really annoying that WP will call this function sometimes
  // with a string for the second paramter, and sometimes with
  // an object ... this checks for that.
  if (!is_object($incomingpost))
  {
    global $post;
    $incomingpost = $post;
  }
  
  if ($incomingpost->post_status == 'private')
  {
    $title = str_replace("Private: ", "", $title);
    return get_option('postlevels_private_before_title') . $title . get_option('postlevels_private_after_title');
  }
  else
  {
    return $title;
  }
}

// Gets the user level of the current user
// If the user isn't logged in, returns null
function postlevels_get_user_level()
{
  if (!is_user_logged_in())
  {
    return null;
  }
  
  global $user_ID, $postlevels_user_key;
  get_currentuserinfo();
  
  $postlevels_current_user_level = get_usermeta($user_ID, $postlevels_user_key, true);
  // Only want numbers here
  if (($postlevels_current_user_level == '') || !is_numeric($postlevels_current_user_level))
  {
    return intval(get_option('postlevels_default_user_level'));
  }
  return intval($postlevels_current_user_level);
}

// Sets up the post levels admin menu
function postlevels_admin_hook()
{
  global $postlevels_post_key, $postlevels_user_key;

  if (function_exists('add_submenu_page'))
  {
    add_submenu_page('plugins.php', 'Post Levels Configuration', 'Post Levels Configuration', 8, __FILE__, 'postlevels_conf');
    add_submenu_page('users.php', 'User Levels', 'User Levels', 8, __FILE__, 'postlevels_users');
  }

  add_filter('manage_posts_columns', 'postlevels_add_column');
  add_action('manage_posts_custom_column', 'postlevels_do_column');

  add_action('simple_edit_form', 'postlevels_do_form');
  add_action('edit_page_form', 'postlevels_do_form');
  add_action('edit_form_advanced', 'postlevels_do_form');

  add_filter('status_save_pre', 'postlevels_status_save');
  add_action('save_post', 'postlevels_post_save');
}

// Called when a post is saved, updates the post level
function postlevels_post_save($post_ID)
{
  global $postlevels_post_key;
  $post = get_post($post_ID);

  if (($_POST['post_level'] != '') || ($post->post_status == 'private'))
  {
    // Private post with no post level gets default
    if ($_POST['post_level'] == '')
    {
      $post_level = get_option('postlevels_default_post_level');
    }
    else
    {
      $post_level = $_POST['post_level'];
    }
    
    // Check if old value exists so we know whether to update or add
    $old_value = get_post_meta($post_ID, $postlevels_post_key, true);

    if ($old_value != '')
    {
      update_post_meta($post_ID, $postlevels_post_key, $post_level);
    }
    else
    {
      add_post_meta($post_ID, $postlevels_post_key, $post_level);
    }
  }
}

// Called before post status is updated on save. Marks
// post as private if the post level is set
function postlevels_status_save($post_status)
{
  if (($_POST['post_level'] != '') && ($post_status == 'publish'))
    return 'private';
  else
    return $post_status;
}

// Adds a post level column to manage post list
function postlevels_add_column($posts_columns)
{
  $posts_columns['post_level'] = 'Post Level';
  return $posts_columns;
}

// Outputs the post level value for each post in the manage post list
function postlevels_do_column($column_name)
{
  global $postlevels_post_key;
  if ($column_name != 'post_level') return false;

  global $post;
  if ($post->post_status != 'private')
  {
    echo 'Public';
    return true;
  }
  
  echo get_post_meta($post->ID, $postlevels_post_key, true);
  return true;
}

// Called during the edit form, outputs the post level drop down
function postlevels_do_form()
{
  global $post, $postlevels_post_key;
  
  echo '<fieldset id="postleveldiv" class="dbx-box" style="margin-bottom: 1em">';
  echo '  <h3 class="dbx-handle">Post Level</h3>';
  echo '  <div class="dbx-content">';
  echo '<select name="post_level">';
  
  $post_level = get_post_meta($post->ID, $postlevels_post_key, true);
  if (($post->post_status != 'private') && ($post_level == ''))
  {
    echo '  <option value="">Public</option>';
    for ($i = 0; $i <= 10; $i++)
    {
      echo "  <option value='$i'>$i</option>";
    }
  }
  else
  {
    echo '  <option value="">Public</option>';
    for ($i = 0; $i <= 10; $i++)
    {
      echo "  <option value='$i'";
      if ($i == $post_level) echo ' selected="yes" ';
      echo ">$i</option>";
    }
  }
  echo '</select>';
  echo '</div></fieldset>';
}

// Post Levels configuration page
function postlevels_conf()
{
  global $postlevels_user_key, $postlevels_post_key;
  global $table_prefix;
  $default_user_key = $table_prefix . 'user_level';

  ?>

  <div class="wrap">
    <h2><?php _e('Post Levels Configuration'); ?></h2>
    <p>Post Levels is a plugin that allows you to restrict access to your posts based upon the user's access level.</p>
  <?php 
  if (isset($_POST['submit']))
  {
    check_admin_referer();

    // Clean the incoming values
    $before_title = stripslashes($_POST['postlevels_private_before_title']);
    $after_title = stripslashes($_POST['postlevels_private_after_title']);
    if (current_user_can('unfiltered_html') == false)
    {
      $before_title = wp_filter_post_kses($before_title);
      $after_title = wp_filter_post_kses($after_title);
    }
    $post_default = preg_replace('|a-z0-9_|i', '', $_POST['postlevels_default_post_level']);
    $user_default = preg_replace('|a-z0-9_|i', '', $_POST['postlevels_default_user_level']);
    $post_preview = preg_replace('|a-z0-9_|i', '', $_POST['postlevels_post_preview']);
    
    // Update values
    update_option('postlevels_private_before_title', $before_title);
    update_option('postlevels_private_after_title', $after_title);
    if (($post_default != '') && is_numeric($post_default)) update_option('postlevels_default_post_level', $post_default);
    if (($user_default != '') && is_numeric($user_default)) update_option('postlevels_default_user_level', $user_default);
    if (($post_preview == 'none') || ($post_preview == 'title') || ($post_preview == 'excerpt') || ($post_preview == 'teaser')) update_option('postlevels_post_preview', $post_preview);
    
    echo '<div id="message" class="updated fade"><p><strong>' . __('Options saved.') . '</strong></p></div>';
  }

  global $postlevels_post_key, $postlevels_user_key;
  $postlevels_post_key = get_option('postlevels_post_key');
  $postlevels_user_key = get_option('postlevels_user_key');
  $postlevels_post_preview = get_option('postlevels_post_preview')
  
  ?>
    <form action="" method="post" id="postlevels-conf">
      <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
        <tr valign="top">
          <th scope="row">Private Post Title Prefix:</th>
          <td><input name="postlevels_private_before_title" type="text" id="postlevels_private_before_title" value="<?php echo form_option('postlevels_private_before_title'); ?>" size="45" class="code" />
          <br />
          What text gets prepended to the post title
          </td>
        <tr>
        <tr valign="top">
          <th scope="row">Private Post Title Postfix:</th>
          <td><input name="postlevels_private_after_title" type="text" id="postlevels_private_after_title" value="<?php echo form_option('postlevels_private_after_title'); ?>" size="45" class="code" />
          <br />
          What text gets appended to the post title
          </td>
        <tr>
        <tr valign="top">
          <th scope="row">Default Post Level:</th>
          <td><input name="postlevels_default_post_level" type="text" id="postlevels_default_post_level" value="<?php echo get_option('postlevels_default_post_level'); ?>" size="2" />
          <br />
          Posts marked <tt>Private</tt> have this level by default
          </td>
        <tr>
        <tr valign="top">
          <th scope="row">Default User Level:</th>
          <td><input name="postlevels_default_user_level" type="text" id="postlevels_default_user_level" value="<?php echo get_option('postlevels_default_user_level'); ?>" size="2" />
          <br />
          Users have this level by default
          </td>
        </tr>
        <tr valign="top">
          <th scope="row">Post Preview:</th>
          <td>
            <select name="postlevels_post_preview" id="postlevels_post_preview">  
              <option value="none" <?php if ($postlevels_post_preview == 'none') echo 'selected="yes"'; ?>')>None</option>
              <option value="title" <?php if ($postlevels_post_preview == 'title') echo 'selected="yes"'; ?>>Title only</option>
              <option value="excerpt" <?php if ($postlevels_post_preview == 'excerpt') echo 'selected="yes"'; ?>>Title plus excerpt</option>
              <option value="teaser" <?php if ($postlevels_post_preview == 'teaser') echo 'selected="yes"'; ?>>Teaser</option>
            </select>
          <br />
          Controls whether users can see that there are private posts they're missing out on. Select <tt>none</tt> for posts to be completely hidden from people who aren't logged on.
          </td>
        </tr>
      </table>
    
      <p class="submit"><input type="submit" name="submit" value="<?php _e('Update Post Levels &raquo;'); ?>" /></p>
    </form>
  </div>

<?php 
}

// Creates the user level editing page
function postlevels_users()
{
  check_admin_referer();
  if ( !current_user_can('edit_users') )
      die(__('You can&#8217;t edit users.'));

  global $table_prefix;
  $default_user_key = $table_prefix . "user_level";

  global $wpdb, $postlevels_user_key;
  
  if ($_POST['action'] == 'update-level')
  {
    if (!empty($_POST['users']))
    {
      $userids = $_POST['users'];
      foreach($userids as $id)
      {
        update_usermeta($id, $postlevels_user_key, intval($_POST['new_level']));
      }
      
      echo '<div id="message" class="updated fade"><p><strong>' . __('User levels changed for ') . count($userids) . ' ' . __('user(s)') . '</strong></p></div>';
    }
    else
    {
      echo '<div id="message" class="updated fade"><p><strong>' . __('No users updated (none checked)') . '</strong></p></div>';
    }
  }
  else
  {
    if(isset($_POST['update-user-levels']))
    {
      echo '<div id="message" class="updated fade"><p><strong>' . __('No users updated (you must click the button below "Update User Levels")') . '</strong></p></div>';
    }
  }
  
  ?>
  <div class="wrap">
    <h2>Manage User Levels</h2>
    <p>This page controls the level of each user, which determines what posts can and cannot be seen by that user.</p>

    <form action="" method="post" id="postlevels-users" name="postlevels-users">
      <table width="100%" cellspacing="2" cellpadding="5" class="editform">
        <tr>
          <th><?php _e('ID') ?></th>
          <th><?php _e('Username') ?></th>
          <th><?php _e('Name') ?></th>
          <th><?php _e('E-mail') ?></th>
          <th><?php _e('Level') ?></th>
        </tr>
        <?php
          $userids = $wpdb->get_col("SELECT ID FROM $wpdb->users;");
          $users = array();
          foreach($userids as $userid) {
            $users[$userid] = new WP_User($userid);
          }
          
          $style = '';
          foreach ($users as $user_object) {
            $email = $user_object->user_email;
            $style = ('class="alternate"' == $style) ? '' : 'class="alternate"';
            echo "<tr $style>";
            echo "<td><input type='checkbox' name='users[]' id='user_{$user_object->ID}' value='{$user_object->ID}' /> <label for='user_{$user_object->ID}'>{$user_object->ID}</label></td>";
            echo "<td><label for='user_{$user_object->ID}'><strong>$user_object->user_login</strong></label></td>";
            echo "<td><label for='user_{$user_object->ID}'>$user_object->first_name $user_object->last_name</label></td>";
            echo "<td><a href='mailto:$email' title='" . sprintf(__('e-mail: %s'), $email) . "'>$email</a></td>";
            echo "<td>" . get_usermeta($user_object->ID, $postlevels_user_key) . "</td>";
            echo "</tr>";
          }
        ?>
      </table>

      <h2>Update User Levels</h2>
      <ul style="list-style:none;">
        <li><input type="radio" name="action" id="action0" value="update-level" /> <?php echo '<label for="action0">'.__('Set the Level of checked users to:')."</label>"; ?>
        <select name="new_level">
          <option value="0">0</option>
          <option value="1">1</option>
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4">4</option>
          <option value="5">5</option>
          <option value="6">6</option>
          <option value="7">7</option>
          <option value="8">8</option>
          <option value="9">9</option>
          <option value="10">10</option>
        </select>
        </li>
      </ul>

      <?php if ($postlevels_user_key == $default_user_key): ?>
      <p class="updated fade-ff0000">You are currently using <code><?php echo $default_user_key; ?></code> as your user key, which is shared with WordPress for administration purposes. This means that changing a users' level will affect their Administrative powers. <em>If you change your own level, you could lock yourself out of the system!</em> You have been warned.</p>
      <?php endif; ?>
      
      <p class="submit"><input type="submit" name="update-user-levels" value="<?php _e('Update User Levels &raquo;'); ?>" /></p>
    </form>
  </div>
  <?php
}
?>
