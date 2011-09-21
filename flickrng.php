<?php
/*
Plugin Name: Flickrng
Plugin URI: http://www.bundy.ca
Description: Flickrng
Version: 0.1
Author: Mitchell Bundy
Author URI: http://www.bundy.ca/
*/

require_once('phpFlickr/phpFlickr.php');
require_once(ABSPATH.'wp-includes/class-oembed.php');
require_once(ABSPATH.'wp-admin/includes/image.php');

if (!class_exists('flickrng')) {
	class flickrng extends phpFlickr {
		
		var $options = array(
			'api' => NULL,
			'sizes' => array(
				'thumbnail' => array(
					'map_to' => false,
					'import' => true
				),
				'medium' => array(
					'map_to' => 'Medium 640'
				),
				'large' => array(
					'map_to' => 'Large'
				)
			),
			'sync_set' => false
		);
		
		var $flickr_sizes = array(
			'Small', 
			'Thumbnail',
			'Medium',
			'Medium 640',
			'Large'
		);
		
		function flickrng() {
			// Get options
			$this->options = get_option('flickrng', $this->options);
			
			// Initialize the parent class with the Flickr API Key
			parent::__construct($this->options['api']);
			
			add_action('init', array($this, 'wp_init'));
			
			// Add Upload Tab
			add_filter('media_upload_tabs', array($this, 'media_upload_tabs'));
			add_action("media_upload_type_flickr", array($this, 'flickr_tab'));
			
			// Check if the image is Flickrd or not
			add_filter('image_downsize', array($this, 'image_downsize'), 10, 3);
			add_filter('attachment_fields_to_edit', array($this, 'attachment_fields_to_edit'), 99, 2);
			
			// Admin Menu
			add_action('admin_menu', array($this, 'admin_menu'));
			
			// Filter wp_get_attachment_link
			add_filter('wp_get_attachment_link', array($this, 'wp_get_attachment_link'), 10, 2);
			
			
			// TODO: Disallow edit image.
			// TODO: Flickr icon/identifier in media manager.
			// TODO: Auto-sync flickr sets
			// TODO: Disallow field edits when syncing with Flickr
			// TODO: Make admin page pretty.
			// TODO: "Don't link to post"
			// TODO: hook into "insert into post"
		}
		
		function wp_init() {
			
		}
		
		function update_options() {
			update_option('flickrng', $this->options);
		}
		
		function get_image_size( $name ) {
			global $_wp_additional_image_sizes;
		
			if ( isset( $_wp_additional_image_sizes[$name] ) )
				return $_wp_additional_image_sizes[$name];
			else if (in_array($name, array('thumbnail','medium', 'large'))) {
				$width = get_option($name.'_size_w');
				$height = get_option($name.'_size_h');
				$crop = get_option($name.'_crop', false);
				return array(
					'width' => $width,
					'height' => $height,
					'crop' => $crop
				);
			}
		
			return false;
		}
		
		function get_image_sizes() {
			$imagesizes = get_intermediate_image_sizes();
			$sizes = array();
			foreach ($imagesizes as $size) {
				$sizes[$size] = $this->get_image_size($size);
			}
			return $sizes;
		}
		
		function admin() {
			if ($_POST['save']) {
				if ( !wp_verify_nonce( $_REQUEST['flickrng_noncename'], plugin_basename(__FILE__) )) {
					wp_die(__('Invalid Nonce', 'flickrng'),__('Nu uh.', 'flickrng'), true);
				}
				
				$this->options['api'] = $_POST['api_key'];
				
				foreach ($_POST['sizes'] as $size => $do) {
					if (!empty($do)) {
						if ($do == 'resize') {
							$this->options['sizes'][$size] = array(
								'map_to' => false,
								'import' => true
							);
						} else {
							$this->options['sizes'][$size] = array(
								'map_to' => $do
							);
						}
					} else unset($this->options['sizes'][$size]);
				}
				$this->update_options();
			}
			?><div class="wrap">
            <?php screen_icon(); ?>
			<h2><?php _e('Flickrng', 'flickrng'); ?></h2>
            <form action="<?=admin_url('options-general.php?page=flickrng')?>" method="post">
            <?php echo '<input type="hidden" name="flickrng_noncename" id="flickrng_noncename" value="' . wp_create_nonce( plugin_basename(__FILE__) ) . '" />' ?>
            <?php _e('API Key', 'flickrng'); ?> <input type="text" name="api_key" id="api_key" value="<?=$this->options['api']?>" /><br /><br />
            <?php
			foreach ($this->get_image_sizes() as $size => $meta) {
				?><label for="size-<?=$size?>">
				<?=$size?> (<?=$meta['width']?>x<?=$meta['height']?>)
                <select name="sizes[<?=$size?>]" id="size-<?=$size?>">
                	<option value="">- <?php _e('Do Nothing') ?> -</option>
                	<?php foreach ($this->flickr_sizes as $fsize) echo '<option value="'.$fsize.'"'.(($this->options['sizes'][$size]['map_to'] == $fsize) ? ' selected="selected"' : '').'>'.$fsize.'</option>'; ?> 
                    <option value="resize"<?php if (!$this->options['sizes'][$size]['map_to'] && $this->options['sizes'][$size]['import']) echo ' selected="selected"'; ?>>- <?php _e('Force Resize'); ?> -</option>
                </select>
                </label><br /><?php
			}
			?>
            <input type="submit" class="button" value="Save" name="save" />
            </form>
			</div><?php
		}
		
		function admin_menu() {
			add_options_page(__('Flickrng', 'flickrng'), __('Flickrng', 'flickrng'), 'manage_options', 'flickrng', array($this, 'admin'));
		}
		
		
		function wp_get_attachment_link($html, $id) {
			$meta = wp_get_attachment_metadata($id);
			if ($meta['flickrng']) { 
				$set_id = get_post_meta($id, 'flickrng_set_id', true);
				$url = $meta['flickrng']['data']['urls']['url'][0]['_content'];
				if ($set_id) $url .= 'in/set-'.$set_id.'/';
				if ($meta['sizes']['large']['flickrng'])
					$html = preg_replace('#href=\'(.*)\'#i', "href='".$meta['sizes']['large']['source']."'", $html);
				$html .= "<input type='hidden' name='flickr_url' value='$url' /></a>";
			}
			return $html;
		}
		
		function attachment_fields_to_edit($form_fields, $post) {
			$meta = wp_get_attachment_metadata($post->ID);
			if ($meta['flickrng']) {
				$form_fields['url']['html'] = $form_fields['url']['html']
				. "<button type='button' class='button urlfile' title='" . esc_attr($post->guid) . "'>" . __('Flickr URL') . "</button>";
			}
			
			$set_id =  get_post_meta($post->ID, 'flickrng_set_id', true);
			if ($set_id && $this->options['sync_set']) {
				// Set fields to disabled
				$form_fields['post_title']['input'] = 'html';
				$form_fields['post_title']['html'] = '<input type="text" class="text" id="attachments['.$post->ID.'][post_title]" value="'.$form_fields['post_title']['value'].'" disabled="disabled">';
				
				$form_fields['image_alt']['input'] = 'html';
				$form_fields['image_alt']['html'] = '<input type="text" class="text" id="attachments['.$post->ID.'][image_alt]" value="'.$form_fields['image_alt']['value'].'" disabled="disabled">';
				
				$form_fields['post_excerpt']['input'] = 'html';
				$form_fields['post_excerpt']['html'] = '<input type="text" class="text" id="attachments['.$post->ID.'][post_excerpt]" value="'.$form_fields['post_excerpt']['value'].'" disabled="disabled">';
				
				$form_fields['post_content']['input'] = 'html';
				$form_fields['post_content']['html'] = '<textarea id="attachments['.$post->ID.'][post_content]" disabled="disabled">'.$form_fields['post_content']['value'].'</textarea>';
				
				// Add set ID
				$new_form = array(
					'flickr_set_id' => array(
						'label' => __('Flickr Set ID', 'flickrng'),
						'input' => 'html',
						'html' => $set_id
					)
				);	
				
				$form_fields = $new_form + $form_fields;
			}
			return $form_fields;
		}
		
		function image_downsize($return, $id, $size) {
			$meta = wp_get_attachment_metadata($id);
			if (is_array($size)) return $return;
			if ($meta && $meta['sizes'][$size]['flickrng']) {
				return array( $meta['sizes'][$size]['source'], $meta['sizes'][$size]['width'], $meta['sizes'][$size]['height'], true );
			}
			return $return;
		}
	
		function media_upload_tabs($tabs) {
			$newtabs = array();
			foreach ($tabs as $key => $tab) {
				$newtabs[$key] = $tab;
				if ($key == 'type_url')
					$newtabs['type_flickr'] = __('From Flickr', 'flickrng');
			}
			return $newtabs;
		}
		
		function attach_photo($photo_id, $post_id, &$error = '') {
			global $wpdb;
			$photo = $this->photos_getInfo($photo_id);
			$photo = $photo['photo'];
					
			// Load image and import the sizes we may need.
			if (!count($photo) || !is_array($photo)) return false;
			if (!$photo['id']) return false;
			
			// Get the sizes
			$sizes = $this->photos_getSizes($photo['id']);
			
			// Upload path
			$uploads = wp_upload_dir();
			
			
			// Grab and save image, use largest
			$imageurl = $sizes[count($sizes) - 1]['source'];
			$filename = wp_unique_filename( $uploads['path'], basename($imageurl), $unique_filename_callback = null );
			$wp_filetype = wp_check_filetype($filename);
			
			$image_string = $this->fetch($imageurl);
			$fileSaved = file_put_contents($uploads['path'] . "/" . $filename, $image_string);
			
			// Check if attachment is already attached to current post. If so, return false
			$guid = $photo['urls']['url'][0]['_content'];
			$check = $wpdb->get_var($wpdb->prepare("SELECT count(*) AS count FROM $wpdb->posts WHERE post_parent = $post_id AND guid = '$guid'"));
			if ($check > 0) return false;
			
			// Add Attachment to database
			$attachment = array(
				 'post_mime_type' => $wp_filetype['type'],
				 'post_title' => $photo['title'],
				 'post_excerpt' => $photo['title'],
				 'post_content' => $photo['description'],
				 'post_status' => 'inherit',
				 'guid' => $guid
			);
			$attach_id = wp_insert_attachment( $attachment, $uploads['path'] . "/" . $filename, $post_id );
			
			// Generate images and save metadata.
			// Filter the image sizes, only resize what we need.
			add_filter('intermediate_image_sizes_advanced', array($this, 'image_sizes'));
			$attach_data = wp_generate_attachment_metadata( $attach_id, $uploads['path'] . "/" . $filename );
			
			// Build WordPress attachment meta array.
			$attach_data['flickrng'] = array('data' => $photo, 'sizes' => $sizes);
			
			// Fill in where WordPress left off
			foreach ($this->options['sizes'] as $size => $params) {
				if (!isset($attach_data['sizes'][$size]) && $params['map_to']) {
					foreach ($sizes as $fsize => $val) {
						if ($val['label'] == $params['map_to']) $key = $fsize;
					}
					
					$attach_data['sizes'][$size] = array(
						'width' => $sizes[$key]['width'],
						'height' => $sizes[$key]['height'],
						'source' => $sizes[$key]['source'],
						'flickrng' => true
					);
				}
			}
			
			// Add title from Flickr
			$attach_data['image_meta']['caption'] = $photo['title'];
			
			wp_update_attachment_metadata( $attach_id,  $attach_data );
			return $attach_id;
		}
		
		function image_sizes($sizes) {
			$the_sizes = array();
			foreach ($this->options['sizes'] as $size => $params) {
				if ($params['import'] && $sizes[$size])
					$the_sizes[$size] = $sizes[$size];
			}
			return $the_sizes;
		}
		
		function fetch($url) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$image = curl_exec($ch);
			curl_close($ch);
			return $image;
		}
		
		/* function make_url($farm_id, $server_id, $id, $secret, $size = '', $extension = 'jpg') {
			$url = 'http://farm'.$farm_id.'.static.flickr.com/'.$server_id.'/'.$id.'_'.$secret;
			if (in_array($size, array('m','s','t','z','b','o'))) $url .= '_'.$size;
			if ($size == 'o') $url .= '.'.$extension;
			else $url .= '.jpg';
			return $url;
		} */
		
		function flickr_tab() {
			$errors = array();
			$id = 0;
			
			if ( isset($_POST['html-upload']) && !empty($_FILES) ) {
				check_admin_referer('media-form');
			}
			
			$post_id = isset( $_REQUEST['post_id'] )? intval( $_REQUEST['post_id'] ) : 0;
			$url = $_POST['url'];

			if ($_POST['import'] && $_POST['type'] == 'single') {
				// Get single photo
				// Fetch from oembed, good way to check validity

				$oembed = new WP_oEmbed;
				$fetch = $oembed->fetch('http://www.flickr.com/services/oembed/', $url);
				
				if ($fetch && preg_match('#static.flickr.com/([0-9]*)/([0-9]*)_(.*)#i', $fetch->url, $matches)) {
					$photo_id = $matches[2];
					// Attach the photo
					$result = $this->attach_photo($photo_id, $post_id, $error);
				}
			} else if ($_POST['import'] && $_POST['type'] == 'set') {
				// Get set of photos
				
				if (preg_match('#sets/([0-9]*)#i', $url, $matches)) {
					$set_id = $matches[1];
					$set = $this->photosets_getPhotos($set_id);

					if (count($set['photoset']['photo'])) {
						// Reverse the array to maintain order
						foreach (array_reverse($set['photoset']['photo']) as $photo) {
							// Attach the photo
							$result = $this->attach_photo($photo['id'], $post_id, $error);
							add_post_meta($result, 'flickrng_set_id', $set_id, true);
						}
						
						// Add post meta referencing the Flickr Set ID
						add_post_meta($post_id, 'flickrng_set_id', $set_id);
					}
				}
			}
			
			return wp_iframe( array($this, 'media_flickr_form'), 'image', $errors, $id );
		}
		
		function media_flickr_form($type = 'file', $errors = null, $id = null) {
			media_upload_header();
			
			$post_id = isset( $_REQUEST['post_id'] )? intval( $_REQUEST['post_id'] ) : 0;

			$form_action_url = admin_url("media-upload.php?type=$type&tab=type_flickr&post_id=$post_id");
			?>
            <form enctype="multipart/form-data" method="post" action="<?php echo esc_attr($form_action_url); ?>" class="media-upload-form type-form validate" id="<?php echo $type; ?>-form">
            <input type="hidden" name="post_id" id="post_id" value="<?php echo (int) $post_id; ?>" />
            <?php wp_nonce_field('media-form'); ?>
            <h3 class="media-title"><?php _e('Add images from Flickr','flickrng'); ?></h3>
            <input type="radio" name="type" value="single" /> Single <input type="radio" name="type" value="set" /> Set<br />
            Link: <input type="text" name="url" />
            <?php submit_button( __('Import', 'flickrng'), '', 'import', false ); ?>
			</form>
			<?php
		}
		
		
	}
}
new flickrng();
?>