<?php

namespace common\helpers;

class GoogleTranslate
{
    public static $connectTimeout = 5;
    public static $timeout = 15;
    public static $proxy = '';
    private static $lastError = '';
    private static $lastHttpCode = 0;
    private static $lastResponseSample = '';

    public static function setTimeouts($connectTimeout, $timeout)
    {
        self::$connectTimeout = max(1, (int)$connectTimeout);
        self::$timeout = max(1, (int)$timeout);
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    public static function getLastDiagnostic()
    {
        $parts = array_filter([
            self::$lastError,
            self::$lastHttpCode ? 'http=' . self::$lastHttpCode : '',
            self::$lastResponseSample ? 'response=' . self::$lastResponseSample : '',
        ]);
        return implode('; ', $parts);
    }

    public static function setProxy($proxy)
    {
        self::$proxy = trim((string)$proxy);
    }

    /**
     * Retrieves the translation of a text
     *
     * @param string $source
     *            Original language of the text on notation xx. For example: es, en, it, fr...
     * @param string $target
     *            Language to which you want to translate the text in format xx. For example: es, en, it, fr...
     * @param string $text
     *            Text that you want to translate
     *
     * @return string a simple string with the translation of the text in the target language
     */
    public static function translate($source, $target, $text, $type = 'intl')
    {
        $text = trim((string)$text);
        if ($text === '' || $source === $target) {
            return $text;
        }

        // Request translation
        $response = self::requestTranslation($source, $target, $text, $type);

        // Clean translation
        return self::getSentencesFromJSON($response);
    }

    /**
     * Internal function to make the request to the translator service
     *
     * @internal
     *
     * @param string $source
     *            Original language taken from the 'translate' function
     * @param string $target
     *            Target language taken from the ' translate' function
     * @param string $text
     *            Text to translate taken from the 'translate' function
         * @param string $type
         *            'intl' use `translate.google.com` API, 'cn' use 'translate.google.cn' API. (default use translate.google.com)
     *
     * @return object[] The response of the translation service in JSON format
     */
    protected static function requestTranslation($source, $target, $text, $type='intl')
    {
        self::$lastError = '';
        self::$lastHttpCode = 0;
        self::$lastResponseSample = '';

        if ($type === 'gtx') {
            $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
                'client' => 'gtx',
                'sl' => $source,
                'tl' => $target,
                'dt' => 't',
                'q' => $text,
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            if (self::$proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, self::$proxy);
            }
            $result = curl_exec($ch);
            if ($result === false) {
                self::$lastError = curl_error($ch);
            }
            $info = curl_getinfo($ch);
            self::$lastHttpCode = (int)($info['http_code'] ?? 0);
            self::$lastResponseSample = self::sampleResponse($result);
            if (self::$lastError === '' && self::$lastHttpCode >= 400) {
                self::$lastError = 'HTTP ' . self::$lastHttpCode;
            }
            curl_close($ch);

            return $result;
        }

        if($type == 'intl'){//use 'translate.google.com' API
            $host = 'translate.google.com';
        }else{//use 'translate.google.cn' API
            $host = 'translate.google.cn';
        }

        // Google translate URL
        $url = "https://{$host}/translate_a/single?client=at&dt=t&dt=ld&dt=qca&dt=rm&dt=bd&dj=1&hl=es-ES&ie=UTF-8&oe=UTF-8&inputm=2&otf=2&iid=1dd3b944-fa62-4b55-b330-74909a99969e";

        $fields = array(
            'sl' => urlencode($source),
            'tl' => urlencode($target),
            'q' => urlencode($text)
        );

        // URL-ify the data for the POST
        $fields_string = "";
        foreach ($fields as $key => $value) {
            $fields_string .= $key . '=' . $value . '&';
        }

        rtrim($fields_string, '&');

        // Open connection
        $ch = curl_init();

        // Set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, count($fields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, 'UTF-8');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AndroidTranslate/5.3.0.RC02.130475354-53000263 5.1 phone TRANSLATE_OPM5_TEST_1');
        if (self::$proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, self::$proxy);
        }

        // Execute post
        $result = curl_exec($ch);
        if ($result === false) {
            self::$lastError = curl_error($ch);
        }
        $info = curl_getinfo($ch);
        self::$lastHttpCode = (int)($info['http_code'] ?? 0);
        self::$lastResponseSample = self::sampleResponse($result);
        if (self::$lastError === '' && self::$lastHttpCode >= 400) {
            self::$lastError = 'HTTP ' . self::$lastHttpCode;
        }

        // Close connection
        curl_close($ch);

        return $result;
    }

    /**
     * Dump of the JSON's response in an array
     *
     * @param string $json
     *            The JSON object returned by the request function
     *
     * @return string A single string with the translation
     */
    protected static function getSentencesFromJSON($json)
    {
        $sentencesArray = json_decode($json, true);
        if ($sentencesArray === null && json_last_error() !== JSON_ERROR_NONE) {
            if (self::$lastError === '') {
                self::$lastError = json_last_error_msg();
            }
            return '';
        }

        $sentences = "";

        if (isset($sentencesArray[0]) && is_array($sentencesArray[0])) {
            foreach ($sentencesArray[0] as $s) {
                $sentences .= $s[0] ?? '';
            }
            return $sentences;
        }

        if (isset($sentencesArray["sentences"]) && is_array($sentencesArray["sentences"])) {
            foreach ($sentencesArray["sentences"] as $s) {
                $sentences .= isset($s["trans"]) ? $s["trans"] : '';
            }
        }

        return $sentences;
    }

    private static function sampleResponse($response)
    {
        $response = trim(preg_replace('/\s+/u', ' ', (string)$response));
        if ($response === '') {
            return '';
        }
        return mb_substr($response, 0, 120, 'UTF-8');
    }
}
