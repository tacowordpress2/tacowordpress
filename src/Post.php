<?php
namespace Taco;

use Taco\Util\Arr as Arr;
use Taco\Util\Collection as Collection;
use Taco\Util\Color as Color;
use Taco\Util\Html as Html;
use Taco\Util\Num as Num;
use Taco\Util\Obj as Obj;
use Taco\Util\Str as Str;

/**
 * Use WordPress posts like a normal model
 * You don't want to use this class directly. Extend it.
 */
class Post extends Base
{
    // Keep a static list of post types so we can look up the post class later
    // This prevents us from having to registering
    public static $post_types;

    const ID = 'ID';
    const KEY_CLASS = 'class';
    const KEY_NONCE = 'nonce';
    const URL_SUBMIT = '/wp-content/plugins/taco/post/submit.php';

    public $singular            = null;
    public $plural              = null;
    public $last_error          = null;
    private $_terms             = array();
    private $_real_post_type    = null;


    public function getKey()
    {
        return $this->getPostType();
    }


    /**
     * Load a post by ID
     * @param mixed $id String or integer as post ID, or post object
     * @param bool $load_terms
     * @return bool
     */
    public function load($id, $load_terms = true)
    {
        $info = (is_object($id)) ? $id : get_post($id);

        // Save the actual post type here in case we end up loading a revision for a preview
        $this->_real_post_type = $info->post_type;

        if (is_preview() && $info->post_type !== 'sub-post-revision') {
            $revisions = wp_get_post_revisions($id);
            $id = array_shift($revisions);
            $info = (is_object($id)) ? $id : get_post($id);
        }

        if (!is_object($info)) {
            return false;
        }

        // Handle how WordPress converts special chars out of the DB
        // b/c even when you pass 'raw' as the 3rd partam to get_post,
        // WordPress will still encode the values.
        if (isset($info->post_title) && preg_match('/[&]{1,}/', $info->post_title)) {
            $info->post_title = html_entity_decode($info->post_title);
        }

        $this->_info = (array) $info;

        // meta
        $meta = get_metadata('post', $this->_info[self::ID]);
        if (Arr::iterable($meta)) {
            foreach ($meta as $k => $v) {
                $this->set($k, current($v));
            }
        }

        // terms
        if ($load_terms) {
            $this->loadTerms();
        }

        return true;
    }

    /**
     * Load the terms
     * @return bool
     */
    public function loadTerms()
    {
        $taxonomy_keys = $this->getTaxonomyKeys();
        if (!Arr::iterable($taxonomy_keys)) {
            return false;
        }

        // TODO Move this to somewhere more efficient
        // Check if this should be an instance of TacoTerm.
        // If not, the object will just be a default WP object from wp_get_post_terms below.
        $taxonomies_subclasses = array();
        $subclasses = Term\Loader::getSubclasses();
        foreach ($subclasses as $subclass) {
            $term_instance = new $subclass;
            $term_instance_taxonomy_key = $term_instance->getKey();
            foreach ($taxonomy_keys as $taxonomy_key) {
                if (array_key_exists($taxonomy_key, $taxonomies_subclasses)) {
                    continue;
                }
                if ($term_instance_taxonomy_key !== $taxonomy_key) {
                    continue;
                }

                $taxonomies_subclasses[$taxonomy_key] = $subclass;
                break;
            }
        }

        foreach ($taxonomy_keys as $taxonomy_key) {
            $terms = wp_get_object_terms($this->get(self::ID), $taxonomy_key);
            if (!Arr::iterable($terms)) {
                continue;
            }

            $terms = array_combine(
                array_map('intval', Collection::pluck($terms, 'term_id')),
                $terms
            );

            // Load Taco\Term if applicable
            if (array_key_exists($taxonomy_key, $taxonomies_subclasses)) {
                $terms = Term\Factory::createMultiple($terms, $taxonomy_key);
            }

            $this->_terms[$taxonomy_key] = $terms;
        }

        return true;
    }


    /**
     * Save a post
     * This handles saving both revisions and the main post
     * @param bool $exclude_post If true, the post itself is not saved, and only the metadata
     * @param bool $is_revision Are we saving a revision
     * @return mixed Integer on success: post ID, false on failure (with WP_Error accessible via getLastError)
     */
    public function save($exclude_post = false)
    {
        if (count($this->_info) === 0) {
            return false;
        }

        // set defaults
        $defaults = $this->getDefaults();
        if (count($defaults) > 0) {
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $this->_info)) {
                    $this->_info[$k] = $v;
                }
            }
        }

        // separate regular post fields from meta
        $post = array();
        $meta = array();
        $post_fields = static::getCoreFieldKeys();
        $fields_and_attribs = static::getFields();
        $meta_fields = array_keys($fields_and_attribs);

        foreach ($this->_info as $k => $v) {
            if (in_array($k, $post_fields)) {
                $post[$k] = $v;
            } elseif (in_array($k, $meta_fields)) {
                $meta[$k] = $v;
            }
        }

        // save post
        $is_update = array_key_exists(self::ID, $post);
        if (!$exclude_post) {
            if ($is_update) {
                wp_update_post($post);
            } else {
                // Pass true as the second param to wp_insert_post
                // so that WP won't silently fail if we hit an error
                $id = wp_insert_post($post, true);
                if (!is_int($id)) {
                    $this->last_error = $id;
                    return false;
                }

                $this->_info[self::ID] = $id;
            }

            // Hack to fix ampersand saving in post titles
            // TODO See if there is a better way to do this
            if ($this->_info[self::ID] && array_key_exists('post_title', $post) && preg_match('/[&\']{1,}/', $post['post_title'])) {
                global $wpdb;
                $prepared_sql = $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_title=%s WHERE ID=%d",
                    $post['post_title'],
                    $this->_info[self::ID]
                );
                $wpdb->query($prepared_sql);
            }
        }

        // Save meta to either the post or revision
        // Iterate the fields not the meta, in case the value passed in is empty
        if (Arr::iterable($meta)) {
            foreach ($meta as $k => $v) {
                if (preg_match('/link/', $fields_and_attribs[$k]['type'])) {
                    $v = urldecode($v);
                }

                // update_post_meta handles both add and update
                update_metadata('post', $this->{self::ID}, $k, $v);
            }
        }

        // save terms
        if (Arr::iterable($this->_terms) > 0) {
            foreach ($this->_terms as $taxonomy_key => $term_ids) {
                if (!Arr::iterable($term_ids)) {
                    continue;
                }

                foreach ($term_ids as $n => $term_id) {
                    // Did a name get passed that's not a term_id?
                    // Try getting the term_id, or saving a new term and using that term_id
                    $convert_term_name_to_term_id = (
                        is_string($term_id)
                        && !is_numeric($term_id)
                        && $taxonomy_key !== 'post_tag'
                    );
                    if ($convert_term_name_to_term_id) {
                        $term = get_term_by('name', $term_id, $taxonomy_key);
                        if (!is_object($term)) {
                            $term = wp_insert_term($term_id, $taxonomy_key);
                        }
                        $term_id = (object) $term;
                    }

                    // The terms might come in as an ID or a whole term object
                    // This makes sure the wp_set_post_terms call only gets term IDs, not objects
                    if (is_object($term_id)) {
                        $term_ids[$n] = $term_id->term_id;
                    }
                }

                // Save taxonomy to the revision so previews work properly
                // These don't get restored when going back revisions
                wp_set_object_terms($this->_info[self::ID], $term_ids, $taxonomy_key);
            }
        }

        return $this->_info[self::ID];
    }


    /**
     * Fields from posts table
     * @return array()
     */
    public static function getCoreFieldKeys()
    {
        return array(
            self::ID,
            'post_author',
            'post_date',
            'post_date_gmt',
            'post_content',
            'post_title',
            'post_category',
            'post_excerpt',
            'post_status',
            'comment_status',
            'ping_status',
            'post_password',
            'post_name',
            'to_ping',
            'pinged',
            'post_modified',
            'post_modified_gmt',
            'post_content_filtered',
            'post_parent',
            'guid',
            'menu_order',
            'post_type',
            'post_mime_type',
            'comment_count',
        );
    }


    /**
     * Get the meta fields
     * @return array
     */
    public function getFields()
    {
        return array();
    }


    /**
     * Get the meta field keys
     * If your admin UI is rendering slowly,
     * you may want to override this and return a hardcoded array
     * @return array
     */
    public function getMetaFieldKeys()
    {
        return array_keys($this->getFields());
    }


    /**
     * Get default values
     * Override this
     * @return array
     */
    public function getDefaults()
    {
        global $user;
        return array(
            'post_type'     => $this->getPostType(),
            'post_author'   => (is_object($user)) ? $user->ID : null,
            'post_date'     => current_time('mysql'),
            'post_category' => array(0),
            'post_status'   => 'publish'
        );
    }


    /**
     * Get the supporting fields
     * @return array
     */
    public function getSupports()
    {
        return array(
            'title',
            'editor', // content
            //'author',
            //'thumbnail',
            //'excerpt',
            //'trackbacks',
            //'custom-fields',
            //'comments',
            'revisions',
            //'page-attributes',
            //'post-formats',
        );
    }


    /**
     * Get the last error from WordPress
     * @return object Instance of WP_Error
     */
    public function getLastError()
    {
        return $this->last_error;
    }


    public static function getClassFromPostType($post_type) {
        if (empty(self::$post_types[$post_type])) {
            return false;
        } else {
            return self::$post_types[$post_type];
        }
    }


    /**
     * Add save hooks
     * @param integer $post_id
     * TODO add nonce check
     */
    public static function addSaveHooks($post_id, $post, $update)
    {
        // Don't save the post itself if this is a preview
        // The revision still needs to be saved to since that's how the preview fields get populated
        if ($post->post_type !== 'revision' && isset($_REQEUST['wp-preview']) && $_REQUEST['wp-preview'] === 'dopreview') {
            return;
        }

        // Make sure we have the right post type or we're on a revision
        if ($post->post_type === 'revision') {
            $post_type = get_post($post->post_parent)->post_type;
        } else {
            $post_type = $post->post_type;
        }

        $class = static::getClassFromPostType($post_type);
        if (empty($class)) {
            return;
        }

        $instance = new $class;

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Check that a post_type is defined. It's not in the case of a delete.
        if (!array_key_exists('post_type', $_POST)) {
            return $post_id;
        }

        // Check perms
        $check = ($_POST['post_type'] === 'page') ? 'edit_page' : 'edit_post';
        if (!current_user_can($check, $post_id)) {
            return $post_id;
        }

        // Get fields to assign
        $updated_entry = new $class;
        $meta_fields = $instance->getFields();

        $field_keys = array_merge(
            static::getCoreFieldKeys(),
            array_keys($meta_fields)
        );

        // Get terms to assign
        $taxonomies = $updated_entry->getTaxonomies();
        $taxonomy_field_keys = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomy_field_keys[$post_type . '_' . $taxonomy] = $taxonomy;
        }

        // Assign vars.  Loop through the field keys rather than the POST vars in order to clear out
        // empty checkboxes
        foreach ($field_keys as $k) {
            if (isset($_POST[$k])) {
                $updated_entry->set($k, $_POST[$k]);
            } else {
                if (!empty($meta_fields[$k]['type']) && $meta_fields[$k]['type'] === 'checkbox') {
                    $updated_entry->set($k, '0');
                }
            }
        }

        foreach ($taxonomy_field_keys as $k => $taxonomy)  {
            if (isset($_POST[$k])) {
                $terms = [];
                foreach ($_POST[$k] as $term_id) {
                    // For some reason Wordpress posts 0 to the category each time
                    if ($term_id != 0) {
                        $terms[] = get_term($term_id, $taxonomy);
                    }
                }

                $updated_entry->setTerms($terms, $taxonomy);
            }
        }

        // Make sure the revision is getting saved if this is a revision
        $updated_entry->set(self::ID, $post->{self::ID});

        return $updated_entry->save(true);
    }


    /**
     * Add restore revision hooks
     * @param integer $post_id
     * @param integer $revision_id
     */
    public static function addRestoreRevisionHooks($post_id, $revision_id)
    {
        $post = get_post($post_id);

        // Make sure we have the right post type
        $class = static::getClassFromPostType($post->post_type);
        if (empty($class)) {
            return;
        }

        $instance = new $class;

        // Make sure we have the right post type
        // Without this, you'll get weird cross-polination errors across post types
        if (!is_object($post) || $post->post_type !== $instance->getPostType()) {
            return;
        }

        return $instance->restoreRevisionMeta($post, $revision_id);
    }


    /**
      * Restore a revision by writing the revision metadata to the post itself
      * @param integer $post_id
      * @param integer $revision_id
      */
    private function restoreRevisionMeta($post, $revision_id) {
        $fields_and_attribs = static::getFields();

        $revision_meta = get_metadata('post', $revision_id, '', true);

        if ($revision_meta === false) {
            foreach($fields_and_attribs as $meta_key => $meta_field) {
                delete_post_meta($post->{self::ID}, $meta_key);
            }
        } else {
            foreach($fields_and_attribs as $meta_key => $meta_field) {
                if (array_key_exists($meta_key, $revision_meta)) {
                    update_post_meta($post->{self::ID}, $meta_key, $revision_meta[$meta_key][0]);

                    if (isset($meta_field['class']) && $meta_field['class'] === 'addmany' ) {
                        \JasandPereza\AddMany::setSubposts($post->{self::ID}, $revision_meta[$meta_key][0]);
                    }

                    if (isset($meta_field['data-addmany']) && $meta_field['data-addmany'] === true) {
                        \Taco\AddMany\AddMany::restoreSubposts($post, $revision_id, $revision_meta[$meta_key][0]);
                    }
                } else {
                    delete_post_meta($post->{self::ID}, $meta_key);
                }
            }
        }
    }


    // Pretty print JSON for revisions
    // Just return a string if it's not JSON
    private static function prettyPrintJSON($str, $level = 0) {
        $json_obj = json_decode($str);
        if ($json_obj === null) {
            return $str;
        }

        $value = '';
        foreach($json_obj as $field_name => $field_value) {
            $value .= str_repeat(' ', $level * 4);
            $value .= Str::human($field_name) . ': ' . static::prettyPrintJSON($field_value, $level + 1) . "\n";
        }

        return $value;
    }


    /**
      * Get a meta value for a revision
      * @param string $compare_from_value The value of the revision - this seems to always be empty which is why we need to override this function
      * @param string $field The field to compare
      * @param WP_Post $revision The post revision
      * @param string $from_to A string 'from' or 'to' specifying whether this is from or to
      */
    public static function getRevisionMetaValue($compare_from_value, $field, $revision, $from_to) {
        $post = get_post($revision->post_parent);

        if (empty($post)) {
            return;
        }

        $class = static::getClassFromPostType($post->post_type);

        if (empty($class)) {
            return;
        }

        $instance = new $class;
        $meta_fields = $instance->getFields();
        $value = get_metadata('post', $revision->{static::ID}, $field, true);

        if (isset($meta_fields[$field]) && isset($meta_fields[$field]['type'])) {
            $meta_field = $meta_fields[$field];

            if ($meta_field['type'] === 'checkbox') {
                if (!!$value) {
                    $value = 'Yes';
                } else {
                    $value = 'No';
                }
            } else if($meta_field['type'] === 'select') {
                if (!empty($meta_field['options'][$value])) {
                    $value = $meta_field['options'][$value];
                }
            } else if($meta_field['type'] === 'link') {
                $value = static::prettyPrintJSON($value);
            } else if(isset($meta_field['class']) && $meta_field['class'] === 'addbysearch') {
                // Get each post title if this is an addbysearch
                $values = [];

                $post_ids = explode(',', $value);
                foreach($post_ids as $post_id) {
                    $post = get_post(trim($post_id));
                    if ($post) {
                        $values[] = $post->post_title;
                    } else {
                        $values[] = 'Post Deleted';
                    }
                }

                $value = implode("\n", $values);
            } else if (isset($meta_field['class']) && $meta_field['class'] === 'addmany') {
                // Handle legacy addmany
                $post_ids = explode(',', $value);

                $values = [];
                // Get the child fields for each subpost
                foreach($post_ids as $post_id) {
                    $post = get_post($post_id);
                    if ($post) {
                        $post = \Taco\Post\Factory::create($post_id);
                        $fields = $meta_field[$post->fields_variation]['fields'];

                        $field_values = [];

                        // Append field values for each subpost field value
                        foreach($fields as $field_name => $field) {
                            if (!empty($meta_field[$post->fields_variation]['fields']['label'])) {
                                $field_values[] = $meta_field[$post->fields_variation]['fields']['label'] . ': ' . $post->$field_name;
                            } else {
                                $field_values[] = Str::human($field_name) . ': '. $post->$field_name;
                            }
                        }

                        $values[] = implode("\n", $field_values);
                    } else {
                        $values[] = 'Post Deleted';
                    }
                }

                $value = implode("\n", $values);
            } else if (isset($meta_field['data-addmany']) && $meta_field['data-addmany'] === true) {
                // Handle new addmany
                $value_obj = json_decode($value);
                $value = '';

                if (isset($value_obj->subposts)) {
                    foreach ($value_obj->subposts as $subpost) {
                        foreach ($subpost->fieldsConfig as $field_name => $field) {
                            if (!empty($field->label)) {
                                $value .= $field->label;
                            } else {
                                $value .= Str::human($field_name);
                            }

                            $value .= ': ' . static::prettyPrintJSON($field->value) . "\n";
                        }
                    }
                }
            }
        }

        return $value;
    }


    /**
      * Add meta field values to revision fields
      * @param $fields The default fields that come from wordpress
      */
    public static function getRevisionMetaFields($fields) {
        // When grabbing revisions, the current post comes in as a regular
        // global post object, but subsequent revisions are determined by the
        // post_id for some reason
        global $post;
        if (!empty($post)) {
            $current_post = $post;
        } else if (!empty($_POST['post_id'])) {
            $current_post = get_post($_POST['post_id']);
        } else {
            return $fields;
        }

        // Figure out the class for this post and get fields
        $class = static::getClassFromPostType($current_post->post_type);
        if (empty($class)) {
            return $fields;
        }

        $instance = new $class;
        foreach ($instance->getFields() as $key => $value) {
            $fields[$key] = $instance->getLabelText($key);
        }

        return $fields;
    }


    /**
      * Always trigger a change for previews so everything is the most current
      * This allows us to always show taxonomy changes when previewing
      */
    public static function alwaysPreviewChanges($check_for_changes, $last_revision, $post) {
        if (
            is_preview() ||
            (!empty($_REQUEST['wp-preview']) && $_REQUEST['wp-preview'] === 'dopreview')
        ) {
            return true;
        } else {
            return $check_for_changes;
        }
    }


    /**
      * Check for metafield changes here.  The Wordpress provided one is unreliable since
      * it doesn't load the meta fields of the current post, but rather what's already in
      * the database for some reason
      *
      */
    public static function checkMetafieldChanges($post_has_changed, $last_revision, $post) {
        // We don't need to go through this if Wordpress has already determined that this
        // post was modified
        if ($post_has_changed === true) {
            return $post_has_changed;
        }

        // Modified version of wordpress revision.php line 157
        foreach ( array_keys( _wp_post_revision_fields( $post ) ) as $field ) {
            if (isset($_POST[$field])) {
                $field_value = $_POST[$field];
            } else {
                $field_value = $post->$field;
            }

            if ( normalize_whitespace( $field_value ) != normalize_whitespace( $last_revision->$field ) ) {
                $post_has_changed = true;
                break;
            }
        }

        return $post_has_changed;
    }


    /**
     * Render a meta box
     * @param object $post
     * @param array $post_config
     * @param bool $return Return the output? If false, echoes the output
     */
    public function renderMetaBox($post, $post_config, $return = false)
    {
        $config = $this->getMetaBoxConfig($post_config['args']);
        if (!Arr::iterable($config['fields'])) {
            return false;
        }

        $this->load($post);

        $out = array();
        $out[] = '<table>';
        foreach ($config['fields'] as $name => $field) {
            // Hack to know if we're editing an existing post
            $is_existing_post = (
                is_array($_SERVER)
                && array_key_exists('REQUEST_URI', $_SERVER)
                && preg_match('/post=([\d]{1,})/', $_SERVER['REQUEST_URI'])
            );

            $field['type'] = (array_key_exists('type', $field)) ? $field['type'] : 'text';

            if (array_key_exists($name, $this->_info)) {
                $field['value'] = $this->_info[$name];
            } elseif ($is_existing_post && $field['type'] !== 'html') {
                $field['value'] = null;
            }

            $default = null;
            if (array_key_exists('default', $field)) {
                if ($field['type'] === 'select') {
                    $default = $field['options'][$field['default']];
                } elseif ($field['type'] === 'image') {
                    $default = '<br>'.Html::image($field['default'], null, array('style'=>'max-width:100px;'));
                } else {
                    $default = nl2br($field['default']);
                }
            }
            $tr_class = $name;
            if ($field['type'] === 'hidden') {
                $tr_class .= ' hidden';
            }
            $out[] = sprintf(
                '<tr%s><td>%s</td><td>%s%s%s</td></tr>',
                Html::attribs(array('class'=>$tr_class)),
                $this->getRenderLabel($name),
                $this->getRenderMetaBoxField($name, $field),
                (array_key_exists('description', $field)) ? Html::p($field['description'], array('class'=>'description')) : null,
                (!is_null($default)) ? sprintf('<p class="description">Default: %s</p>', $default) : null
            );
        }
        $out[] = '</table>';

        $html = join("\n", $out);
        if ($return) {
            return $html;
        }
        echo $html;
    }


    /**
     * Register the post type
     * Override this if you need to
     */
    public function registerPostType()
    {
        $config = $this->getPostTypeConfig();
        if (!empty($config)) {
            register_post_type($this->getPostType(), $config);
        }

        // Put fields in REST API
        $fields = $this->getFields();
        register_rest_field($this->getPostType(), 'fields', [
            'get_callback' => function($post) use($fields) {
                $meta_fields = get_post_meta($post['id'], '');
                $filtered_fields = array_filter($meta_fields, function($key) use($fields) {
                    return in_array($key, array_keys($fields));
                }, ARRAY_FILTER_USE_KEY);

                return array_map(function($field) {
                    return $field[0];
                }, $filtered_fields);
            }
        ]);

        // Add human date to REST API
        register_rest_field($this->getPostType(), 'human_date', [
            'get_callback' => function($post) {
                return get_the_date('', $post['id']);
            }
        ]);
    }


    /**
     * Add REST endpoints as routes
     * Adapted from https://github.com/WP-API/WP-API/issues/2308#issuecomment-266107899
     *
     * @return void
     */
    public function restPostMetaEndpoints($routes) {
        // Only modify routes for this post type
        $route_name = '/wp/v2/' . $this->getRestBase();
        if (empty($routes['/wp/v2/' . $this->getRestBase()])) {
            return $routes;
        }

        // Make sure to use the correct one of meta_value or meta_value_num for numbers in front end
        $routes[$route_name][0]['args']['orderby']['enum'][] = 'meta_value';
        $routes[$route_name][0]['args']['orderby']['enum'][] = 'meta_value_num';

        // Allow only the meta keys that I want
        $routes[$route_name][0]['args']['meta_key'] = [
            'description'       => 'The meta key to query.',
            'type'              => 'string',
            'enum'              => array_keys($this->getFields()),
            'validate_callback' => 'rest_validate_request_arg',
        ];

        // Allow meta_query.  Pass in as a JSON string
        $routes[$route_name][0]['args']['meta_query'] = [
            'description'       => 'A WordPress meta query.',
            'type'              => 'string',
            'validate_callback' => 'rest_validate_request_arg',
        ];

        return $routes;
    }


    /**
     * Map REST params to query params
     * Adapted from https://github.com/WP-API/WP-API/issues/2308#issuecomment-266107899
     *
     * @return void
     */
    public function restMetaKeyMap($args, $request) {
        if ($key = $request->get_param('meta_key')) {
	        $args['meta_key'] = $key;
	    }

        if ($key = $request->get_param('meta_value')) {
	        $args['meta_value'] = $key;
	    }

        if ($key = $request->get_param('meta_query')) {
	        $args['meta_query'] = $key;
	    }

	    return $args;
    }


    /**
     * Get the taxonomies
     * @return array
     */
    public function getTaxonomies()
    {
        return ($this->getRealPostType() === 'post') ? array('category') : array();
    }


    /**
     * Get a taxonomy by name
     * @param string $key
     * @return array
     */
    public function getTaxonomy($key)
    {
        $taxonomies = $this->getTaxonomies();
        if (!Arr::iterable($taxonomies)) {
            return false;
        }

        $taxonomy = (array_key_exists($key, $taxonomies)) ? $taxonomies[$key] : false;
        if (!$taxonomy) {
            return false;
        }

        // Handle all of these:
        // return array('one', 'two', 'three');
        // return array('one'=>'One Category', 'two', 'three');
        // return array(
        //     'one'=>array('label'=>'One Category'),
        //     'two'=>array('rewrite'=>array('slug'=>'foobar')),
        //     'three'
        // );
        if (is_string($taxonomy)) {
            $taxonomy = (is_numeric($key))
                ? array('label'=>self::getGeneratedTaxonomyLabel($taxonomy))
                : array('label'=>$taxonomy);
        } elseif (is_array($taxonomy) && !array_key_exists('label', $taxonomy)) {
            $taxonomy['label'] = self::getGeneratedTaxonomyLabel($key);
        }

        // Unlike WordPress default, we'll default to hierarchical=true
        // That's just more common for us
        if (!array_key_exists('hierarchical', $taxonomy)) {
            $taxonomy['hierarchical'] = true;
        }

        // Default to show all taxonomies in REST API
        $taxonomy['show_in_rest'] = true;

        return $taxonomy;
    }


    /**
     * Get an autogenerated taxonomy label
     * @param string $str
     * @return string
     */
    public static function getGeneratedTaxonomyLabel($str)
    {
        return Str::human(str_replace('-', ' ', $str));
    }


    /**
     * Get the taxonomy keys
     * @return array
     */
    public function getTaxonomyKeys()
    {
        $taxonomies = $this->getTaxonomies();
        if (!Arr::iterable($taxonomies)) {
            return array();
        }

        $out = array();
        foreach ($taxonomies as $k => $taxonomy) {
            $taxonomy = $this->getTaxonomy($k);
            $out[] = $this->getTaxonomyKey($k, $taxonomy);
        }
        return $out;
    }


    /**
     * Get a taxonomy key
     * @param string $key
     * @param array $taxonomy
     * @return string
     */
    public function getTaxonomyKey($key, $taxonomy = array())
    {
        if (is_string($key)) {
            return $key;
        }
        if (is_array($taxonomy) && array_key_exists('label', $taxonomy)) {
            return Str::machine($taxonomy['label'], Base::SEPARATOR);
        }
        return $key;
    }


    /**
     * Get the taxonomy info
     * @return array
     */
    public function getTaxonomiesInfo()
    {
        $taxonomies = $this->getTaxonomies();
        if (!Arr::iterable($taxonomies)) {
            return array();
        }

        $out = array();
        foreach ($taxonomies as $k => $taxonomy) {
            $taxonomy = $this->getTaxonomy($k);
            $key = $this->getTaxonomyKey($k, $taxonomy);
            $out[] = array(
                'key'       => $key,
                'post_type' => $this->getPostType(),
                'config'    => $taxonomy
            );
        }
        return $out;
    }


    /**
     * Get hierarchical
     */
    public function getHierarchical()
    {
        return false;
    }


    /**
     * Get the post type config
     * @return array
     */
    public function getPostTypeConfig()
    {
        if (in_array($this->getPostType(), array('post', 'page'))) {
            return null;
        }

        return array(
            'labels' => array(
                'name'              => _x($this->getPlural(), 'post type general name'),
                'singular_name'     => _x($this->getSingular(), 'post type singular name'),
                'add_new'           => _x('Add New', $this->getSingular()),
                'add_new_item'      => __(sprintf('Add New %s', $this->getSingular())),
                'edit_item'         => __(sprintf('Edit %s', $this->getSingular())),
                'new_item'          => __(sprintf('New %s', $this->getPlural())),
                'view_item'         => __(sprintf('View %s', $this->getSingular())),
                'search_items'      => __(sprintf('Search %s', $this->getPlural())),
                'not_found'         => __(sprintf('No %s found', $this->getPlural())),
                'not_found_in_trash'=> __(sprintf('No %s found in Trash', $this->getPlural())),
                'parent_item_colon' => ''
            ),
            'hierarchical'        => $this->getHierarchical(),
            'public'              => $this->getPublic(),
            'supports'            => $this->getSupports(),
            'show_in_menu'        => $this->getShowInMenu(),
            'show_in_admin_bar'   => $this->getShowInAdminBar(),
            'show_in_rest'        => $this->getShowInRest(),
            'rest_base'           => $this->getRestBase(),
            'menu_icon'           => $this->getMenuIcon(),
            'menu_position'       => $this->getMenuPosition(),
            'exclude_from_search' => $this->getExcludeFromSearch(),
            'has_archive'         => $this->getHasArchive(),
            'rewrite'             => $this->getRewrite(),
            'publicly_queryable'  => $this->getPubliclyQueryable(),
        );
    }


    /**
     * Get the lower case machine plural to use for REST to be consistent
     *
     * @return string
     */
    public function getRestBase()
    {
        return strtolower(Str::machine($this->getPlural()));
    }


    /**
     * Is this post type public?
     * If not, users trying to visit the URL for a post of this type will get a 404
     * @return bool
     */
    public function getPublic()
    {
        return true;
    }


    /**
     * Does this have an archive?
     * @return bool
     */
    public function getHasArchive()
    {
        return false;
    }


    /**
     * Get any applicable rewrites
     * The default behavior is for the post type slug to be used as the rewrite
     * @return mixed
     */
    public function getRewrite()
    {
        return true;
    }


    /**
     * Should this post type be publicly queryable?
     * @return bool
     */
    public function getPubliclyQueryable()
    {
        return true;
    }


    /**
     * Get the menu icon
     * @return string
     */
    public function getMenuIcon()
    {
        // Look for these files by default
        // If your plugin directory contains an [post-type].png file, that will by default be the icon
        // Ex: hot-sauce.png
        $reflector = new \ReflectionClass(get_called_class());
        $dir = basename(dirname($reflector->getFileName()));
        $post_type = $this->getPostType();
        $fnames = array(
            $post_type.'.png',
            $post_type.'.gif',
            $post_type.'.jpg'
        );
        foreach ($fnames as $fname) {
            $fpath = sprintf('%s/%s/%s', WP_PLUGIN_DIR, $dir, $fname);
            if (!file_exists($fpath)) {
                continue;
            }

            return sprintf('%s/%s/%s', WP_PLUGIN_URL, $dir, $fname);
        }
        return '';
    }


    /**
     * Show in the admin menu?
     * @return bool
     */
    public function getShowInMenu()
    {
        return true;
    }


    /**
     * Show in the admin bar?
     * @return bool
     */
    public function getShowInAdminBar()
    {
        return true;
    }


    /**
     * Show in the REST API?
     * @return bool
     */
    public function getShowInRest()
    {
        return true;
    }


    /**
     * Get menu position
     * @return integer
     */
    public function getMenuPosition()
    {
        return null;
    }


    /**
     * Sort the admin columns if necessary
     * @param array $vars
     * @return array
     */
    public function sortAdminColumns($vars)
    {
        if (!isset($vars['orderby'])) {
            return $vars;
        }

        $admin_columns = $this->getAdminColumns();
        if (!Arr::iterable($admin_columns)) {
            return $vars;
        }

        foreach ($admin_columns as $k) {
            if ($vars['orderby'] !== $k) {
                continue;
            }

            $vars = array_merge($vars, array(
                'meta_key'=> $k,
                'orderby' => 'meta_value'
            ));

            break;
        }
        return $vars;
    }


    /**
     * Make the admin taxonomy columns sortable
     * Admittedly this is a bit hackish
     * @link http://wordpress.stackexchange.com/questions/109955/custom-table-column-sortable-by-taxonomy-query
     * @param array $clauses
     * @param object $wp_query
     * @return array
     */
    public function makeAdminTaxonomyColumnsSortable($clauses, $wp_query)
    {
        global $wpdb;

        // Not sorting at all? Get out.
        if (!array_key_exists('orderby', $wp_query->query)) {
            return $clauses;
        }
        if ($wp_query->query['orderby'] !== 'meta_value') {
            return $clauses;
        }
        if (!array_key_exists('meta_key', $wp_query->query)) {
            return $clauses;
        }

        // No taxonomies defined? Get out.
        $taxonomies = $this->getTaxonomies();
        if (!Arr::iterable($taxonomies)) {
            return $clauses;
        }

        // Not sorting by a taxonomy? Get out.
        $sortable_taxonomy_key = null;
        foreach ($taxonomies as $taxonomy_key => $taxonomy) {
            $taxonomy_key = (is_int($taxonomy_key)) ? $taxonomy : $taxonomy_key;
            if ($wp_query->query['meta_key'] !== $taxonomy_key) {
                continue;
            }

            $sortable_taxonomy_key = $taxonomy_key;
            break;
        }
        if (!$sortable_taxonomy_key) {
            return $clauses;
        }
        if ($wp_query->query['meta_key'] !== $sortable_taxonomy_key) {
            return $clauses;
        }

        // Now we know which taxonomy the user is sorting by
        // but WordPress will think we're sorting by a meta_key.
        // Correct for this bad assumption by WordPress.
        $clauses['where'] = str_replace(
            array(
                "AND ({$wpdb->postmeta}.meta_key = '".$taxonomy."' )",
                "AND ( \n  {$wpdb->postmeta}.meta_key = '".$taxonomy."'\n)"
            ),
            '',
            $clauses['where']
        );

        // This is how we find the posts
        $clauses['join'] .= "
            LEFT OUTER JOIN {$wpdb->term_relationships} ON {$wpdb->posts}.ID={$wpdb->term_relationships}.object_id
            LEFT OUTER JOIN {$wpdb->term_taxonomy} USING (term_taxonomy_id)
            LEFT OUTER JOIN {$wpdb->terms} USING (term_id)
        ";
        $clauses['where'] .= "AND (taxonomy = '".$taxonomy."' OR taxonomy IS NULL)";
        $clauses['groupby'] = "object_id";
        $clauses['orderby'] = "GROUP_CONCAT({$wpdb->terms}.name ORDER BY name ASC)";
        $clauses['orderby'] .= (strtoupper($wp_query->get('order')) == 'ASC') ? 'ASC' : 'DESC';
        return $clauses;
    }


    /**
     * Get the admin columns
     * @return array
     */
    public function getAdminColumns()
    {
        return array_merge(
            array_keys($this->getFields()),
            $this->getTaxonomyKeys()
        );
    }


    /**
     * Hide the title from admin columns?
     * @return bool
     */
    public function getHideTitleFromAdminColumns()
    {
        if (in_array('title', $this->getAdminColumns())) {
            return false;
        }

        $supports = $this->getSupports();
        if (is_array($supports) && in_array('title', $supports)) {
            return false;
        }

        return true;
    }


    /**
     * Add meta boxes
     */
    public function addMetaBoxes()
    {
        $meta_boxes = $this->getMetaBoxes();
        $meta_boxes = $this->replaceMetaBoxGroupMatches($meta_boxes);
        if ($meta_boxes === self::METABOX_GROUPING_PREFIX) {
            $meta_boxes = $this->getPrefixGroupedMetaBoxes();
        }

        $post_type = $this->getPostType();
        foreach ($meta_boxes as $k => $config) {
            $config = $this->getMetaBoxConfig($config, $k);
            if (!array_key_exists('fields', $config)) {
                continue;
            }
            if (!Arr::iterable($config['fields'])) {
                continue;
            }

            add_meta_box(
                sprintf('%s_%s', $post_type, Str::machine($k)), // id
                $config['title'],                 // title
                array(&$this, 'renderMetaBox'),   // callback
                $post_type,                       // post_type
                $config['context'],               // context
                $config['priority'],              // priority
                $config                           // callback_args
            );
        }
    }


    /**
     * Get the post type
     * @return string
     */
    public function getPostType()
    {
        $called_class_segments = explode('\\', get_called_class());
        $class_name = end($called_class_segments);
        return (is_null($this->post_type))
            ? Str::machine(Str::camelToHuman($class_name), Base::SEPARATOR)
            : $this->post_type;
    }


    /**
     * The public facing post type
     * @return string
     */
    public function getPublicPostType()
    {
        return $this->getPostType();
    }


    /**
     * Get real post type (if this is a revision)
     */
    public function getRealPostType() {
        if (!empty($this->_real_post_type)) {
            return $this->_real_post_type;
        } else {
            return $this->getPostType();
        }
    }

    /**
     * Should this content type be excluded from search or no?
     * @return bool
     */
    public function getExcludeFromSearch()
    {
        return false;
    }


    /**
     * Get the pairs
     * @param array $args for get_posts()
     * @return array
     */
    public static function getPairs($args = array())
    {
        $called_class = get_called_class();
        $instance = Post\Factory::create($called_class);

        // Optimize the query if no args
        // Unfortunately, WP doesn't provide a clean way to specify which columns to select
        // If WP allowed that, this custom SQL wouldn't be necessary
        if (!Arr::iterable($args)) {
            global $wpdb;
            $sql = sprintf(
                "SELECT
                    p.ID,
                    p.post_title
                FROM $wpdb->posts p
                WHERE p.post_type = '%s'
                AND (p.post_status = 'publish')
                ORDER BY p.post_title ASC",
                $instance->getPostType()
            );
            $results = $wpdb->get_results($sql);
            if (!Arr::iterable($results)) {
                return array();
            }

            return array_combine(
                Collection::pluck($results, 'ID'),
                Collection::pluck($results, 'post_title')
            );
        }

        // Custom args provided
        $default_args = array(
            'post_type'  => $instance->getPostType(),
            'numberposts'=> -1,
            'order'      => 'ASC',
            'orderby'    => 'title',
        );
        $args = (Arr::iterable($args)) ? $args : $default_args;
        if (!array_key_exists('post_type', $args)) {
            $args['post_type'] = $instance->getPostType();
        }

        $all = get_posts($args);
        if (!Arr::iterable($all)) {
            return array();
        }

        return array_combine(
            Collection::pluck($all, self::ID),
            Collection::pluck($all, 'post_title')
        );
    }


    /**
     * Get by a key/val
     * Note: This only works with custom meta fields, not core fields
     * @param string $key
     * @param mixed $val
     * @param string $compare
     * @param mixed $args
     * @param bool $load_terms
     * @return array
     */
    public static function getPairsBy($key, $val, $compare = '=', $args = array())
    {
        $instance = Post\Factory::create(get_called_class());

        global $wpdb;
        if (in_array($key, static::getCoreFieldKeys())) {
            // Using a core field
            $sql = sprintf(
                "SELECT p.ID, p.post_title
                FROM $wpdb->posts p
                WHERE p.post_type = %s
                AND (p.post_status = 'publish')
                AND %s %s %s
                ORDER BY p.ID ASC",
                '%s',
                $key,
                $compare,
                '%s'
            );
            $prepared_sql = $wpdb->prepare($sql, $instance->getPostType(), $val);
        } else {
            // Number fields should be compared numerically, not alphabetically
            // Of course, this currently requires you to use type=number to achieve numeric sorting
            $fields = $instance->getFields();
            $field_is_numeric = ($fields[$key]['type'] === 'number');

            // Using a meta field
            $sql = sprintf(
                "
                SELECT p.ID, p.post_title
                FROM $wpdb->postmeta pm
                INNER JOIN $wpdb->posts p
                ON p.ID = pm.post_id
                WHERE p.post_type = %s
                AND (p.post_status = 'publish')
                AND pm.meta_key = %s
                AND %s %s %s
                ORDER BY p.ID ASC
                ",
                '%s',
                '%s',
                ($field_is_numeric) ? 'CAST(pm.meta_value AS DECIMAL)' : 'pm.meta_value',
                $compare,
                '%s'
            );
            $prepared_sql = $wpdb->prepare($sql, $instance->getPostType(), $key, $val);
        }
        $results = $wpdb->get_results($prepared_sql);
        $post_ids = Collection::pluck($results, 'ID');
        if (!Arr::iterable($args)) {
            if (!Arr::iterable($results)) {
                return array();
            }

            return array_combine(
                $post_ids,
                Collection::pluck($results, 'post_title')
            );
        }

        if (array_key_exists('post__in', $args)) {
            $args = (is_array($args)) ? $args : array_map('trim', explode(',', $args));
            $args['post__in'] = array_merge($args['post__in'], $post_ids);
        } else {
            $args['post__in'] = $post_ids;
        }

        // Need to use args? Call getBy.
        $records = static::getBy($key, $val, $compare, $args, false);
        if (!Arr::iterable($records)) {
            return array();
        }

        return array_combine(
            Collection::pluck($records, 'ID'),
            Collection::pluck($records, 'post_title')
        );
    }


    /**
     * Get all posts
     * @param bool $load_terms
     * @return array
     */
    public static function getAll($load_terms = true)
    {
        return static::getWhere(array(), $load_terms);
    }


    /**
     * Get posts with conditions
     * @param array $args
     * @param bool $load_terms
     * @return array
     */
    public static function getWhere($args = array(), $load_terms = true)
    {
        $instance = Post\Factory::create(get_called_class());

        // Allow sorting both by core fields and custom fields
        // See: http://codex.wordpress.org/Class_Reference/WP_Query#Order_.26_Orderby_Parameters
        $default_orderby = $instance->getDefaultOrderBy();
        $default_order = $instance->getDefaultOrder();
        $default_args = array(
            'post_type'  => $instance->getPostType(),
            'numberposts'=> -1,
            'orderby'    => $default_orderby,
            'order'      => $default_order,
        );

        // Sometimes you will specify a default orderby using getDefaultOrderBy
        if ($default_orderby !== 'menu_order') {
            $fields = $instance->getFields();
            if (array_key_exists($default_orderby, $fields)) {
                $default_args['meta_key'] = $default_orderby;

                // Number fields should be sorted numerically, not alphabetically
                // Of course, this currently requires you to use type=number to achieve numeric sorting
                $default_args['orderby'] = ($fields[$default_orderby]['type'] === 'number')
                    ? 'meta_value_num'
                    : 'meta_value';
            }
        }

        // But other times, you'll just pass in orderby via $args,
        // e.g. if you call getBy or getWhere with the $args param
        if (array_key_exists('orderby', $args)) {
            $fields = $instance->getFields();
            if (array_key_exists($args['orderby'], $fields)) {
                $args['meta_key'] = $args['orderby'];

                // Number fields should be sorted numerically, not alphabetically
                // Of course, this currently requires you to use type=number to achieve numeric sorting
                $args['orderby'] = ($fields[$args['orderby']]['type'] === 'number')
                    ? 'meta_value_num'
                    : 'meta_value';
            }
        }

        $criteria = array_merge($default_args, $args);
        return Post\Factory::createMultiple(get_posts($criteria), $load_terms);
    }


    /**
     * Get one post
     * @param array $args
     * @return object
     */
    public static function getOneWhere($args = array())
    {
        $args['numberposts'] = 1;
        $result = static::getWhere($args);
        return (count($result)) ? current($result) : null;
    }


    /**
     * Get by a key/val
     * @param string $key
     * @param mixed $val
     * @param string $compare
     * @param mixed $args
     * @param bool $load_terms
     * @return array
     */
    public static function getBy($key, $val, $compare = '=', $args = array(), $load_terms = true)
    {
        $instance = Post\Factory::create(get_called_class());

        // Hack to handle fields like post_date, post_title, etc.
        if (!in_array($key, static::getCoreFieldKeys())) {
            $args = array_merge($args, array(
                'meta_query'=>array(
                    array(
                        'key'=>$key,
                        'compare'=>$compare,
                        'value'=>$val
                    )
                ),
            ));
            return static::getWhere($args, $load_terms);
        }

        $orderby = (array_key_exists('orderby', $args) && in_array($args['orderby'], static::getCoreFieldKeys()))
            ? $args['orderby']
            : 'p.post_date';
        $order = (array_key_exists('order', $args) && in_array(strtoupper($args['order']), array('ASC', 'DESC')))
            ? $args['order']
            : 'DESC';

        // First we are going to get the post IDs for matching records.
        // Then we will append that to our $args so that the remaining filters (if applicable) can be applied
        global $wpdb;
        $sql = sprintf(
            "SELECT p.ID
            FROM $wpdb->posts p
            WHERE p.post_type = %s
            AND (p.post_status = %s)
            AND %s %s %s
            ORDER BY %s %s
            %s",
            '%s',
            '%s',
            $key,
            $compare,
            '%s',
            $orderby,
            $order,
            (array_key_exists('numberposts', $args))
                ? sprintf("LIMIT %d", (int) $args['numberposts'])
                : null
        );
        $prepared_sql = $wpdb->prepare(
            $sql,
            $instance->getPostType(),
            (array_key_exists('post_status', $args)) ? $args['post_status'] : 'publish',
            $val
        );
        $results = $wpdb->get_results($prepared_sql);
        if (!Arr::iterable($results)) {
            return $results;
        }

        $post_ids = Collection::pluck($results, 'ID');
        if (!Arr::iterable($args)) {
            return Post\Factory::createMultiple($post_ids, $load_terms);
        }

        if (array_key_exists('post__in', $args)) {
            $args = (is_array($args)) ? $args : array_map('trim', explode(',', $args));
            $args['post__in'] = array_merge($args['post__in'], $post_ids);
        } else {
            $args['post__in'] = $post_ids;
        }

        return static::getWhere($args, $load_terms);
    }


    /**
     * Get a single record by a key/val
     * Note: This only works with custom meta fields, not core fields
     * @param string $key
     * @param mixed $val
     * @param string $compare
     * @param mixed $args
     * @param bool $load_terms
     * @return array
     */
    public static function getOneBy($key, $val, $compare = '=', $args = array(), $load_terms = true)
    {
        $args['numberposts'] = 1;
        $result = static::getBy($key, $val, $compare, $args, $load_terms);
        return (count($result)) ? current($result) : null;
    }


    /**
     * Get by multiple conditions
     * Conditions get treated with AND logic
     * TODO Make this more efficient
     * @param array $conditions
     * @param mixed $args
     * @param bool $load_terms
     * @return array
     */
    public static function getByMultiple($conditions, $args = array(), $load_terms = true)
    {
        if (!Arr::iterable($conditions)) {
            return self::getWhere($args, $load_terms);
        }

        // Extract numberposts if passed in $args
        // because we don't want to prematurely restrict the result set.
        $numberposts = (array_key_exists('numberposts', $args))
            ? $args['numberposts']
            : null;
        unset($args['numberposts']);

        // First, get all the post_ids
        $post_ids = array();
        foreach ($conditions as $k => $condition) {
            // Conditions can have numeric or named keys:
            // ['key1', 'val1', '=']
            // ['key'=>'foo', 'val'=>'bar', '=']
            $condition_values = array_values($condition);
            $key = (array_key_exists('key', $condition)) ? $condition['key'] : $condition_values[0];
            $value = (array_key_exists('value', $condition)) ? $condition['value'] : $condition_values[1];

            // Make sure we have a compare
            $compare = '=';
            if (array_key_exists('compare', $condition)) {
                $compare = $condition['compare'];
            } elseif (array_key_exists(2, $condition_values)) {
                $compare = $condition_values[2];
            }

            // Get the posts from getBy
            // Trying to replicate getBy's logic could be significant
            // b/c it handles both core and meta fields
            $posts = self::getBy($key, $value, $compare, $args, false);

            // If no results, that means we found a condition
            // that was not met by any posts.
            // So we need to clear out $post_ids so that
            // we don't get false positives.
            if (!Arr::iterable($posts)) {
                $post_ids = array();
                break;
            }

            // Using array_intersect here gives us the AND relationship
            // array_merge would give us OR
            $new_post_ids = Collection::pluck($posts, 'ID');
            $post_ids = (Arr::iterable($post_ids))
                ? array_intersect($post_ids, $new_post_ids)
                : $new_post_ids;

            // If no overlap, we're done checking criteria
            if (count($post_ids) === 0) {
                break;
            }
        }

        // You can't do this within the loop b/c WordPress treats
        // post__in as an OR relationship when passed to get_posts
        if (Arr::iterable($post_ids)) {
            $args['post__in'] = $post_ids;
        }

        // Reapply numberposts now that we have our desired post_ids
        if (!is_null($numberposts)) {
            $args['numberposts'] = $numberposts;
        }

        return (Arr::iterable($post_ids))
            ? self::getWhere($args, $load_terms)
            : array();
    }


    /**
     * Get one by multiple conditions
     * Conditions get treated with AND logic
     * TODO Make this more efficient
     * @param array $conditions
     * @param mixed $args
     * @param bool $load_terms
     * @return array
     */
    public static function getOneByMultiple($conditions, $args = array(), $load_terms = true)
    {
        $default_args = array('numberposts'=>1);
        $args = array_merge($args, $default_args);
        $results = self::getByMultiple($conditions, $args, $load_terms);
        return (Arr::iterable($results))
            ? current($results)
            : null;
    }


    /**
     * Get by a taxonomy and term
     * @param string $taxonomy
     * @param mixed $terms
     * @param string $field
     * @param array $args
     * @param bool $load_terms
     * @return array
     */
    public static function getByTerm($taxonomy, $terms, $field = 'slug', $args = array(), $load_terms = true)
    {
        $args = array_merge($args, array(
            'tax_query'=>array(
                array(
                    'taxonomy'=>$taxonomy,
                    'terms'=>$terms,
                    'field'=>$field
                )
            ),
        ));
        return static::getWhere($args, $load_terms);
    }


    /**
     * Get one by a taxonomy and term
     * @param string $taxonomy
     * @param mixed $terms
     * @param string $field
     * @param array $args
     * @param bool $load_terms
     * @return object
     */
    public static function getOneByTerm($taxonomy, $terms, $field = 'slug', $args = array(), $load_terms = true)
    {
        $args['numberposts'] = 1;
        $result = static::getByTerm($taxonomy, $terms, $field, $args, $load_terms);
        return (count($result)) ? current($result) : null;
    }


    /**
     * Get results by page
     * @param int $page
     * @param array $args
     * @param string $sort
     * @param bool $load_terms
     */
    public static function getPage($page = 1, $args = array(), $load_terms = true)
    {
        $instance = Post\Factory::create(get_called_class());

        $criteria = array(
            'post_type' => $instance->getPostType(),
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $instance->getPostsPerPage(),
            'offset' => ($page - 1) * $instance->getPostsPerPage()
        );
        $criteria = array_merge($criteria, $args);
        return Post\Factory::createMultiple(get_posts($criteria), $load_terms);
    }


    /**
     * Get results by page
     * TODO Make this more efficient
     * @param array $args
     */
    public static function getPageCount($args = array())
    {
        $instance = Post\Factory::create(get_called_class());

        $posts_per_page = $instance->getPostsPerPage();
        $criteria = array(
            'post_type'=>$instance->getPostType(),
            'posts_per_page'=>-1
        );
        $criteria = array_merge($criteria, $args);

        $query = new \WP_Query($criteria);
        $n = 0;
        while ($query->have_posts()) {
            $n++;
            $query->next_post();
        }

        return ceil($n / $posts_per_page);
    }


    /**
     * Get count
     * TODO Make this more efficient
     * @param array $args
     */
    public static function getCount($args = array())
    {
        return count(static::getPairs($args));
    }


    /**
     * Get the posts per page
     * @return int
     */
    public function getPostsPerPage()
    {
        return get_option('posts_per_page');
    }


    /**
     * Set the terms
     * @param array $term_ids
     * @param string $taxonomy
     * @param bool $append
     * @return array
     */
    public function setTerms($term_ids, $taxonomy = null, $append = false)
    {
        $taxonomy = ($taxonomy) ? $taxonomy : 'post_tag';
        if (!is_array($this->_terms)) {
            $this->_terms = array();
        }
        if (!array_key_exists($taxonomy, $this->_terms)) {
            $this->_terms[$taxonomy] = array();
        }

        $this->_terms[$taxonomy] = ($append)
            ? array_merge($this->_terms[$taxonomy], $term_ids)
            : $term_ids;
        return $this->_terms[$taxonomy];
    }


    /**
     * Get the terms
     * @param string $taxonomy
     * @return array
     */
    public function getTerms($taxonomy = null)
    {
        if ($taxonomy) {
            return (array_key_exists($taxonomy, $this->_terms))
                ? $this->_terms[$taxonomy]
                : array();
        }

        return $this->_terms;
    }


    /**
     * Does this post have this term?
     * @param integer $term_id
     * @return bool
     */
    public function hasTerm($term_id)
    {
        $taxonomy_terms = $this->getTerms();
        if (!Arr::iterable($taxonomy_terms)) {
            return false;
        }

        foreach ($taxonomy_terms as $taxonomy_key => $terms) {
            if (!Arr::iterable($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if ((int) $term->term_id === (int) $term_id) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Get the permalink
     * @return string
     */
    public function getPermalink()
    {
        return get_permalink($this->get('ID'));
    }


    /**
     * Get the edit permalink
     * @link http://codex.wordpress.org/Template_Tags/get_edit_post_link
     * @param string $context
     * @return string
     */
    public function getEditPermalink($context = 'display')
    {
        return get_edit_post_link($this->get('ID'), $context);
    }


    /**
     * Get the title through the_title filter
     * @return string
     */
    public function getTheTitle()
    {
        return apply_filters('the_title', $this->get('post_title'), $this->get('ID'));
    }


    /**
     * Get the content through the_content filter
     * @return string
     */
    public function getTheContent()
    {
        return apply_filters('the_content', $this->get('post_content'));
    }


    /**
     * Get the excerpt through the_content filter
     * Accepted values for length_unit: 'char', 'word'
     * @param array $args
     * @return string
     */
    public function getTheExcerpt($args = array())
    {
        $default_args = array(
            'length' => 150,
            'length_unit' => 'char',
            'strip_shortcodes' => true,
            'hellip' => '&hellip;'
        );
        $args = (Arr::iterable($args))
            ? array_merge($default_args, $args)
            : $default_args;
        extract($args);

        $excerpt = $this->get('post_excerpt');
        if (!strlen($excerpt)) {
            $excerpt = strip_tags($this->get('post_content'));
            if ($length_unit == 'char') {
                $excerpt = Str::shortenWordsByChar($excerpt, $length, $hellip);
            } elseif ($length_unit == 'word') {
                $excerpt = Str::shortenWords($excerpt, $length, $hellip);
            }
        }
        $excerpt = apply_filters('the_excerpt', $excerpt);

        return ($strip_shortcodes) ? strip_shortcodes($excerpt) : $excerpt;
    }


    /**
     * Get the thumbnail (featured image)
     * @param string $size
     * @param string $alt
     * @param bool $use_alt_as_title
     * @return string HTML img tag
     */
    public function getThePostThumbnail($size = 'full', $alt = '', $use_alt_as_title = false)
    {
        if (!has_post_thumbnail($this->get('ID'))) {
            return false;
        }

        $thumbnail = get_the_post_thumbnail($this->get('ID'), $size, array(
            'title' => ($use_alt_as_title ? $alt : ''),
            'alt' => $alt
        ));
        return $thumbnail;
    }


    /**
     * Get the image attachment array for post's featured image
     * @param string $size
     * @param string $property
     * @return array or string
     */
    public function getPostAttachment($size = 'full', $property = null)
    {
        $post_id = $this->get('ID');
        if (!has_post_thumbnail($post_id)) {
            return false;
        }

        $attachment_id = get_post_thumbnail_id($post_id);
        $attachment_src = wp_get_attachment_image_src($attachment_id, $size);

        if (empty($attachment_id) || empty($attachment_src)) {
            return false;
        }

        $image_properties = array(
            'url',
            'width',
            'height',
            'is_resized'
        );
        $image_array = array_combine(
            $image_properties,
            array_values($attachment_src)
        );

        $attachment = get_post($attachment_id);
        $image_array['alt_text']    = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $image_array['description'] = apply_filters('the_content', $attachment->post_content);
        $image_array['caption']     = get_the_excerpt($attachment_id);
        $image_array['title']       = get_the_title($attachment_id);

        if (in_array($property, $image_properties)) {
            return $image_array[$property];
        }
        return $image_array;
    }


    /**
     * Get the image attachment URL for post's featured image
     * @param string $size
     * @return string
     */
    public function getPostAttachmentURL($size = 'full')
    {
        return $this->getPostAttachment($size, 'url');
    }


    /**
     * Get the anchor tag
     * @param string $field_key
     * @return string HTML <a>
     */
    public function getAnchorTag($field_key = 'post_title')
    {
        return parent::getAnchorTag($field_key);
    }


    /**
     * Delete the post
     * @param $bypass_trash (aka force_delete per wp_delete_post)
     * @return bool
     */
    public function delete($bypass_trash = false)
    {
        return wp_delete_post($this->get('ID'), $bypass_trash);
    }


    /**
     * Get the default order by
     * @return string
     */
    public function getDefaultOrderBy()
    {
        return 'menu_order';
    }


    /**
     * Get the default order
     * @return string
     */
    public function getDefaultOrder()
    {
        return 'ASC';
    }


    /**
     * Render a public field
     * @param string $key See code below for accepted vals
     * @param array $field
     * @param bool $load_value
     * @return string
     */
    public function getRenderPublicField($key, $field = null, $load_value = true)
    {
        $class = get_called_class();
        if ($key === self::KEY_CLASS) {
            $attribs = array('type'=>'hidden', 'name'=>$key, 'value'=>$class);
            return Html::tag('input', null, $attribs);
        }
        if ($key === self::KEY_NONCE) {
            $attribs = array('type'=>'hidden', 'name'=>$key, 'value'=>wp_create_nonce($this->getNonceAction()));
            return Html::tag('input', null, $attribs);
        }

        if ($load_value) {
            if (!is_array($field)) {
                $field = self::getField($key);
            }
            if (!array_key_exists('value', $field)) {
                $field['value'] = $this->$key;
            }
        }
        return self::getRenderMetaBoxField($key, $field);
    }


    /**
     * Get the nonce/CSRF action
     * @return string
     */
    public function getNonceAction()
    {
        return md5(join('_', array(
            __FILE__,
            get_called_class(),
            md5_file(__FILE__),
            $this->getPostType()
        )));
    }


    /**
     * Verify the nonce
     * @param string $nonce
     * @return bool
     */
    public function verifyNonce($nonce)
    {
        return wp_verify_nonce($nonce, $this->getNonceAction());
    }


    /**
     * Get the public form key
     * This is useful for integrations with FlashData and the like
     * when you want to persist data from the form to another page.
     * For instance, in the case of error messages and form values.
     * @param string $suffix
     * @return string
     */
    public function getPublicFormKey($suffix = null)
    {
        $val = sprintf('%s_public_form', $this->getPostType());
        return ($suffix) ? sprintf('%s_%s', $val, $suffix) : $val;
    }


    /**
     * Delete all the posts
     * TODO Make this more efficient
     * @param $bypass_trash (aka force_delete per wp_delete_post)
     * @return integer Number of posts deleted
     */
    public static function deleteAll($bypass_trash = false)
    {
        $num_deleted = 0;

        $all = static::getAll(false);
        if (!Arr::iterable($all)) {
            return $num_deleted;
        }

        foreach ($all as $post) {
            if ($post->delete($bypass_trash)) {
                $num_deleted++;
            }
        }

        return $num_deleted;
    }


    /**
     * Find a post
     * @param integer $post_id
     * @return object
     */
    public static function find($post_id, $load_terms = true)
    {
        $instance = Post\Factory::create(get_called_class());
        $instance->load($post_id, $load_terms);
        return $instance;
    }


    /**
     * Decode an encoded string into a link object
     * @param string $encoded
     * @return array
     */
    public static function decodeLinkObject($encoded)
    {
        return json_decode(stripslashes(urldecode($encoded)));
    }


    /**
     * Get a decoded object from an encoded string by field name
     * @param string $field
     * @return array
     */
    public function getDecodedLinkObjectFromField($field)
    {
        return json_decode(stripslashes(urldecode($this->get($field))));
    }


    /**
     * Get just the URL from a field type of link
     * @param string $field
     * @return string
     */
    public function getLinkURL($field)
    {
        $link_attr = self::decodeLinkObject($this->get($field));

        if(!is_object($link_attr)) {
            return $this->get($field);
        }
        if(!(strlen($link_attr->href) && strlen($link_attr->title) && strlen($link_attr->target))) {
            $field_attribs = $this->getField($field);
            if (array_key_exists('default', $field_attribs)) return $field_attribs['default'];
        }
        return $link_attr->href;
    }


    /**
     * Get a field from a link object (href | title or body | target)
     * @param string $field
     * @param string $part
     * @return string
     */
    public function getLinkPart($field, $part)
    {
        $link_attr = self::decodeLinkObject($this->get($field));
        if ($part === 'body') {
            $part = 'title';
        }
        return $link_attr->$part;
    }


    /**
     * Get an HTML link from attributes that come from a link object
     * This is mainly used with the field type of link
     * @param object $link_attr
     * @param string $body
     * @param string $classes
     * @param string $id
     * @param string $styles
     * @return string
     */
    public function linkAttribsToHTMLString($link_attr, $body = '', $classes = '', $id = '', $styles = '')
    {
        $link_text = null;
        if (strlen($link_attr->title)) {
            $link_text = $link_attr->title;
        } elseif (strlen($body)) {
            $link_text = $body;
        } else {
            $link_text = $link_attr->href;
        }
        return Html::link(
            $link_attr->href,
            $link_text,
            array(
                'title'  => $link_attr->title,
                'target' => $link_attr->target,
                'class'  => $classes,
                'id'     => $id,
                'style'  => $styles
            )
        );
    }


    /**
     * Get a field from a link object (href | title | target)
     * @param string $field
     * @param string $part
     * @return string
     */
    public function getLinkHTML($field, $body = '', $classes = '', $id = '', $styles = '')
    {
        $link_attr = self::decodeLinkObject($this->get($field));
        return self::linkAttribsToHTMLString(
            $link_attr,
            $body,
            $classes,
            $id,
            $styles
        );
    }


    /**
     * Get an HTML link from an encoded link object
     * @param string $oject_string
     * @param string $body
     * @param string $classes
     * @param string $id
     * @param string $styles
     * @return string
     */
    public static function getLinkHTMLFromObject($object_string, $body = '', $classes = '', $id = '', $styles = '')
    {
        return self::linkAttribsToHTMLString(
            self::decodeLinkObject($object_string),
            $body,
            $classes,
            $id,
            $styles
        );
    }
}
