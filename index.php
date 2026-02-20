<?php
/*
Plugin Name: Ultimate UniqID Media Manager
Description: Rename ALL media files (images, pdf, mp3, mp4, mov, etc.) to uniqid, store in uniqid folders, migrate old media, fix database URLs and regenerate thumbnails.
Version: 1.1
Author: 6arshid
License: GPL2
*/

if (!defined('ABSPATH')) exit;


/*
|--------------------------------------------------------------------------
| 1. DISABLE YEAR/MONTH FOLDERS
|--------------------------------------------------------------------------
*/

add_filter('pre_option_uploads_use_yearmonth_folders', '__return_zero');


/*
|--------------------------------------------------------------------------
| 2. RENAME ALL NEW FILES TO UNIQID
|--------------------------------------------------------------------------
*/

add_filter('wp_handle_upload_prefilter', function($file){

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    if ($ext) {
        $file['name'] = uniqid() . '.' . strtolower($ext);
    }

    return $file;
});


/*
|--------------------------------------------------------------------------
| 3. FORCE NEW UPLOADS INTO UNIQID FOLDER
|--------------------------------------------------------------------------
*/

add_filter('upload_dir', function($dirs){

    if (!empty($_FILES)) {

        $uniq_folder = uniqid();

        $dirs['subdir'] = '/' . $uniq_folder;
        $dirs['path']   = $dirs['basedir'] . '/' . $uniq_folder;
        $dirs['url']    = $dirs['baseurl'] . '/' . $uniq_folder;

        wp_mkdir_p($dirs['path']);
    }

    return $dirs;
});


/*
|--------------------------------------------------------------------------
| 4. ADMIN MENU
|--------------------------------------------------------------------------
*/

add_action('admin_menu', function(){
    add_menu_page(
        'UniqID Media',
        'UniqID Media',
        'manage_options',
        'uniqid-media',
        'uniqid_media_admin_page',
        'dashicons-admin-media',
        81
    );
});


function uniqid_media_admin_page(){
    ?>
    <div class="wrap">
        <h1>Ultimate UniqID Media Migration</h1>
        <p>This will rename ALL old files (images, pdf, mp3, mp4, mov, etc.), move them to uniqid folders, fix database URLs and regenerate thumbnails.</p>

        <form method="post">
            <?php wp_nonce_field('uniqid_media_nonce'); ?>
            <input type="submit" name="start_migration" class="button button-primary" value="Migrate & Repair ALL Media">
        </form>
    </div>
    <?php

    if (isset($_POST['start_migration']) && check_admin_referer('uniqid_media_nonce')) {
        uniqid_media_migrate_all();
    }
}


/*
|--------------------------------------------------------------------------
| 5. FULL MIGRATION FOR ALL FILE TYPES
|--------------------------------------------------------------------------
*/

function uniqid_media_migrate_all(){

    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $base_url = $upload_dir['baseurl'];

    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
    ]);

    foreach ($attachments as $attachment){

        $old_relative = get_post_meta($attachment->ID, '_wp_attached_file', true);
        if (!$old_relative) continue;

        $old_path = $base_dir . '/' . $old_relative;
        if (!file_exists($old_path)) continue;

        $ext = pathinfo($old_path, PATHINFO_EXTENSION);
        if (!$ext) continue;

        $new_folder   = uniqid();
        $new_filename = uniqid() . '.' . strtolower($ext);

        $new_dir  = $base_dir . '/' . $new_folder;
        wp_mkdir_p($new_dir);

        $new_path = $new_dir . '/' . $new_filename;

        if (!rename($old_path, $new_path)) continue;

        $new_relative = $new_folder . '/' . $new_filename;
        $new_url = $base_url . '/' . $new_relative;
        $old_url = $base_url . '/' . $old_relative;

        /*
        |--------------------------------------------------------------------------
        | UPDATE ATTACHMENT META
        |--------------------------------------------------------------------------
        */

        update_post_meta($attachment->ID, '_wp_attached_file', $new_relative);

        delete_post_meta($attachment->ID, '_wp_attachment_metadata');

        // regenerate only if image
        if (wp_attachment_is_image($attachment->ID)) {
            $metadata = wp_generate_attachment_metadata($attachment->ID, $new_path);
            wp_update_attachment_metadata($attachment->ID, $metadata);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE CONTENT
        |--------------------------------------------------------------------------
        */

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $wpdb->posts 
                 SET post_content = REPLACE(post_content, %s, %s)",
                $old_url,
                $new_url
            )
        );

        $wpdb->update(
            $wpdb->posts,
            ['guid' => $new_url],
            ['ID' => $attachment->ID]
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $wpdb->postmeta 
                 SET meta_value = REPLACE(meta_value, %s, %s)",
                $old_url,
                $new_url
            )
        );
    }

    echo "<div class='updated'><p>All media files (images, pdf, mp3, mp4, mov, etc.) successfully migrated and repaired.</p></div>";
}
