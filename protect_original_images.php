<?php
/*
Plugin Name: Protect Original Images
Description: WordPress plugin to make your images in original size secure
Version: 0.1
Author: Damian Rawski
License: GPLv2
Copyright: Damian Rawski
*/

namespace WordPress\Plugins;

class ProtectOriginalImages {

    private static $supportedExtensions = ['jpg', 'jpeg', 'gif', 'png', 'bmp'];

    private static $fallbackSizes = ['large', 'medium', 'thumbnail'];

    public function __construct() {
        add_filter('mod_rewrite_rules', [$this, 'addRewriteRules']);
        add_filter('the_content', [$this, 'replaceUrlsInImgTags']);
        add_filter('the_content', [$this, 'replaceUrlsInAnchors']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Gets image ID by URL
     * see: http://philipnewcomer.net/2012/11/get-the-attachment-id-from-an-image-url-in-wordpress/
     *
     * @param $imageUrl
     * @return null|string
     */
    public static function getImageIdByUrl($imageUrl) {
        global $wpdb;
        $attachmentId = false;

        // Get the upload directory paths
        $uploadDirPath = wp_upload_dir();

        // Make sure the upload path base directory exists in the attachment URL, to verify that we're working with a media library image
        if ( false !== strpos( $imageUrl, $uploadDirPath['baseurl'] ) ) {
            // If this is the URL of an auto-generated thumbnail, get the URL of the original image
            $imageUrl = preg_replace( '/-\d+x\d+(?=\.('.implode('|', apply_filters('wppoi_supported_extensions', static::$supportedExtensions)).')$)/i', '', $imageUrl );
            // Remove the upload path base directory from the attachment URL
            $imageUrl = str_replace( $uploadDirPath['baseurl'] . '/', '', $imageUrl );
            // Finally, run a custom database query to get the attachment ID from the modified attachment URL
            $attachmentId = $wpdb->get_var( $wpdb->prepare( "SELECT wposts.ID FROM $wpdb->posts wposts, $wpdb->postmeta wpostmeta WHERE wposts.ID = wpostmeta.post_id AND wpostmeta.meta_key = '_wp_attached_file' AND wpostmeta.meta_value = '%s' AND wposts.post_type = 'attachment'", $imageUrl ) );
        }

        return !empty($attachmentId) ? $attachmentId : null;
    }

    protected function getSupportedExtensions() {
        return apply_filters('wppoi_supported_extensions', static::$supportedExtensions);
    }

    protected function getImageUrlPattern() {
        return '(.*?)(\-\d{2,4}x\d{2,4})?\.('.implode('|', $this->getSupportedExtensions()).')';
    }

    protected function getFallbackSizes() {
        return apply_filters('wppoi_fallback_sizes', static::$fallbackSizes);
    }

    public function replaceUrlsInImgTags($content) {
        return preg_replace_callback('/<img(.+?)src="' . $this->getImageUrlPattern() . '"(.*?)>/', function ($matches) {
            if (empty($matches[3])) {
                return '<img' . $matches[1] . 'src="' . wp_get_attachment_image_src(static::getImageIdByUrl($matches[2] . '.' . $matches[4]), 'cropped_large')[0] . '"' . $matches[5] . '>';
            }
            return $matches[0];
        }, $content);
    }

    public function replaceUrlsInAnchors($content) {
        return preg_replace_callback('/<a(.+?)href="'.$this->getImageUrlPattern().'"(.*?)>/', function($matches) {
            $originalUrl = $matches[2].'.'.$matches[4];
            if (empty($matches[3])) {
                $fallbackSizes = $this->getFallbackSizes();
                $previewUrl = $originalUrl;
                $imageId = static::getImageIdByUrl($matches[2].'.'.$matches[4]);
                while ($originalUrl == $previewUrl && count($fallbackSizes) > 0) {
                    $previewUrl = wp_get_attachment_image_src($imageId, array_shift($fallbackSizes))[0];
                }
                return '<a'.$matches[1].'href="'.$previewUrl.'"'.$matches[5].'>';
            }
            return $matches[0];
        }, $content);
    }

    public function addRewriteRules($rules) {
        $adminUrl = str_replace('.', '\.', get_admin_url());
        $rulesTemplate = str_replace('[[ADMIN_URL]]', $adminUrl, file_get_contents(__DIR__.'/htaccess_rewrite_rules'));
        return $rules . $rulesTemplate;
    }

    public function activate() {
        flush_rewrite_rules(true);
    }

    public function deactivate() {
        remove_filter('mod_rewrite_rules', [$this, 'addRewriteRules']);
        flush_rewrite_rules(true);
    }

}

new ProtectOriginalImages();
