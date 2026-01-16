<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class OvesioExport
{
    private $context;
    private $db;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->db = Db::getInstance();
    }

    public function getOrdersExport($durationMonths = 12)
    {
        if ($durationMonths <= 0) {
            $durationMonths = 12;
        }

        $defaultCurrencyId = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        $defaultCurrency = new Currency($defaultCurrencyId);
        $defaultCurrencyIso = $defaultCurrency->iso_code;

        $dateFrom = date('Y-m-d', strtotime("-$durationMonths months"));


        $orderStates = Configuration::get('OVESIO_ECOMMERCE_ORDER_STATES');
        if ($orderStates) {
            $orderStates = json_decode($orderStates, true);
        }

        $whereClause = 'AND os.logable = 1';
        if (!empty($orderStates) && is_array($orderStates)) {
             $ids = array_map('intval', $orderStates);
             $whereClause = 'AND o.current_state IN (' . implode(',', $ids) . ')';
        }

        $sql = '
            SELECT
                o.id_order as order_id,
                o.id_currency,
                o.conversion_rate,
                c.email,
                o.total_paid_tax_incl as total,
                o.date_add as date
            FROM `' . _DB_PREFIX_ . 'orders` o
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (o.id_customer = c.id_customer)
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON (o.current_state = os.id_order_state)
            WHERE o.date_add >= "' . pSQL($dateFrom) . '"
            ' . $whereClause . '
            ORDER BY o.id_order ASC
        ';

        $orders = $this->db->executeS($sql);
        $data = [];

        if ($orders && is_array($orders)) {
            foreach ($orders as $row) {
                $orderId = (int)$row['order_id'];
                $orderCurrencyId = (int)$row['id_currency'];
                $conversionRate = (float)$row['conversion_rate'];
                
                $total = (float)$row['total'];
                $rateToUse = 1.0;

                // If order currency is different from default, we need to convert back to default
                if ($orderCurrencyId != $defaultCurrencyId && $conversionRate > 0) {
                    $total = $total / $conversionRate;
                    $rateToUse = $conversionRate;
                }

                $orderProducts = $this->getOrderProducts($orderId, $rateToUse, $defaultCurrencyIso);

                $data[$orderId] = [
                    'order_id' => $orderId,
                    'customer_id' => md5($row['email']),
                    'total' => (float)$total,
                    'currency' => $defaultCurrencyIso,
                    'date' => $row['date'],
                    'products' => $orderProducts
                ];
            }
        }

        return array_values($data);
    }

    private function getOrderProducts($orderId, $conversionRate = 1.0, $currencyIso = '')
    {
        $sql = '
            SELECT
                od.product_id,
                od.product_attribute_id,
                od.product_reference as sku,
                od.product_name as name,
                od.product_quantity as quantity,
                od.unit_price_tax_incl as price
            FROM `' . _DB_PREFIX_ . 'order_detail` od
            WHERE od.id_order = ' . (int)$orderId;

        $rows = $this->db->executeS($sql);
        $products = [];

        if (!$rows || !is_array($rows)) {
            return [];
        }

        foreach ($rows as $p) {
            // Fallback for SKU
            $sku = $p['sku'];
            if (empty($sku)) {
                $sku = $p['product_id'];
                if ($p['product_attribute_id']) {
                    $sku .= '-' . $p['product_attribute_id'];
                }
            }

            $price = (float)$p['price'];
            if ($conversionRate != 1.0 && $conversionRate > 0) {
                $price = $price / $conversionRate;
            }

            $products[] = [
                'sku' => $sku,
                'name' => $p['name'],
                'quantity' => (int)$p['quantity'],
                'price' => (float)$price,
                'currency' => $currencyIso
            ];
        }

        return $products;
    }

    public function getProductsExport()
    {
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $idGroup = (int)$this->context->customer->id ? $this->context->customer->id_default_group : Group::getCurrent()->id;
        $currencyIso = $this->context->currency->iso_code;

        $sql = '
            SELECT
                p.id_product,
                p.reference as sku,
                pl.name,
                pl.description_short,
                pl.description,
                m.name as manufacturer,
                sa.quantity,
                p.price,
                p.id_tax_rules_group,
                p.wholesale_price,
                stock.out_of_stock,
                pl.link_rewrite,
                i.id_image
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (p.id_product = pl.id_product AND pl.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON (p.id_manufacturer = m.id_manufacturer)
            LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` stock ON (stock.id_product = p.id_product AND stock.id_product_attribute = 0 AND stock.id_shop = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON (p.id_product = i.id_product AND i.cover = 1)
            WHERE pl.id_lang = ' . $idLang . '
            AND p.active = 1
            ORDER BY p.id_product ASC
        ';

        $rows = $this->db->executeS($sql);

        if (!$rows || !is_array($rows)) {
            return [];
        }

        $data = [];
        $link = new Link();

        foreach ($rows as $row) {
            $sku = $row['sku'];
            if (empty($sku)) {
                $sku = $row['id_product'];
            }

            $price = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, true);

            $quantity = (int)$row['quantity'];
            $availability = ($quantity <= 0) ? 'out_of_stock' : 'in_stock';

            $description = $this->htmlToPlainText($row['description']);
            if (empty($description)) {
                $description = $this->htmlToPlainText($row['description_short']);
            }

            $imageUrl = null;
            if ($row['id_image']) {
                $imageUrl = $link->getImageLink($row['link_rewrite'], $row['id_image'], 'home_default');
                if (strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = Tools::getShopProtocol() . $imageUrl;
                }
            }

            $productUrl = $link->getProductLink($row['id_product'], $row['link_rewrite'], null, null, $idLang, $idShop);
            $categoryPath = $this->getNextCategoryPath($row['id_product'], $idLang);

            $data[$sku] = [
                'sku' => $sku,
                'name' => $row['name'],
                'quantity' => $quantity,
                'price' => (float)$price,
                'currency' => $currencyIso,
                'availability' => $availability,
                'description' => $description,
                'manufacturer' => $row['manufacturer'],
                'image' => $imageUrl,
                'url' => $productUrl,
                'category' => $categoryPath
            ];
        }

        return array_values($data);
    }

    private function getNextCategoryPath($idProduct, $idLang)
    {
        $product = new Product($idProduct);
        $idCategoryDefault = $product->id_category_default;

        $category = new Category($idCategoryDefault, $idLang);
        $parents = $category->getParentsCategories($idLang);

        $path = [];
        foreach ($parents as $parent) {
            if ($parent['id_category'] == Configuration::get('PS_ROOT_CATEGORY') || $parent['id_category'] == Configuration::get('PS_HOME_CATEGORY')) {
                continue;
            }
            $path[] = $parent['name'];
        }
        $path = array_reverse($path);

        return implode(' > ', $path);
    }

    private function htmlToPlainText($content)
    {
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\t+/', ' ', $text);
        $text = preg_replace('/ +/', ' ', $text);
        $text = preg_replace("/(\r?\n){2,}/", "\n", $text);
        return trim($text);
    }
}
