<?php
/**
 * @package  Addvert
 * @author   Gennaro Vietri <gennaro.vietri@gmail.com>
 * @author   Pelligra Salvatore <s.pelligra@addvert.it>
 */
if (!defined('_PS_VERSION_'))
    exit;

if( !defined('_PS_USE_SQL_SLAVE_') )
    define('_PS_USE_SQL_SLAVE_', 0);

require __DIR__ .'/logger.php';

class Addvert extends Module
{
    const TOKEN = 'addvert_token';
    const TABLE = 'addvert_order_token';
    const SCRIPT_BASE_URL = 'https://addvert.it';
    const ADDVERT_API = 'https://addvert.it/api/order/send_order';

    public $ecommerceId;

    public $secretKey;

    public $buttonLayout;

    protected $_tags = null;

    protected $_categories = null;

    const ADDVERT_TYPE = 'product';

    protected function _getProduct()
    {
        $product = null;

        if ($id_product = (int)Tools::getValue('id_product')) {
            $product = new Product($id_product, true, $this->context->language->id);

            if (!Validate::isLoadedObject($product)) $product = null;
        }

        return $product;
    }

    public function __construct()
    {
        $this->name = 'addvert';
        $this->tab = 'advertising_marketing';
        $this->version = '1.2';
        $this->author = 'Addvert.it';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Addvert integration');
        $this->description = $this->l('PrestaShop Module to integrate Addvert affiliation platform.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->initialize();
    }

    public function install()
    {
        if (!function_exists('curl_init'))
            throw new Exception('Addvert needs the CURL PHP extension.');

        Configuration::updateValue('ADDVERT_ECOMMERCE_ID', $this->ecommerceId);
        Configuration::updateValue('ADDVERT_SECRET_KEY', $this->secretKey);
        Configuration::updateValue('ADDVERT_BUTTON_LAYOUT', $this->buttonLayout);

        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('productActions')
            && $this->registerHook('newOrder')
            && $this->registerHook('paymentConfirm')
            && $this->create_table();
    }

    public function uninstall()
    {
        Configuration::deleteByName('ADDVERT_ECOMMERCE_ID');
        Configuration::deleteByName('ADDVERT_SECRET_KEY');
        Configuration::deleteByName('ADDVERT_BUTTON_LAYOUT');
        $this->delete_table();

        return parent::uninstall();
    }

    protected function initialize()
    {
        $this->ecommerceId = htmlentities(Configuration::get('ADDVERT_ECOMMERCE_ID'), ENT_QUOTES, 'UTF-8');
        $this->secretKey = htmlentities(Configuration::get('ADDVERT_SECRET_KEY'), ENT_QUOTES, 'UTF-8');
        $this->buttonLayout = htmlentities(Configuration::get('ADDVERT_BUTTON_LAYOUT'), ENT_QUOTES, 'UTF-8');

        $this->debug = Configuration::get('ADDVERT_DEBUG') == 1;
        if($this->debug)
            $this->logger = new Addvert\Logger(_PS_ROOT_DIR_ . '/log/addvert.log');

        // Retrocompatibility
        $this->initContext();
    }

    public function postProcess()
    {
        $errors = '';
        if (Tools::isSubmit('submitAddvertConf'))
        {
            if ($ecommerceId = Tools::getValue('ecommerce_id')) {
                Configuration::updateValue('ADDVERT_ECOMMERCE_ID', $ecommerceId);
            } elseif (Shop::getContext() == Shop::CONTEXT_SHOP || Shop::getContext() == Shop::CONTEXT_GROUP) {
                Configuration::deleteFromContext('ADDVERT_ECOMMERCE_ID');
            }

            if ($secretKey = Tools::getValue('secret_key'))
                Configuration::updateValue('ADDVERT_SECRET_KEY', $secretKey);
            elseif (Shop::getContext() == Shop::CONTEXT_SHOP || Shop::getContext() == Shop::CONTEXT_GROUP)
                Configuration::deleteFromContext('ADDVERT_SECRET_KEY');

            if ($buttonLayout = Tools::getValue('button_layout'))
                Configuration::updateValue('ADDVERT_BUTTON_LAYOUT', $buttonLayout);
            elseif (Shop::getContext() == Shop::CONTEXT_SHOP || Shop::getContext() == Shop::CONTEXT_GROUP)
                Configuration::deleteFromContext('ADDVERT_BUTTON_LAYOUT');

            $debug = (int) Tools::getValue('debug');
            Configuration::updateValue('ADDVERT_DEBUG', $debug);

            $this->initialize();
        }
        if ($errors)
            echo $this->displayError($errors);
    }

    public function getContent()
    {
        $this->postProcess();
        $output = '
		<form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post" enctype="multipart/form-data">
			<fieldset>
				<legend>'.$this->l('Addvert integration configuration').'</legend>
				<br/><br/>
				<label for="ecommerce_id">'.$this->l('Ecommerce ID').'</label>
				<div class="margin-form">
					<input id="ecommerce_id" type="text" name="ecommerce_id" value="'.$this->ecommerceId.'" style="width:250px" />
				</div>
				<br class="clear"/>
				<label for="secret_key">'.$this->l('Secret key').'</label>
				<div class="margin-form">
					<input id="secret_key" type="text" name="secret_key" value="'.$this->secretKey.'" style="width:250px" />
				</div>
				<br class="clear"/>
				<label for="button_layout">'.$this->l('Button layout').'</label>
				<div class="margin-form">
					<select id="secret_key" name="button_layout">
					    <option value="standard"' . ($this->buttonLayout == 'standard' ? ' selected="selected"' : '') . '>Standard</option>
					    <option value="small"' . ($this->buttonLayout == 'small' ? ' selected="selected"' : '') . '>Small</option>
					</select>
				</div>
				<br class="clear"/>
				<label for="debug">'.$this->l('Debug').'</label>
				<div class="margin-form">
                    <input id="debug" type="checkbox" name="debug" value="1"'
                        .($this->debug ? ' checked' : '').' />
				</div>
				<br class="clear"/>
				<div class="margin-form">
					<input class="button" type="submit" name="submitAddvertConf" value="'.$this->l('Validate').'"/>
				</div>
				<br class="clear"/>
			</fieldset>
		</form>';
        return $output;
    }

    /**
     * Costruisce i meta per l'head della scheda prodotto
     */
    public function getMetaHtml()
    {
        $metaHtml = '';

        if ($this->_isProductPage()) {
            $product = $this->_getProduct();

            if(is_null($product) || $product == false)
                return $metaHtml;

            $metas = array(
                array('property' => 'og:url',           'content' => $product->getLink()),
                array('property' => 'og:title',         'content' => $product->name),
                array('property' => 'og:description',   'content' => strip_tags($product->description_short)),
                array('name' => 'addvert:type',         'content' => self::ADDVERT_TYPE),
                array('name' => 'addvert:ecommerce_id', 'content' => $this->ecommerceId),
                array('name' => 'addvert:price',        'content' => number_format($product->getPrice(), 2, '.', '')),
            );

            $image = Product::getCover($product->id);
            if (isset($image['id_image'])) {
                $img = $this->context->link->getImageLink($product->link_rewrite, "$product->id-$image[id_image]");
                // patch prestashop 1.3.4 (outletbicocca)
                if($img[0] === '/')
                    $img = _PS_BASE_URL_ . $img;

                $metas[] = array('property' => 'og:image', 'content' => $img);
            }

            if ($categoryId = $this->getDefaultCategory($product)) {
                $category = new Category($categoryId);
                $metas[] = array('name' => 'addvert:category', 'content' => $category->getName());
            }

            foreach (explode(',', $product->getTags($this->context->language->id)) as $tag) {
                $metas[] = array('name' => 'addvert:tag', 'content' => trim($tag));
            }

            foreach ($metas as $meta) {
                $metaHtml .= '<meta';
                foreach ($meta as $attribute => $value) {
                    $metaHtml .= sprintf(' %s="%s"', $attribute, $this->escapeHtml($value));
                }
                $metaHtml .= ' />' . "\n";
            }
        }

        return $metaHtml;
    }

    public function hookHeader()
    {
        if( isset($_GET[self::TOKEN]) )                // expires in 31 days
            setcookie(self::TOKEN, $_GET[self::TOKEN], time()+2678400);

        return $this->_isProductPage() ? $this->getMetaHtml() : '';
    }

    /**
     * params: cart, order, customer, currency, orderStatus
     */
    public function hookNewOrder($params)
    {
        if(!$this->active)
            return;

        $this->attach_token($params['order']->id);

    }
    /**
     * params: orderStatus, id_order
     */
    public function hookPaymentConfirm($params)
    {
        if(!$this->active)
            return;

        $this->notify_addvert((int) $params['id_order']);
    }

    public function getButtonHtml()
    {
        return '
<script type="text/javascript">
    (function() {
        var js = document.createElement(\'script\'); js.type = \'text/javascript\'; js.async = true;
        js.src = \'' . $this->getScriptUrl() . '\';
        var s = document.getElementsByTagName(\'script\')[0]; s.parentNode.insertBefore(js, s);
    })();
</script>
<div class="addvert-btn" data-width="450" data-layout="' . $this->buttonLayout . '"></div>
        ';
    }

    public function hookProductActions()
    {
        return $this->getButtonHtml();
    }

    public function escapeHtml($data, $allowedTags = null)
    {
        if (is_array($data)) {
            $result = array();
            foreach ($data as $item) {
                $result[] = $this->escapeHtml($item);
            }
        } else {
            // process single item
            if (Tools::strlen($data)) {
                if (is_array($allowedTags) and !empty($allowedTags)) {
                    $allowed = implode('|', $allowedTags);
                    $result = preg_replace('/<([\/\s\r\n]*)(' . $allowed . ')([\/\s\r\n]*)>/si', '##$1$2$3##', $data);
                    $result = htmlspecialchars($result, ENT_COMPAT, 'UTF-8', false);
                    $result = preg_replace('/##([\/\s\r\n]*)(' . $allowed . ')([\/\s\r\n]*)##/si', '<$1$2$3>', $result);
                } else {
                    $result = htmlspecialchars($data, ENT_COMPAT, 'UTF-8', false);
                }
            } else {
                $result = $data;
            }
        }
        return $result;
    }

    public function getScriptUrl()
    {
        return self::SCRIPT_BASE_URL . '/api/js/addvert-btn.js';
    }

    protected function _isProductPage()
    {
        return ((int)Tools::getValue('id_product') > 0);
    }

    private function initContext()
    {
        global $smarty, $cookie, $link;

        if ( !empty($link) ) {
            $this->context = new StdClass();
            $this->context->smarty = $smarty;
            $this->context->cookie = $cookie;
            $this->context->link = $link;
            $this->context->language = new Language($cookie->id_lang);
        }
        elseif( empty($this->context) ) {
            $this->context = Context::getContext();
        }
    }

    public function getDefaultCategory($product)
    {
        if (method_exists($product, 'getDefaultCategory')) {
            return $product->getDefaultCategory();
        } else {
            $default_category = Db::getInstance()->getValue('
                SELECT p.`id_category_default`
                FROM `'._DB_PREFIX_.'product` p
                WHERE p.`id_product` = '.(int)$product->id);

            return $default_category;
        }
    }

    protected function create_table()
    {
        $tbl = $this->table();
        $q = "CREATE TABLE IF NOT EXISTS $tbl("
           .' order_id INT NOT NULL,'
           .' token CHAR(32) NOT NULL,'
           .' PRIMARY KEY (`order_id`))';
        return $this->query_exec($q);
    }
    protected function delete_table()
    {
        return $this->query_exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    protected function attach_token($order_id) {
        $tbl = $this->table();
        $order_id = (int) $order_id;
        $token = pSQL($_COOKIE[self::TOKEN]);

        $q = "INSERT INTO $tbl(order_id, token) VALUES($order_id, '$token')";
        $res = $this->query_exec($q);

        $this->log("Token `$token` attached to order `$order_id`? "
                    . var_export($res, true));

        return $res;
    }

    protected function notify_addvert($order_id) {
        $token = $this->get_token($order_id);

        if(!$token) // it's not an addvert's commission
            return;

        $order = new Order($order_id);
        $url = self::ADDVERT_API .'?'. join('&', array(
            'token='. $token,
            'total='. urlencode($order->total_products_wt),
            'secret='. urlencode($this->secretKey),
            'tracking_id='. $order_id,
            'ecommerce_id='. $this->ecommerceId,
        ));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $resp = curl_exec($ch);
        if($resp === false)
            $this->log('cURL error #'. curl_errno($ch)."\n". curl_error($ch));
        else
            $this->log("cURL response:\n$resp");

        curl_close($ch);
    }

    protected function get_token($order_id) {
        $q = 'SELECT token FROM '. $this->table()
           .' WHERE order_id = '. (int) $order_id
           .' LIMIT 1';
        $r = (array) $this->query_result($q);

        return $r['token'];
    }

    private function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }
    private function query_exec($q)
    {
        return $this->db()->Execute($q);
    }
    private function query_result($q) {
        $r = $this->db()->ExecuteS($q);
        return is_array($r) ? $r[0] : $r;
    }
    private function db() {
        return DB::getInstance(_PS_USE_SQL_SLAVE_);
    }

    protected function log($msg) {
        if($this->debug)
            $this->logger->log($msg);
        return $this;
    }
}
