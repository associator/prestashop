<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Associator extends Module
{
    const BASE_URL = 'api.associator.eu';
    const VERSION = 'v1';

    /**
     * Associator constructor.
     */
    public function __construct()
    {
        $this->name = 'associator';
        $this->tab = 'advertising_marketing';
        $this->author = 'Associator.eu';
        $this->version = '1.0.0';
        $this->module_key = 'fr6f0cf17c8cb0d314ec544203a9f6f5';

        parent::__construct();
        $this->secure_key = Tools::encrypt($this->name);
        $this->displayName = $this->l('Associator');
        $this->description = $this->l('Real-time product recommendation tool based on historical orders and artificial intelligence.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    /**
     * Control what happens when the store administrator installs module
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->registerHook('productFooter')) {
            return false;
        }

        if (!$this->registerHook('displayOrderConfirmation')) {
            return false;
        }

        Configuration::updateValue('ASSOCIATOR_SUPPORT', 10);
        Configuration::updateValue('ASSOCIATOR_CONFIDENCE', 10);
        Configuration::updateValue('ASSOCIATOR_MAX_RECOMMENDATIONS', 4);
        Configuration::updateValue('ASSOCIATOR_FOOTER_WIDGET', 0);

        return true;
    }

    /**
     * Control what happens when the store administrator uninstall
     * @return bool
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Hook when order confirmation is displayed
     * @param $params
     */
    public function hookDisplayOrderConfirmation($params)
    {
        $transactionItems = [];
        $order = isset($params['objOrder']) ? $params['objOrder'] : [];

        if (!is_a($order, 'Order')) {
            return;
        }

        $products = $order->getProducts();
        foreach ($products as $product) {
            $transactionItems[] = (int) $product['product_id'];
        }

        $apiKey = Configuration::get('ASSOCIATOR_API_KEY');
        $this->saveTransaction($apiKey, $transactionItems);
    }

    /**
     * Hook when product footer was displayed
     * @return mixed
     * @throws PrestaShopDatabaseException
     */
    public function hookProductFooter()
    {
        $apiKey = Configuration::get('ASSOCIATOR_API_KEY');
        $support = (int) Configuration::get('ASSOCIATOR_SUPPORT');
        $confidence = (int) Configuration::get('ASSOCIATOR_CONFIDENCE');
        $maxRecommendations = (int) Configuration::get('ASSOCIATOR_MAX_RECOMMENDATIONS');
        $footerWidget = (bool) Configuration::get('ASSOCIATOR_FOOTER_WIDGET');

        if (!$footerWidget) {
            return;
        }

        $cartProducts = $this->getCartProducts();
        $associations = $this->getAssociations($apiKey, $cartProducts, $support, $confidence);
        $associations = array_reduce($associations, 'array_merge', array());
        $products = $this->getProductsByIds($this->context->language->id, $associations, $maxRecommendations);
        $this->context->smarty->assign('products', $products);

        return $this->display(__FILE__, 'views/templates/hook/productFooter.tpl');
    }

    /**
     * Get product from cart
     * @return array
     */
    public function getCartProducts()
    {
        $cartProducts = [];
        $products = Context::getContext()->cart->getProducts();
        foreach ($products as $product) {
            $cartProducts[] = (int)$product['id_product'];
        }

        return $cartProducts;
    }

    /**
     * Get products by ids
     * @param $langId
     * @param array $productIds
     * @param int $limit
     * @param Context|null $context
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    protected function getProductsByIds($langId, array $productIds, $limit = 10,  Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }

        if(empty($productIds)) {
            return false;
        }

        $sql = '
		SELECT
			p.id_product, IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute, pl.`link_rewrite`, pl.`name`, pl.`description_short`, product_shop.`id_category_default`,
			image_shop.`id_image` id_image, il.`legend`,
			ps.`quantity` AS sales, p.`ean13`, p.`upc`, cl.`link_rewrite` AS category, p.show_price, p.available_for_order, IFNULL(stock.quantity, 0) as quantity, p.customizable,
			IFNULL(pa.minimal_quantity, p.minimal_quantity) as minimal_quantity, stock.out_of_stock,
			product_shop.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20).' DAY')).'" as new,
			product_shop.`on_sale`, product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity
		FROM `'._DB_PREFIX_.'product_sale` ps
		LEFT JOIN `'._DB_PREFIX_.'product` p ON ps.`id_product` = p.`id_product`
		'.Shop::addSqlAssociation('product', 'p').'
		LEFT JOIN `'._DB_PREFIX_.'product_attribute_shop` product_attribute_shop
			ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id.')
		LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON (product_attribute_shop.id_product_attribute=pa.id_product_attribute)
		LEFT JOIN `'._DB_PREFIX_.'product_lang` pl
			ON p.`id_product` = pl.`id_product`
			AND pl.`id_lang` = '.(int)$langId.Shop::addSqlRestrictionOnLang('pl').'
		LEFT JOIN `'._DB_PREFIX_.'image_shop` image_shop
			ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id.')
		LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$langId.')
		LEFT JOIN `'._DB_PREFIX_.'category_lang` cl
			ON cl.`id_category` = product_shop.`id_category_default`
			AND cl.`id_lang` = '.(int)$langId.Shop::addSqlRestrictionOnLang('cl').Product::sqlStock('p', 0);
        $sql .= '
		WHERE product_shop.`active` = 1 and p.`id_product` IN ('.implode(',', $productIds).')
		AND p.`visibility` != \'none\'';
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql .= ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)';
        }
        $sql .= '
		ORDER BY FIELD(p.id_product, '.implode(',', $productIds).')
		LIMIT '.(int)$limit;

        try {
            $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        } catch (PrestaShopDatabaseException $exception) {
            return false;
        }

        if (!$result) {
            return false;
        }

        return Product::getProductsProperties($langId, $result);
    }


    /**
     * Get settings content
     * @return string
     */
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            $data = [
                'api_key' => strval(Tools::getValue('ASSOCIATOR_API_KEY')),
                'support' => strval(Tools::getValue('ASSOCIATOR_SUPPORT')),
                'confidence' => strval(Tools::getValue('ASSOCIATOR_CONFIDENCE')),
                'max_recommendation' => strval(Tools::getValue('ASSOCIATOR_MAX_RECOMMENDATIONS')),
                'footer_widget' => strval(Tools::getValue('ASSOCIATOR_FOOTER_WIDGET'))
            ];
            $errors = $this->validate($data);

            if (!empty($errors)) {
                $output .= $this->displayError($this->l(reset($errors)));
            } else {
                Configuration::updateValue('ASSOCIATOR_API_KEY', $data['api_key']);
                Configuration::updateValue('ASSOCIATOR_SUPPORT', $data['support']);
                Configuration::updateValue('ASSOCIATOR_CONFIDENCE', $data['confidence']);
                Configuration::updateValue('ASSOCIATOR_MAX_RECOMMENDATIONS', $data['max_recommendation']);
                Configuration::updateValue('ASSOCIATOR_FOOTER_WIDGET', $data['footer_widget']);

                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output.$this->displayForm();
    }

    /**
     * Validate form data
     * @param $data
     * @return array
     */
    public function validate($data)
    {
        $errors = [];

        if (!$data['api_key']) {
            $errors[] = $this->l('API KEY should not be empty!');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['api_key']) !== 1) {
            $errors[] = $this->l('API KEY is not valid!');
        }

        if (!$data['support']) {
            $errors[] = $this->l('Support should not be empty!');
        }

        if (!Validate::isUnsignedInt($data['support'])) {
            $errors[] = $this->l('Support should be number!');
        }

        if ((int) $data['support'] < 5 || (int) $data['support'] > 100) {
            $errors[] = $this->l('Support should be number between 5 and 100!');
        }

        if (!$data['confidence']) {
            $errors[] = $this->l('Confidence should not be empty!');
        }

        if (!Validate::isUnsignedInt($data['confidence'])) {
            $errors[] = $this->l('Confidence should be number!');
        }

        if ($data['confidence'] < 5 || $data['confidence'] > 100) {
            $errors[] = $this->l('Confidence should be number between 5 and 100!');
        }

        if (!$data['max_recommendation']) {
            $errors[] = $this->l('Maximum number of recommendations should not be empty!');
        }

        if (!Validate::isUnsignedInt($data['max_recommendation'])) {
            $errors[] = $this->l('Maximum number of recommendations should be number!');
        }

        return $errors;
    }

    /**
     * Create settings form
     * @return string
     */
    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('API KEY'),
                    'name' => 'ASSOCIATOR_API_KEY',
                    'size' => 50,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Support'),
                    'name' => 'ASSOCIATOR_SUPPORT',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Confidence'),
                    'name' => 'ASSOCIATOR_CONFIDENCE',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Maximum number of recommendations'),
                    'name' => 'ASSOCIATOR_MAX_RECOMMENDATIONS',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Footer widget'),
                    'name' => 'ASSOCIATOR_FOOTER_WIDGET',
                    'required' => false,
                    'options' => [
                        'query' => [
                            [
                                'expo' => 1,
                                'name' => $this->l('Yes')
                            ],
                            [
                                'expo' => 0,
                                'name' => $this->l('No')
                            ]
                        ],
                        'id' => 'expo',
                        'name' => 'name'
                    ]
                ]
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                    '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];
        $helper->fields_value['ASSOCIATOR_API_KEY'] = Configuration::get('ASSOCIATOR_API_KEY');
        $helper->fields_value['ASSOCIATOR_SUPPORT'] = Configuration::get('ASSOCIATOR_SUPPORT');
        $helper->fields_value['ASSOCIATOR_CONFIDENCE'] = Configuration::get('ASSOCIATOR_CONFIDENCE');
        $helper->fields_value['ASSOCIATOR_MAX_RECOMMENDATIONS'] = Configuration::get('ASSOCIATOR_MAX_RECOMMENDATIONS');
        $helper->fields_value['ASSOCIATOR_FOOTER_WIDGET'] = Configuration::get('ASSOCIATOR_FOOTER_WIDGET');

        return $helper->generateForm($fields_form);
    }

    /**
     * Save single transaction items
     * @param string $apiKey
     * @param array $transactions
     */
    public function saveTransaction($apiKey, array $transactions)
    {
        $url = sprintf('%s/%s/transactions', self::BASE_URL, self::VERSION);
        $this->request($url, 'POST', [
            'api_key' => $apiKey,
            'transaction' => $transactions
        ]);
    }

    /**
     * Get associated items
     * @param string $apiKey
     * @param array $samples
     * @param null $support Default value is defined in documentation
     * @param null $confidence Default value is defined in documentation
     * @return array
     */
    public function getAssociations($apiKey, array $samples, $support = null, $confidence = null)
    {
        if (empty($samples)) {
            return [];
        }

        $parameters['api_key'] = $apiKey;
        $parameters['samples'] = json_encode($samples);
        if (isset($support)) {
            $parameters['support'] = $support;
        }
        if (isset($confidence)) {
            $parameters['confidence'] = $confidence;
        }
        $query = http_build_query($parameters);
        $url = sprintf('%s/%s/associations?%s', self::BASE_URL, self::VERSION, $query);
        $data = $this->request($url);

        if ($data == false) {
            return [];
        }

        $response = json_decode($data, true);

        return isset($response['associations']) ? $response['associations'] :[];
    }

    /**
     * Simple Curl wrapper
     * @param $url
     * @param string $method
     * @param array $data
     * @return mixed
     */
    public function request($url, $method = 'GET', $data = [])
    {
        $curl = curl_init();
        $settings[CURLOPT_URL] = $url;
        $settings[CURLOPT_RETURNTRANSFER] = 1;
        $settings[CURLOPT_HTTPHEADER] = ['Content-Type:application/json'];
        if ($method === 'POST') {
            $settings[CURLOPT_CUSTOMREQUEST] = "POST";
            $settings[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        curl_setopt_array($curl, $settings);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }
}