<?php

/**
 * Class Coinzone
 */
class Coinzone
{

    /**
     * Coinzone API URL
     */
    const API_URL = 'https://api.coinzone.com/v2/';

    /**
     * @var string
     */
    private $clientCode;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param $apiKey
     * @param $clientCode
     */
    function __construct($apiKey, $clientCode)
    {
        $this->apiKey = $apiKey;
        $this->clientCode = $clientCode;
    }

    /**
     * @return mixed
     */
    public function getApiKey()
    {
        return html_entity_decode($this->apiKey);
    }

    /**
     * @param mixed $apiKey
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @return mixed
     */
    public function getClientCode()
    {
        return $this->clientCode;
    }

    /**
     * @param mixed $clientCode
     */
    public function setClientCode($clientCode)
    {
        $this->clientCode = $clientCode;
    }

    /**
     * @param $path
     * @param $payload
     * @param $timestamp
     * @return string
     */
    private function createSignature($path, $payload = null, $timestamp)
    {
        $stringToSign = $payload . $path . $timestamp;
        $signature = hash_hmac('sha256', $stringToSign, $this->getApiKey());

        return $signature;
    }

    /**
     * Set headers and sign the request
     * @param $path
     * @param array $payload
     */
    private function prepareRequest($path, array $payload)
    {
        $timestamp = time();
        if (!empty($payload)){
            $payload = json_encode($payload);
        }

        $signature = $this->createSignature($path, $payload, $timestamp);

        $this->headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'clientCode: ' . $this->clientCode,
            'timestamp: ' . $timestamp,
            'signature: ' . $signature
        );
    }

    /**
     * API Call
     * @param $path
     * @param $payload
     * @return mixed|string
     */
    public function callApi($path, $payload = '')
    {
        $this->prepareRequest(self::API_URL.$path, $payload);

        $url = self::API_URL . $path;
        $curlHandler = curl_init($url);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);

        if (!empty($payload)) {
            curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curlHandler, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $result = curl_exec($curlHandler);
        if ($result === false) {
            return false;
        }
        $response = json_decode($result);
        error_log(print_r($response,1));
        curl_close($curlHandler);

        return $response;
    }

    public function checkRequest($string)
    {
        $headers = $this->getHeaders();

        $headerTimestamp = empty($headers['timestamp']) ? '1412263015' : $headers['timestamp'];
        $headerSignature = empty($headers['signature']) ? '' : $headers['signature'];

        $signature = $this->createSignature(
            WC()->api_request_url('WC_Gateway_Coinzone'),
            $string,
            $headerTimestamp
        );

        if ($headerSignature != $signature) {
            http_response_code(400);
            die(json_encode(
                array(
                    'error' => true,
                    'message' => __('Invalid signature.', 'coinzone')
                )
            ));
        }


    }

    private function getHeaders()
    {
        if (!function_exists('getallheaders')) {
            foreach ($_SERVER as $name => $value) {
                if (strtolower(substr($name, 0, 5)) == 'http_') {
                    $headers[str_replace(
                        ' ',
                        '-',
                        ucwords(strtolower(str_replace('_', ' ', substr($name, 5))))
                    )] = $value;
                }
            }
            $request_headers = $headers;
        } else {
            $request_headers = getallheaders();
        }

        return $request_headers;
    }



} 