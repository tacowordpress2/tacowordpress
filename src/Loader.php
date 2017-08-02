<?php
namespace Taco;

class Loader
{
    public static function init()
    {
        add_action('init', '\Taco\Post\Loader::loadAll');
        add_action('init', '\Taco\Post\Loader::registerTaxonomies');
        add_action('init', '\Taco\Term\Loader::loadAll');
        add_action('shutdown', '\Taco\Frontend\Loader::addToHTML');

        // Add action to save post
        add_action('save_post', '\Taco\Post::addSaveHooks', 10, 3);

        // Allow post meta to be restored
        add_action('wp_restore_post_revision', '\Taco\Post::addRestoreRevisionHooks', 10, 2);

        // Add post meta fields to the revisions page
        // The hook to get their values is in the post loader
        add_filter('_wp_post_revision_fields', '\Taco\Post::getRevisionMetaFields');

        add_filter('wp_save_post_revision_check_for_changes', '\Taco\Post::alwaysPreviewChanges', 10, 3);
        add_filter('wp_save_post_revision_post_has_changed', '\Taco\Post::checkMetafieldChanges', 10, 3);

        // JSON decode the meta query variable
        add_filter ('rest_query_var-meta_query', function($value) {
          return json_decode($value, true);;
        });

        return true;
    }
}
