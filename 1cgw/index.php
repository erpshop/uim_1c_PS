<?php
ini_set('soap.wsdl_cache_enabled', '0');
ini_set('soap.wsdl_cache_ttl', '0'); 

require_once 'OneC/Wsdl/Server.php';
require_once 'OneC/Wsdl/Client.php';

class OneCGateway {
	
	private $db_prefix      = 'ps_';
	private $secure_id 		= //'Идентификатор модуля обмена';
	private $secure_login 	= //'Имя пользователя';
	private $secure_pswd 	= //'пароль';
	
	private $db_host        = 'localhost';
	private $db_user        = 'erpshop';
	private $db_password    = '[etnf';
	
	private $db_name        = 'presta';

	private $enable_logs   = 1;
	
	private $level_depth   = 1;
	
	private $link;

	public function __construct() {
		include('./../config/config.inc.php');
	}

	/**
	* @param string $signature
	* @return boolean
	*/
	private function _validateSignature($signature) {
		$hash = md5($this->secure_id.';'.$this->secure_login.';'.$this->secure_pswd);
		return $hash === $signature;
	}
	/*Connect с базой*/
    private function _connectDb(){
		
		$this->link = mysql_connect($this->db_host, $this->db_user, $this->db_password);
		mysql_set_charset('utf8');
		if (!$this->link) {
			$fp = fopen( "./log_connect_bd.log", "a+" );
			fwrite($fp, 'Could not connect: ' . mysql_error()."\r\n");
			fclose($fp);
			return false;
		} 
		
		$db_selected = mysql_select_db($this->db_name, $this->link);
		if (!$db_selected) {
			$fp = fopen( "./log_connect_bd.log", "a+" );
			fwrite($fp, 'Can\'t use presta: ' . mysql_error()."\r\n");
			fclose($fp);
			return false;
		}	
		return true;
	}
	/*Connect с базой закрыт*/
	private function _closeConnectDb(){
		mysql_close($this->link);
	}
	/*id групп*/
	private function _getIdAllGroup($lang){
		$groups = GroupCore::getGroups($lang);
		foreach ($groups as $g){
			$groupId[] = $g['id_group'];
		}
		return $groupId;
	}
	/**
	* @param mixed $args
	* @param string $signature
	* @return mixed
	*/
	
	public function sendCategories($args, $signature) {
	// коннект с базой
		if (!$this->_connectDb()) {
			return array('error' => 'Not connect database');
		}
	//категории	
		if ($this->enable_logs) {
			$fp = fopen( "./log_send_categories.log", "a+" );
			fwrite($fp, var_export($args, true)."\r\n");
			fclose($fp);
		}
    //цифровая подпись
		if (!$this->_validateSignature($signature)) {
			if ($this->enable_logs) {
				$fp = fopen( "./log_send_categories.log", "a+" );
				fwrite($fp, 'Signature is not correct'."\r\n");
				fclose($fp);
			}
			return array('error' => 'Signature is not correct');
		}
		
		$languages = Language::getLanguages(); // id всех языков
		$lang = (int)Language::isInstalled('ru'); // id языка с iso_code = 'ru'
		
		$args = (array)$args;
		
		$parent_id = (array)Category::getRootCategory(); // id родителя
				
		$this->category_ids = array();
		if ($args['category']) {
			$this->_saveCategoriesTree($args['category'], (int)$parent_id['id'], $languages, $lang);
		} 
		$this->_closeConnectDb();
		return array('error' => '', 'category' => $this->category_ids);;
		
	}
	// Дерево каталогов
	private function _saveCategoriesTree($categories, $parent_id, $languages, $lang){
		foreach ($categories as $category) {
			if ($category){
				$category = (array)$category;
				if (!Category::categoryExists((int)$category['id'])){
					$category['id'] = '0';
				}
				
				$category['parent_id'] = $parent_id;
				$category_id = $this->_saveCategory($category, $languages, $lang);
				if (isset($category['category']) && $category['category'] && is_array($category['category'])) {
					$this->_saveCategoriesTree($category['category'], $category_id, $languages, $lang);
				}
			}
		}
	}
	//дата изменяемой категории
	private function _getCategoryDateAdd($category_id){
		return mysql_fetch_array(mysql_query("SELECT date_add FROM " . $this->db_prefix . "category WHERE id_category = " .(int)$category_id));
	}

	// Сохранить категорию
	private function _saveCategory($args, $languages, $lang){
		$category_id = (int)$args['id'];
		
		if ($args['keyword']!= ''){
			$keyword = $args['keyword'];
		} else {
			$keyword = $this->_transliterateString($args['name'], true);
		}
		
		$descriptions = array();
		foreach ($languages as $k => $v) {
			if ($v["id_lang"] == $lang) {
				$descriptions[$v["id_lang"]] = array(
					'name' 						=> $args['name'],
			        'meta_title' 				=> $args['seo_title'],
			        'meta_keyword' 				=> $args['meta_keyword'],
			        'meta_description' 			=> $args['meta_description'],
					'link_rewrite'              => $keyword,
			        'description' 				=> $args['description'],
				);
			} else {
				$descriptions[$v["id_lang"]] = array (
					'name' 						=> $this->_transliterateString($args['name'], false),
			        'meta_title' 				=> '',
			        'meta_keyword' 				=> '',
					'meta_description' 			=> '',
					'link_rewrite'              => $keyword,
			        'description' 				=> '',
				);
			}
		} 
		$data = array (
			'parent_id'             => $args['parent_id'],
			'active'                => $args['status'],
			'image'                 => isset($args['dir_image']) ? $args['dir_image'] : '',
			'position'              => $args['sort_order'],
			'category_description' 	=> $descriptions,
			'image'                 => $args['image'],
			'dir_image'             => $args['dir_image'],
		);

		if ($args['delete'] == '0'){
			if ($category_id) {
				$this->_editCategory($category_id, $data);
			} else {
				$data['groupBox'] = $this->_getIdAllGroup($lang);
				$category_id = $this->_addCategory($data);
				$this->category_ids[$args['id_1c']] = $category_id;
			}
		} else {
			if ($category_id) {
				$this->_deleteCategory($category_id);
				$category_id = '0';
			}
		}
		return $category_id;
	}
	//Удаление категории
	
	private function _deleteCategory($category_id){
		$object = new Category();
		$object->id = (int)$category_id;
		$object->delete();
	}

	//Добавление категории
	
	private function _addCategory($data){
		
		$object = new Category();
		$object->id_parent = (int)$data['parent_id'];
		$object->active = (int)$data['active'];
		
		$object->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['name']);
		$object->link_rewrite = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['link_rewrite']);
		$object->description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['description']);
		$object->meta_title = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_title']);
		$object->meta_keywords = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_keyword']);
		$object->meta_description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_description']);
		
		$object->groupBox = (array)$data['groupBox']; 

		$object->add();

		//картинка
		$image_type  = ImageType::getImagesTypes('categories');
		
		if (isset($data['dir_image']) && $data['dir_image'] && $data['image']) {
			$this->_saveImage($object->id, $data['image'], $image_type, 'c');
		}
		Category::regenerateEntireNtree();
		
		return $object->id; 
	}
	// Изменение категории
	private function _editCategory($category_id, $data){
		$dateAdd = $this->_getCategoryDateAdd($category_id);
		$object = new Category();
		$object->id = (int)$category_id;
		$object->id_parent = (int)$data['parent_id'];
		$object->active = (int)$data['active'];
		$object->date_add = $dateAdd['date_add'];
		
		$object->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['name']);
		$object->link_rewrite = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['link_rewrite']);
		$object->description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['description']);
		$object->meta_title = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_title']);
		$object->meta_keywords = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_keyword']);
		$object->meta_description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['category_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_description']);

		$object->update();
		
		$image_type  = ImageType::getImagesTypes('categories');
		
		if (isset($data['dir_image']) && $data['dir_image'] && $data['image']) {
			$this->_saveImage($object->id, $data['image'], $image_type, 'c');
		}
	} 
	//Сохранение картинки 
	private function _saveImage($id, $data, $image_type, $name_folder) {
		$name_img = "./../img/" . $name_folder  . "/" . (int)$id . ".jpg";
		$fp = fopen($name_img, "wb");
		if ($fp = fopen($name_img, "wb")) {
			$wlen = 0;
			for ($written = 0; $written < strlen(base64_decode($data)); $written += $wlen ) {
				$wlen = fwrite($fp, substr(base64_decode($data), $written));
				if ($wlen === false) {
					return 0;
				}
			}
			fclose($fp);
			chmod($name_img, 0644);
		}
		list($width_orig, $height_orig) = getimagesize("./../img/" . $name_folder  . "/" . (int)$id . ".jpg");
		foreach ($image_type as $value){
			if ($width_orig != $value['width'] || $height_orig != $value['height']) {
				$name_Img = "./../img/" . $name_folder  . "/" . (int)$id . "-" . $value['name'] . ".jpg";
				ImageManager::resize($name_img, $name_Img, $value['width'], $value['height']);
			} else {
				copy("./../img/" . $name_folder  . "/" . (int)$id . ".jpg", "./../img/" . $name_folder  . "/" . (int)$id . "-" . $value['name'] . ".jpg");
			}		
		} 
	}

	/*Товары*/
	/**
	* @param mixed $args
	* @param string $signature
	* @return mixed
	*/
	public function sendProducts($args, $signature) {
		if (!$this->_connectDb()) {
			return array('error' => 'Not connect database');
		}
		if ($this->enable_logs) {
			$fp = fopen( "./log_send_products.log", "a+" );
			fwrite($fp, var_export($args, true)."\r\n");
			fclose($fp);
		}

		if (!$this->_validateSignature($signature)) {
			if ($this->enable_logs) {
				$fp = fopen( "./log_send_products.log", "a+" );
				fwrite($fp, 'Signature is not correct'."\r\n");
				fclose($fp);
			}
			return array('error' => 'Signature is not correct');
		}
		
		$languages = Language::getLanguages(); // id всех языков
		$lang = (int)Language::isInstalled('ru'); // id языка с iso_code = 'ru'

		$this->product_ids = array();
	//	$this->product_option_ids = array();

		$args = (array)$args;
		$products = $args['product'];
		if ($products) {
			foreach ($products as $product) {
				if ($product) {
					$product = (array)$product;
					if (!$this->_getProduct((int)$product['id'])) {
						$product['id'] = '0';
					}
					$this->_saveProduct($product, $languages, $lang);
				}
			}
		}
		$this->_closeConnectDb();
		return array('error' => '', 'product' => $this->product_ids);
	}
	// наличие товара в базе
	private function _getProduct($product_id){
		return mysql_fetch_array(mysql_query("SELECT id_product FROM " . $this->db_prefix . "product WHERE id_product = " . (int)$product_id));
	}
	// id всех магазинов
	private function _getIdAllShops(){
		$shops = Shop::getShops();
		foreach ($shops as $v){
			$shopsId['id_shop'] = $v['id_shop'];
		}
		return $shopsId;
	}
	// категории для товара
	private function _productCategories($categories) {
		$result = array();
		if (isset($categories['id']) && is_array($categories['id'])) {
			$last = array_pop($categories['id']);
			$result = $categories['id'];
		}
		return $result;
	}
	// сохранение товара
	private function _saveProduct($args, $languages, $lang) {
		$product_id = (int)$args['id'];
		
		if ($args['keyword']!= ''){
			$keyword = $args['keyword'];
		} else {
			$keyword = $this->_transliterateString($args['name'], true);
		}
		
		$descriptions = array();
		foreach ($languages as $k => $v) {
			if ($v["id_lang"] == $lang) {
				$descriptions[$v["id_lang"]] = array(
					'name' 						=> $args['name'],
			        'meta_title' 				=> $args['seo_title'],
			        'meta_keyword' 				=> $args['meta_keyword'],
			        'meta_description' 			=> $args['meta_description'],
					'link_rewrite'              => $keyword,
			        'description' 				=> $args['description'],
				//	'tag'                       => $args['tag']
				);
			} else {
				$descriptions[$v["id_lang"]] = array (
					'name' 						=> $this->_transliterateString($args['name'], false),
			        'meta_title' 				=> '',
			        'meta_keyword' 				=> '',
					'meta_description' 			=> '',
					'link_rewrite'              => $keyword,
			        'description' 				=> '',
				//	'tag'                       => '',
				);
			}
		}
		$tmpArr = $args['attributes'];
		$features = array();
		foreach ($tmpArr as $val){
			foreach ($languages as $k => $v) {
				if ($v["id_lang"] == $lang) {
					$features[$v["id_lang"]] = array(
						'property'     => $val['property'],
						'value'        => $val['value'],
					);
				} else {
					$features[$v["id_lang"]] = array (
						'property' 			=> $this->_transliterateString($val['property'], false),
						'value' 		    => $this->_transliterateString($val['value'], false),
					);
				}
			}
		}
		
		$manufacturer_id = 0;
		if ($args['manufacturer']) {
			$manufacturer_info = Manufacturer::getIdByName((string)$args['manufacturer']);
			if (!$manufacturer_info) {
				$manufacturer_data['name'] = (string)$args['manufacturer'];
				
				foreach ($languages as $k => $v) {
					if ($v["id_lang"] == $lang) {
						$manufacturer_description[$v["id_lang"]] = array(
							'description'       => '',
							'short_description' => '',
							'meta_title'        => '',
							'meta_keyword'      => '', 
							'meta_description'  => '',
						);
					} else {
						$manufacturer_description[$v["id_lang"]] = array(
							'description'      => '',							
							'short_description' => '',
							'meta_keyword' => '', 
							'meta_description' => '', 
							'meta_title' => '',
						);
					}
				}
				$manufacturer_data['manufacturer_description'] = $manufacturer_description;
				$manufacturer_id = $this->_addManufacturer($manufacturer_data);
			} else {
				$manufacturer_id = $manufacturer_info;
			}
		}
		
		$data = array (
			'active'                => $args['status'],
			'id_manufacturer'       => (int)$manufacturer_id,
			'quantity'              => $args['quantity'],
			'price'                 => $args['price'],
			'weight'                => $args['weight'],
			'upc'                   => $args['upc'],
			'id_category_default'   => $args['main_category_id'],
		//	'ean13'                 => '0',
			'product_category' 	    => $this->_productCategories((array)$args['categories']),
			'product_description'   => $descriptions,			
			'image'                 => $args['image'],
			'dir_image'             => $args['dir_image'],
			'product_image'         => $args['product_image'],
			'feature'               => $features,
		);
		
		if ($args['delete'] == '0') {
			if ($product_id) {
				$this->_editProduct($product_id, $data);
			} else {
				$product_id = $this->_addProduct($data);
				$this->product_ids[$args['id_1c']] = $product_id;
			}
		} else {
			if ($product_id) {
				$this->_deleteProduct($product_id);
				$product_id = 0;
			}
		}
		return $product_id;
	}
	// добавление производителя
	private function _addManufacturer($manufacturer_data) {
		$result = mysql_query("INSERT INTO " . $this->db_prefix . "manufacturer SET 
							   name = '" . $manufacturer_data['name'] . "',
							   date_add = NOW(),
							   date_upd = NOW(),
							   active = 1");
		$manufacturer_id = mysql_insert_id();
		foreach ($manufacturer_data['manufacturer_description'] as $id_lang => $value) {	
				$result = mysql_query("INSERT INTO " . $this->db_prefix . "manufacturer_lang SET
							id_manufacturer = '". $manufacturer_id ."',
							id_lang = '". (int)$id_lang . "',
							description = '" . mysql_escape_string($value['description']) . "',
							short_description = '" . mysql_escape_string($value['short_description']) . "',
							meta_title = '" . mysql_escape_string($value['meta_title']) . "',
							meta_keywords = '" . mysql_escape_string($value['meta_keyword']) . "' ,
							meta_description = '" . mysql_escape_string($value['meta_description']) ."'");
		}
		$shopId = $this->_getIdAllShops();
		foreach ($shopId as $value){
			$result = mysql_query("INSERT INTO " . $this->db_prefix . "manufacturer_shop SET
							id_manufacturer = '" . $manufacturer_id . "',
							id_shop = '" . $value['id_shop'] . "'");
		}
		return $manufacturer_id;		
	}
	private function _addProductCategories($product_category, $product_id){
		foreach ($product_category as $val){
			$result = mysql_query("INSERT INTO " . $this->db_prefix . "category_product SET
							id_product = '" . (int)$product_id . "' ,
							id_category = '" .(int)$val ."'");
		}
	}
	private function _pathImg($image_id){
		$path = "./../img/p/";
		$a = array();
		$pt = '';
		while ($image_id > 0) {
			$a = $image_id % 10;
			$pt = $a."/".$pt;
			$image_id = intval($image_id / 10); 
		}
		mkdir($path.$pt);
		return $path.$pt;
	}
	private function _addProductImage($product_id, $imgCover, $argImg){
		$position = 1;
		$result = mysql_query("INSERT INTO " . $this->db_prefix . "image SET
							id_product = '" . $product_id . "',
							position = '" . (int)$position . "',
							cover = 1");
		$image_id = mysql_insert_id(); 
		$path = $this->_pathImg($image_id);
		$name_img = $path.(int)$image_id . ".jpg";
		$fp = fopen($name_img, "wb");
		if ($fp = fopen($name_img, "wb")) {
			$wlen = 0;
			for ($written = 0; $written < strlen(base64_decode($imgCover)); $written += $wlen ) {
				$wlen = fwrite($fp, substr(base64_decode($imgCover), $written));
				if ($wlen === false) {
					return 0;
				}
			}
			fclose($fp);
			chmod($name_img, 0644);
		}
		$img_files = $_FILES['userfile']['tmp_name'];
		
	} 
	// существование характеристики
	private function _getIdByNameFeature($property){
		return mysql_fetch_array(mysql_query("SELECT id_feature FROM " . $this->db_prefix . "feature_lang WHERE name = " . $property));
	}
	//существование значения характеристики
	private function _getValueFeature($id_feature, $value){
		return mysql_fetch_array(mysql_query("SELECT id_feature_value FROM " . $this->db_prefix . "feature_value_lang WHERE id_feature = " . (int)$id_feature . " AND " . $value));
	}
	
	//добавление товара
	private function _addProduct($data){
		$object = new Product();
		$object->id_manufacturer = (int)$data['id_manufacturer'];
		$object->active = (int)$data['active'];
		$object->quantity = (int)$data['quantity'];
		$object->price = (int)$data['price'];
		$object->weight = (int)$data['weight'];
		//$object->upc = $data['upc'];
		$object->id_category_default = (int)$data['id_category_default'];
	//	$object->ean13 = $data['ean13'];
	
		$object->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['name']);
		$object->link_rewrite = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['link_rewrite']);
		$object->description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['description']);
		$object->meta_title = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_title']);
		$object->meta_keywords = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_keyword']);
		$object->meta_description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_description']);
		$object->add();
		if ($data['feature']){
			foreach ($data['feature'] as $attr){
				//$id_feature = $this->_getIdByNameFeature($attr['property']);
			/*	if ($id_feature){
			//		$id_feature_value = $this->_getValueFeature(id_feature, $attr['value']);
				} else {
					$feature = new Feature();
					$feature->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $attr['property']);
					$feature->add();
				}
				$fp = fopen( "./log_.log", "a+" );
				fwrite($fp, var_export($id_feature, true)."\r\n");
				fclose($fp); */
			}
		}
		$this->_addProductCategories($data['product_category'], $object->id);
	//	$this->_addProductImage($object->id, $data['image'], $data['product_image']);
		return $object->id;
	}
	private function _getProductDateAdd($product_id){
		return mysql_fetch_array(mysql_query("SELECT date_add FROM " . $this->db_prefix . "product WHERE id_product = " .(int)$product_id));
	}
	// изменение товара
	private function _editProduct($product_id, $data){
		$object = new Product();
		$object->id = (int)$product_id;
		$object->id_manufacturer = (int)$data['id_manufacturer'];
		$object->active = (int)$data['active'];
		$object->quantity = (int)$data['quantity'];
		$object->price = (int)$data['price'];
		$object->weight = (int)$data['weight'];
		$date_add = $this->_getProductDateAdd($product_id);
		$object->date_add = $date_add['date_add'];
		//$object->upc = $data['upc'];
		$object->id_category_default = (int)$data['id_category_default'];
	//	$object->ean13 = $data['ean13'];
		
	
		$object->name = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['name']);
		$object->link_rewrite = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['link_rewrite']);
		$object->description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['description']);
		$object->meta_title = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_title']);
		$object->meta_keywords = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_keyword']);
		$object->meta_description = array((int)Configuration::get('PS_LANG_DEFAULT') => $data['product_description'][(int)Configuration::get('PS_LANG_DEFAULT')]['meta_description']);
		$object->update();
	}
	// удаление товара
	private function _deleteProduct($product_id){
		$object = new Product();
		$object->id = (int)$product_id;
		$object->delete();		
	}
	//перобразование name
	private function _rusToTranslit($string) {
		$converter = array(	
			'а' => 'a',   'б' => 'b',   'в' => 'v',
			'г' => 'g',   'д' => 'd',   'е' => 'e',
			'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
			'и' => 'i',   'й' => 'y',   'к' => 'k',
			'л' => 'l',   'м' => 'm',   'н' => 'n',
			'о' => 'o',   'п' => 'p',   'р' => 'r',
			'с' => 's',   'т' => 't',   'у' => 'u',
			'ф' => 'f',   'х' => 'h',   'ц' => 'c',
			'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
			'ь' => '',    'ы' => 'y',   'ъ' => '',
			'э' => 'e',   'ю' => 'yu',  'я' => 'ya',

			'А' => 'A',   'Б' => 'B',   'В' => 'V',
			'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
			'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
			'И' => 'I',   'Й' => 'Y',   'К' => 'K',
			'Л' => 'L',   'М' => 'M',   'Н' => 'N',
			'О' => 'O',   'П' => 'P',   'Р' => 'R',
			'С' => 'S',   'Т' => 'T',   'У' => 'U',
			'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
			'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
			'Ь' => '',    'Ы' => 'Y',   'Ъ' => '',
			'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
		);
		return strtr($string, $converter);
	}

	private function _transliterateString($str, $to_url = true) {
		$str = $this->_rusToTranslit($str);
		if ($to_url) {
			$str = strtolower($str);
			$preg_str = '~[^-a-z0-9_]+~u';
		} else {
			$preg_str = '~[^-a-zA-Z0-9_ ]+~u';
		}
		$str = preg_replace($preg_str, '-', $str);
		$str = $this->_removeDuplicates('--', '-', $str);
		$str = trim($str, "-");

		return $str;
	}
	private function _removeDuplicates($search, $replace, $subject) {
		$i = 0;
		$pos = strpos($subject, $search);
		while ($pos !== false) {
			$subject = str_replace($search, $replace, $subject);
			$pos = strpos($subject, $search);
			$i++;
			if ($i > 150) {
				die('_removeDuplicates() loop error');
			}
		}
		return $subject;
	}

	/**
	* @param mixed $args
	* @param string $signature
	* @return mixed
	*/
	public function sendSeries($args, $signature) {
		if ($this->enable_logs) {
			$fp = fopen( "./log_send_series.log", "a+" );
			fwrite($fp, var_export($args, true)."\r\n");
			fclose($fp);
		}

		if (!$this->_validateSignature($signature)) {
			if ($this->enable_logs) {
				$fp = fopen( "./log_send_series.log", "a+" );
				fwrite($fp, 'Signature is not correct'."\r\n");
				fclose($fp);
			}
			return array('error' => 'Signature is not correct');
		}

		// function body ...

		return array('error' => '');
	}

	/**
	* @param mixed $args
	* @param string $signature
	* @return mixed
	*/
	public function sendPriceAndQuantity($args, $signature) {
		if ($this->enable_logs) {
			$fp = fopen( "./log_send_price.log", "a+" );
			fwrite($fp, var_export($args, true)."\r\n");
			fclose($fp);
		}

		if (!$this->_validateSignature($signature)) {
			if ($this->enable_logs) {
				$fp = fopen( "./log_send_price.log", "a+" );
				fwrite($fp, 'Signature is not correct'."\r\n");
				fclose($fp);
			}
			return array('error' => 'Signature is not correct');
		}

		// function body ...

		return array('error' => '');
	}
}


class onec_send_data {

  public function onec_send_customer_add( $id, $firstname, $lastname, $gender, $company, $address_1, $address_2, $city, $postcode ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->AddTask( array( 'action'=>'customer_add', 'uid' => "$id", 'fn' => "$firstname", 'ln' => "$lastname", 'gender' => "$gender", 'company' => "$company", 'addr_1' => "$address_1", 'addr_2' => "$address_2", 'city' => "$city", 'postcode' => "$postcode" ) );
  }

  public function onec_send_customer_edit( $id, $firstname, $lastname, $gender, $company, $address_1, $address_2, $city, $postcode ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->AddTask( array( 'action'=>'customer_edit', 'uid' => "$id", 'fn' => "$firstname", 'ln' => "$lastname", 'gender' => "$gender", 'company' => "$company", 'addr_1' => "$address_1", 'addr_2' => "$address_2", 'city' => "$city", 'postcode' => "$postcode" ) );
  }

  public function onec_send_customer_del( $id ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->AddTask( array( 'action'=>'customer_del', 'uid' => "$id" ) );
  }

  public function onec_send_create_order( $order_data ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->AddTask( $order_data );
  }

  public function onec_send_payment( $order_id, $summ ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->AddTask( array( 'action'=>'payment_confirm', 'order_id' => "$order_id", 'sum' => "$sum" ) );
  }
  
   /**
   * @param mixed
   */
  public function onec_send_data( $data ) {
    $client = new OneC_Wsdl_Client("http://localhost/elit/ru_RU/ws/PHPTest?wsdl", "ws", "ws");
    $client->__call( "AddTask", $data );
  }
  
}

$server = new OneC_Wsdl_Server();
$server->setService('OneCGateway');
$server->handle();

