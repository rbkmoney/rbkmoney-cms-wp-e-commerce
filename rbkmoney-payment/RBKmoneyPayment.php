<?php


class RBKmoneyPayment
{
    /**
     * HTTP METHOD
     */
    const HTTP_METHOD_POST = 'POST';
    const HTTP_METHOD_GET = 'GET';

    /**
     * HTTP CODE
     */
    const HTTP_CODE_OK = 200;
    const HTTP_CODE_CREATED = 201;
    const HTTP_CODE_MOVED_PERMANENTLY = 301;
    const HTTP_CODE_BAD_REQUEST = 400;
    const HTTP_CODE_INTERNAL_SERVER_ERROR = 500;

    /**
     * Create invoice settings
     */
    const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
    const CREATE_INVOICE_DUE_DATE = '+1 days';

    /**
     * Constants for Callback
     */
    const SHOP_ID = 'shopID';
    const METADATA = 'metadata';
    const ORDER_ID = 'order_id';
    const SESSION_ID = 'session_id';
    const EVENT_TYPE = 'event_type';

    private $api_url = 'https://api.rbk.money/v2/';

    private $merchant_private_key = '';
    private $shop_id = '';
    private $currency = '';
    private $product = '';
    private $description = '';
    private $amount = 0;
    private $order_id = '';
    private $session_id = '';

    protected $errors = array();

    protected $requiredFields = array();

    public function getApiUrl()
    {
        return $this->api_url;
    }

    public function setApiUrl($api_url)
    {
        if (filter_var($api_url, FILTER_VALIDATE_URL) === false) {
            $this->setErrors($api_url . ' не валидный URL');
        }
        $this->api_url = $api_url;
    }

    public function getMerchantPrivateKey()
    {
        return $this->merchant_private_key;
    }

    public function setMerchantPrivateKey($merchant_private_key)
    {
        $this->merchant_private_key = $merchant_private_key;
    }

    public function getShopId()
    {
        return $this->shop_id;
    }

    public function setShopId($shop_id)
    {
        $this->shop_id = $shop_id;
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function getProduct()
    {
        return $this->product;
    }

    public function setProduct($product)
    {
        $this->product = $product;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        if (!is_numeric($amount)) {
            throw new Exception($amount . ' не является числом');
        }
        $this->amount = $amount;
    }

    public function getOrderId()
    {
        return $this->order_id;
    }

    public function setOrderId($order_id)
    {
        $this->order_id = $order_id;
    }

    public function getSessionId()
    {
        return $this->session_id;
    }

    public function setSessionId($session_id)
    {
        $this->session_id = $session_id;
    }

    private function getErrors()
    {
        return $this->errors;
    }

    private function setErrors($errors)
    {
        $this->errors[] = $errors;
    }

    private function clearErrors()
    {
        $this->errors = array();
    }

    public function getRequiredFields()
    {
        return $this->requiredFields;
    }

    public function setRequiredFields($requiredFields)
    {
        if (!is_array($requiredFields) || empty($requiredFields)) {
            $this->setErrors('Отсутствуют обязательные поля');
        }
        $this->requiredFields = $requiredFields;
    }

    public function __construct(array $params = array())
    {
        if (!empty($params)) {
            $this->bind($params);
        }
    }

    private function toUpper($pockets)
    {
        return ucfirst(str_replace(['_', '-'], '', $pockets[1]));
    }

    private function getMethodName($name, $prefix = 'get')
    {
        $key = preg_replace_callback('{([_|-]\w)}s', array(__CLASS__, 'toUpper'), $name);
        return $prefix . ucfirst($key);
    }

    private function bind(array $params)
    {
        foreach ($params as $name => $value) {
            $method = $this->getMethodName($name, 'set');
            if (!empty($value) && method_exists($this, $method)) {
                $this->$method($value);
            }
        }
    }

    private function checkRequiredFields()
    {
        $required_fields = $this->getRequiredFields();
        foreach ($required_fields as $field) {
            $method = $this->getMethodName($field);
            if (method_exists($this, $method)) {
                $value = $this->$method();
                if (empty($value)) $this->setErrors('<b>' . $field . '</b> is required');
            } else {
                $this->setErrors($field . ' метод не найден');
            }
        }
    }

    private function prepare_due_date()
    {
        date_default_timezone_set('UTC');
        return date(static::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime(static::CREATE_INVOICE_DUE_DATE));
    }

    private function prepare_metadata($order_id, $session_id)
    {
        return [
            'cms' => 'wordpress',
            'module' => 'wp-e-commerce',
            'plugin' => 'rbkmoney_payment',
            'order_id' => $order_id,
            'session_id' => $session_id,
        ];
    }

    private function prepare_api_url($path = '', $query_params = [])
    {
        $url = rtrim($this->api_url, '/') . '/' . $path;
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }
        return $url;
    }

    public function create_invoice()
    {
        $this->setRequiredFields([
            'merchant_private_key',
            'shop_id',
            'amount',
            'order_id',
            'session_id',
            'currency',
            'product',
            'description',
        ]);

        $data = [
            'shopID' => $this->getShopId(),
            'amount' => (int)$this->getAmount(),
            'metadata' => $this->prepare_metadata($this->getOrderId(), $this->getSessionId()),
            'dueDate' => $this->prepare_due_date(),
            'currency' => $this->getCurrency(),
            'product' => $this->getProduct(),
            'description' => $this->getDescription(),
        ];


        $this->validate();
        $url = $this->prepare_api_url('processing/invoices');
        $headers = $this->headers();
        $response =  $this->send($url, static::HTTP_METHOD_POST, $headers, json_encode($data, true));
        $response_decode = json_decode($response['body'], true);
        $invoice_id = !empty($response_decode['invoice']['id']) ? $response_decode['invoice']['id'] : '';
        $access_token = !empty($response_decode['invoiceAccessToken']['payload']) ? $response_decode['invoiceAccessToken']['payload'] : '';
        return $response;
    }



    private function headers() {
        $headers = [];
        $headers[] = 'X-Request-ID: ' . uniqid();
        $headers[] = 'Authorization: Bearer ' . $this->merchant_private_key;
        $headers[] = 'Content-type: application/json; charset=utf-8';
        $headers[] = 'Accept: application/json';
        return $headers;
    }

  
    private function send($url, $method, $headers = [], $data = '')
    {
        if (empty($url)) {
            throw new Exception('Не передан обязательный параметр url');
        }

        $allowed_methods = [static::HTTP_METHOD_POST];
        if (!in_array($method, $allowed_methods)) {
            throw new Exception('Неподдерживаемый метод ' . $method);
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($curl);
        $info = curl_getinfo($curl);
        $curl_errno = curl_errno($curl);

        $response['http_code'] = $info['http_code'];
        $response['body'] = $body;
        $response['error'] = $curl_errno;

        curl_close($curl);
        return $response;
    }

    private function validate()
    {
        $this->checkRequiredFields();
        if (count($this->getErrors()) > 0) {
            $errors = 'Errors found: ' . implode(', ', $this->getErrors());
            throw new Exception($errors);
        }
        $this->clearErrors();
    }
}
