<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MallSmokeTestController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $productIds = '90,102';
    public $categoryId = '';
    public $timeout = 15;
    public $checkLanguageText = true;
    public $allowedChineseTerms = '中文';
    public $hostSmokeHosts = 'mongoyia.com,mn.zlck888.com,www.funpay.com';
    public $zeroPriceProductId = 0;

    private $failures = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'productIds',
            'categoryId',
            'timeout',
            'checkLanguageText',
            'allowedChineseTerms',
            'hostSmokeHosts',
            'zeroPriceProductId',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->stdout("Mall frontend smoke test against {$this->baseUrl}\n");

        foreach ($this->buildCases() as $case) {
            $this->checkPage($case['label'], $case['path'], $case['needles'] ?? [], $case['lang'] ?? '', $case['forbidden'] ?? []);
        }

        $this->checkChatToken();
        $this->checkChatLocalizedErrors();
        $this->checkChatImageUpload();
        $this->checkChatScriptLocalizedErrors();
        $this->checkPublicLayoutLanguage();
        $this->checkZeroPriceCartBlocked();
        $this->checkHostRoutes();

        if ($this->failures) {
            $this->stderr("\nFailed smoke checks:\n");
            foreach ($this->failures as $failure) {
                $this->stderr("- {$failure}\n");
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nAll mall frontend smoke checks passed.\n");
        return ExitCode::OK;
    }

    private function buildCases()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        $primaryProductId = $ids[0] ?? 90;
        $chatProductId = $ids[1] ?? $primaryProductId;

        $cases = [
            ['label' => 'home', 'path' => '/', 'needles' => ['Mongoyia']],
            ['label' => 'product zh', 'path' => "/product/{$primaryProductId}"],
            ['label' => 'product en', 'path' => "/product/{$primaryProductId}?lang=en", 'lang' => 'en'],
            ['label' => 'product mn', 'path' => "/product/{$primaryProductId}?lang=mn", 'lang' => 'mn'],
            ['label' => 'cart mn', 'path' => '/mall/cart/index?lang=mn', 'lang' => 'mn'],
            ['label' => 'login', 'path' => '/mall/default/login'],
            ['label' => 'chat entry', 'path' => "/mall/chat/index?gid={$chatProductId}", 'needles' => $this->chatNeedles($chatProductId)],
            [
                'label' => 'chat en',
                'path' => "/mall/chat/index?gid={$chatProductId}&lang=en",
                'needles' => array_merge($this->chatNeedles($chatProductId), $this->chatLangNeedles('en')),
                'lang' => 'en',
                'forbidden' => $this->chatChineseNeedles(),
            ],
            [
                'label' => 'chat mn',
                'path' => "/mall/chat/index?gid={$chatProductId}&lang=mn",
                'needles' => array_merge($this->chatNeedles($chatProductId), $this->chatLangNeedles('mn')),
                'lang' => 'mn',
                'forbidden' => $this->chatChineseNeedles(),
            ],
        ];

        if ($this->categoryId !== '') {
            array_splice($cases, 4, 0, [[
                'label' => 'category',
                'path' => '/category/' . (int)$this->categoryId,
            ]]);
        }

        return $cases;
    }

    private function chatNeedles(int $productId)
    {
        $needles = [
            'merchantId:',
            'productId: ' . $productId,
            'storeId:',
            'wsAddress:',
            'tokenUrl:',
            'uploadUrl:',
            'connect()',
        ];

        $product = (new \yii\db\Query())
            ->select(['p.id', 'p.store_id', 'merchant_id' => 's.user_id'])
            ->from(['p' => '{{%mall_product}}'])
            ->leftJoin(['s' => '{{%store}}'], 's.id = p.store_id')
            ->where(['p.id' => $productId])
            ->one(Yii::$app->db);

        if ($product) {
            $needles[] = 'merchantId: ' . (int)$product['merchant_id'];
            $needles[] = 'storeId: ' . (int)$product['store_id'];
        }

        return $needles;
    }

    private function chatLangNeedles(string $lang)
    {
        return [
            'tokenUrl:',
            'uploadUrl:',
            'lang=' . $lang,
            'lang: "' . $lang . '"',
        ];
    }

    private function chatChineseNeedles()
    {
        return [
            '在线客服',
            '连接中...',
            '表情',
            '图片',
            '输入消息...',
            '商品 #',
            '店铺 #',
            '暂无历史消息',
            '连接成功，开始对话',
            '连接失败，请刷新页面重试',
            '连接已断开',
            '正在重新连接...',
            '错误',
            '未知错误',
        ];
    }

    private function checkPage(string $label, string $path, array $needles = [], string $lang = '', array $forbidden = [])
    {
        $url = $this->baseUrl . $path;
        $response = $this->get($url);

        if ($response['status'] < 200 || $response['status'] >= 400) {
            $this->fail("{$label} expected HTTP 2xx/3xx, got {$response['status']} from {$url}");
            return;
        }

        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($response['body'], $needle) !== false) {
                $this->fail("{$label} contains fatal marker '{$needle}' from {$url}");
                return;
            }
        }

        foreach ($needles as $needle) {
            if (stripos($response['body'], $needle) === false) {
                $this->fail("{$label} missing expected text '{$needle}' from {$url}");
                return;
            }
        }

        $translatableHtml = $this->translatableHtml($response['body']);
        foreach ($forbidden as $needle) {
            if (stripos($translatableHtml, $needle) !== false) {
                $this->fail("{$label} contains untranslated chat text '{$needle}' from {$url}");
                return;
            }
        }

        if ($this->checkLanguageText && $lang !== '' && $lang !== 'zh-CN' && !$this->checkNonChinesePageText($label, $url, $response['body'])) {
            return;
        }

        $this->stdout("PASS {$label}: HTTP {$response['status']} {$path}\n");
    }

    private function checkChatToken()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        $productId = $ids[1] ?? ($ids[0] ?? 90);
        $response = $this->post('/mall/chat/token', [
            'gid' => $productId,
            'user_id' => 'mall_smoke_' . time(),
        ]);

        if ($response['status'] !== 200) {
            $this->fail("chat token expected HTTP 200, got {$response['status']} from /mall/chat/token");
            return;
        }

        if (!$this->checkNoFatalMarkers('chat token', '/mall/chat/token', $response['body'])) {
            return;
        }

        $json = json_decode($response['body'], true);
        $token = (string)($json['data']['token'] ?? '');
        if ((int)($json['code'] ?? 0) !== 200 || substr_count($token, '.') !== 1) {
            $this->fail('chat token expected JSON code 200 with signed token from /mall/chat/token');
            return;
        }

        $this->stdout("PASS chat token: HTTP {$response['status']} /mall/chat/token\n");
    }

    private function checkChatLocalizedErrors()
    {
        foreach (['en', 'mn'] as $lang) {
            $tokenResponse = $this->post('/mall/chat/token?lang=' . $lang, [
                'gid' => 0,
                'user_id' => '',
            ]);
            if (!$this->assertLocalizedJsonError('chat token ' . $lang . ' error', '/mall/chat/token?lang=' . $lang, $tokenResponse, 400)) {
                return;
            }

            $uploadResponse = $this->post('/mall/chat/upload?lang=' . $lang, []);
            if (!$this->assertLocalizedJsonError('chat upload ' . $lang . ' error', '/mall/chat/upload?lang=' . $lang, $uploadResponse, 400)) {
                return;
            }

            $typeDenied = $this->postSmokeUploadFile('/mall/chat/upload?lang=' . $lang, 'chat-smoke.txt', 'text/plain', 'not an image');
            if (!$this->assertLocalizedJsonError('chat upload ' . $lang . ' type error', '/mall/chat/upload?lang=' . $lang, $typeDenied, 415)) {
                return;
            }

            $invalidImage = $this->postSmokeUploadFile('/mall/chat/upload?lang=' . $lang, 'chat-smoke-invalid.png', 'image/png', 'not a real png');
            if (!$this->assertLocalizedJsonError('chat upload ' . $lang . ' invalid image error', '/mall/chat/upload?lang=' . $lang, $invalidImage, 415)) {
                return;
            }

            $tooLargeBytes = 5 * 1024 * 1024 + 1;
            $tooLarge = $this->postSmokeUploadFile('/mall/chat/upload?lang=' . $lang, 'chat-smoke-too-large.png', 'image/png', str_repeat('0', $tooLargeBytes));
            if (!$this->assertLocalizedJsonError('chat upload ' . $lang . ' size error', '/mall/chat/upload?lang=' . $lang, $tooLarge, $this->expectedChatTooLargeUploadCodes($tooLargeBytes))) {
                return;
            }
        }

        $this->stdout("PASS chat localized errors: token/upload/type/image/size en,mn\n");
    }

    private function checkChatScriptLocalizedErrors()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        $productId = $ids[1] ?? ($ids[0] ?? 90);
        $cases = [
            'en' => ['Error', 'Unknown error'],
            'mn' => ['Алдаа', 'Тодорхойгүй алдаа'],
        ];

        foreach ($cases as $lang => $expected) {
            $path = '/mall/chat/index?gid=' . $productId . '&lang=' . $lang;
            $response = $this->get($this->baseUrl . $path);
            if ($response['status'] < 200 || $response['status'] >= 400) {
                $this->fail("chat script {$lang} expected HTTP 2xx/3xx, got {$response['status']} from {$path}");
                return;
            }
            if (!$this->checkNoFatalMarkers('chat script ' . $lang, $path, $response['body'])) {
                return;
            }

            foreach (['错误:', '未知错误'] as $needle) {
                if (strpos($response['body'], $needle) !== false) {
                    $this->fail("chat script {$lang} contains old Chinese error fallback '{$needle}' from {$path}");
                    return;
                }
            }

            foreach ($expected as $needle) {
                if (strpos($response['body'], $needle) === false) {
                    $this->fail("chat script {$lang} missing localized error fallback '{$needle}' from {$path}");
                    return;
                }
            }
        }

        $this->stdout("PASS chat script localized errors: en,mn\n");
    }

    private function checkChatImageUpload()
    {
        $filePath = $this->createSmokePng();
        try {
            $response = $this->postMultipart('/mall/chat/upload?lang=en', [
                'smoke' => '1',
            ], [
                'file' => [
                    'path' => $filePath,
                    'name' => 'chat-smoke.png',
                    'type' => 'image/png',
                ],
            ]);

            if ($response['status'] !== 200) {
                $this->fail("chat image upload expected HTTP 200, got {$response['status']} from /mall/chat/upload?lang=en");
                return;
            }
            if (!$this->checkNoFatalMarkers('chat image upload', '/mall/chat/upload?lang=en', $response['body'])) {
                return;
            }

            $json = json_decode($response['body'], true);
            $url = (string)($json['data']['url'] ?? $json['url'] ?? '');
            if ((int)($json['code'] ?? 0) !== 200 || $url === '') {
                $this->fail('chat image upload expected JSON code 200 with uploaded image URL');
                return;
            }
            if (!preg_match('#^/attachment/chat/\d{4}/\d{2}/\d{2}/chat_smoke_[^/]+\.png$#', $url)) {
                $this->fail("chat image upload returned unexpected URL '{$url}'");
                return;
            }
            if ((int)($json['data']['width'] ?? 0) !== 1 || (int)($json['data']['height'] ?? 0) !== 1) {
                $this->fail("chat image upload expected 1x1 image metadata, got width=" . ($json['data']['width'] ?? '') . " height=" . ($json['data']['height'] ?? ''));
                return;
            }

            $this->deleteUploadedSmokeFile($url);
            $this->stdout("PASS chat image upload: {$url}\n");
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    private function createSmokePng()
    {
        $filePath = Yii::getAlias('@runtime') . '/chat-smoke.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        if ($png === false || file_put_contents($filePath, $png) === false) {
            throw new \RuntimeException('Unable to create chat upload smoke image at ' . $filePath);
        }

        return $filePath;
    }

    private function createSmokeTextFile(string $name, string $content)
    {
        $filePath = Yii::getAlias('@runtime') . '/' . $name;
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException('Unable to create chat upload smoke file at ' . $filePath);
        }

        return $filePath;
    }

    private function postSmokeUploadFile(string $path, string $name, string $type, string $content)
    {
        $filePath = $this->createSmokeTextFile($name, $content);
        try {
            return $this->postMultipart($path, [], [
                'file' => [
                    'path' => $filePath,
                    'name' => $name,
                    'type' => $type,
                ],
            ]);
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    private function deleteUploadedSmokeFile(string $url)
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        if (!preg_match('#^/attachment/chat/\d{4}/\d{2}/\d{2}/chat_smoke_[^/]+\.png$#', $path)) {
            return;
        }

        $filePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function checkPublicLayoutLanguage()
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        $productId = $ids[1] ?? ($ids[0] ?? 90);
        $path = '/mall/chat/index?gid=' . $productId . '&lang=mn';
        $response = $this->get($this->baseUrl . $path);

        if ($response['status'] < 200 || $response['status'] >= 400) {
            $this->fail("public layout mn expected HTTP 2xx/3xx, got {$response['status']} from {$path}");
            return;
        }
        if (!$this->checkNoFatalMarkers('public layout mn', $path, $response['body'])) {
            return;
        }

        foreach (['Бараа:', 'Нүүр', 'Хайлт', '$99-өөс дээш захиалгад хүргэлт үнэгүй'] as $needle) {
            if (strpos($response['body'], $needle) === false) {
                $this->fail("public layout mn missing localized layout text '{$needle}' from {$path}");
                return;
            }
        }
        foreach (['Item:', '>Home<', 'Free Shipping for all Order of $99'] as $needle) {
            if (strpos($response['body'], $needle) !== false) {
                $this->fail("public layout mn contains old layout text '{$needle}' from {$path}");
                return;
            }
        }

        if (!preg_match('/<script[^>]+src=["\']([^"\']*main\.js[^"\']*)["\']/i', $response['body'], $matches)) {
            $this->fail("public layout mn could not find main.js asset from {$path}");
            return;
        }

        $scriptUrl = $this->absoluteUrl(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $script = $this->get($scriptUrl);
        if ($script['status'] < 200 || $script['status'] >= 400) {
            $this->fail("public layout mn expected main.js HTTP 2xx/3xx, got {$script['status']} from {$scriptUrl}");
            return;
        }
        foreach (['Бид таны хэрэглээг сайжруулахын тулд cookie ашигладаг', 'Зөвшөөрөх'] as $needle) {
            if (strpos($script['body'], $needle) === false) {
                $this->fail("public layout mn main.js missing localized cookie text '{$needle}' from {$scriptUrl}");
                return;
            }
        }

        $this->stdout("PASS public layout mn: header/cart/breadcrumb/search/cookie text\n");
    }

    private function assertLocalizedJsonError(string $label, string $path, array $response, $expectedCode)
    {
        $expectedCodes = is_array($expectedCode) ? array_map('intval', $expectedCode) : [(int)$expectedCode];
        if ($response['status'] !== 200) {
            $this->fail("{$label} expected HTTP 200 JSON business error, got {$response['status']} from {$path}");
            return false;
        }

        if (!$this->checkNoFatalMarkers($label, $path, $response['body'])) {
            return false;
        }

        $json = json_decode($response['body'], true);
        $code = (int)($json['code'] ?? 0);
        $msg = (string)($json['msg'] ?? '');
        if (!in_array($code, $expectedCodes, true) || $msg === '') {
            $this->fail("{$label} expected JSON code " . implode('/', $expectedCodes) . " with message from {$path}");
            return false;
        }

        if (preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $msg, $matches)) {
            $this->fail("{$label} returned untranslated Chinese message '{$matches[0]}' from {$path}");
            return false;
        }

        return true;
    }

    private function expectedChatTooLargeUploadCodes(int $payloadBytes)
    {
        $codes = [413];
        $uploadLimit = $this->phpShorthandBytes((string)ini_get('upload_max_filesize'));
        $postLimit = $this->phpShorthandBytes((string)ini_get('post_max_size'));

        if (($uploadLimit !== null && $uploadLimit > 0 && $payloadBytes > $uploadLimit)
            || ($postLimit !== null && $postLimit > 0 && ($payloadBytes + 1024) > $postLimit)
        ) {
            $codes[] = 400;
        }

        return array_values(array_unique($codes));
    }

    private function phpShorthandBytes(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(-?\d+)([kmg])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int)$matches[1];
        switch (strtolower($matches[2] ?? '')) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }

    private function checkZeroPriceCartBlocked()
    {
        $productId = $this->zeroPriceCartProductId();
        if ($productId <= 0) {
            $this->stdout("WARN zero-price cart protection skipped; no zero-price product id configured.\n");
            return;
        }

        $product = (new \yii\db\Query())
            ->select(['id', 'price', 'status'])
            ->from('{{%mall_product}}')
            ->where(['id' => $productId])
            ->one(Yii::$app->db);
        if (!$product || (float)$product['price'] > 0) {
            $this->stdout("WARN zero-price cart protection skipped; product {$productId} is not a zero-price product.\n");
            return;
        }

        $page = $this->getWithHeaders($this->baseUrl . '/product/' . $productId);
        if ($page['status'] < 200 || $page['status'] >= 400) {
            $this->fail("zero-price product page expected HTTP 2xx/3xx, got {$page['status']} from /product/{$productId}");
            return;
        }
        if (!$this->checkNoFatalMarkers('zero-price product page', '/product/' . $productId, $page['body'])) {
            return;
        }

        $csrf = $this->extractCsrfToken($page['body']);
        if ($csrf === '') {
            $this->fail("zero-price product {$productId} page did not expose CSRF token for cart smoke");
            return;
        }

        $before = $this->cartRowCount($productId);
        $response = $this->post('/mall/cart/edit-ajax', [
            'product_id' => $productId,
            'number' => 1,
            'product_attribute_value' => '',
            '_csrf' => $csrf,
        ], $this->cookieHeaders($page['headers']));
        $after = $this->cartRowCount($productId);

        if ($response['status'] !== 200) {
            $this->fail("zero-price cart add expected HTTP 200 business error, got {$response['status']} from /mall/cart/edit-ajax");
            return;
        }
        if (!$this->checkNoFatalMarkers('zero-price cart add', '/mall/cart/edit-ajax', $response['body'])) {
            return;
        }

        $json = json_decode($response['body'], true);
        $code = (int)($json['code'] ?? 0);
        $msg = (string)($json['msg'] ?? '');
        if ($code === 200 || stripos($msg, 'price') === false) {
            $this->fail("zero-price cart add expected price business error, got code={$code} msg={$msg}");
            return;
        }
        if ((int)$before !== (int)$after) {
            $this->fail("zero-price cart add changed cart rows for product {$productId}: before={$before}, after={$after}");
            return;
        }

        $this->stdout("PASS zero-price cart blocked: product {$productId} code {$code}\n");
    }

    private function zeroPriceCartProductId()
    {
        if ((int)$this->zeroPriceProductId > 0) {
            return (int)$this->zeroPriceProductId;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', $this->productIds))));
        return $ids[1] ?? 0;
    }

    private function checkHostRoutes()
    {
        foreach (array_filter(array_map('trim', explode(',', (string)$this->hostSmokeHosts))) as $host) {
            $response = $this->get($this->baseUrl . '/', ['Host: ' . $host]);
            if ($response['status'] < 200 || $response['status'] >= 400) {
                $this->fail("host route {$host} expected HTTP 2xx/3xx, got {$response['status']}");
                continue;
            }

            if (!$this->checkNoFatalMarkers('host route ' . $host, '/', $response['body'])) {
                continue;
            }

            if (stripos($response['body'], 'FunPay') !== false) {
                $this->fail("host route {$host} rendered legacy FunPay content");
                continue;
            }

            if (stripos($response['body'], 'mongoyia') === false) {
                $this->fail("host route {$host} did not render Mongoyia mall content");
                continue;
            }

            $this->stdout("PASS host route {$host}: HTTP {$response['status']} /\n");
        }
    }

    private function checkNonChinesePageText(string $label, string $url, string $html)
    {
        $text = $this->visibleText($html);
        foreach ($this->allowedChineseTermList() as $term) {
            $text = str_replace($term, '', $text);
        }

        preg_match_all('/[\x{4e00}-\x{9fff}]{2,}/u', $text, $matches);
        $terms = array_values(array_unique($matches[0] ?? []));
        if ($terms) {
            $preview = implode(', ', array_slice($terms, 0, 8));
            $this->fail("{$label} visible text contains Chinese terms after allowlist: {$preview} from {$url}");
            return false;
        }

        return true;
    }

    private function visibleText(string $html)
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/u', ' ', $text);
    }

    private function translatableHtml(string $html)
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html);
        $html = preg_replace('/<!--.*?-->/s', ' ', $html);
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function allowedChineseTermList()
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$this->allowedChineseTerms))));
    }

    private function fatalNeedles()
    {
        return [
            'yii\base\ErrorException',
            'yii\db\Exception',
            'PHP Warning',
            'PHP Fatal error',
            'Stack trace:',
            'Call to undefined',
            'Trying to get property',
        ];
    }

    private function absoluteUrl(string $url)
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME) ?: 'http';
            return $scheme . ':' . $url;
        }
        if (strpos($url, '/') === 0) {
            return $this->baseUrl . $url;
        }
        return $this->baseUrl . '/' . $url;
    }

    private function get(string $url, array $headers = [])
    {
        $response = $this->getWithHeaders($url, $headers);
        return [
            'status' => $response['status'],
            'body' => $response['body'],
        ];
    }

    private function getWithHeaders(string $url, array $headers = [])
    {
        $headers = array_merge(['User-Agent: MongoyiaSmokeTest/1.0'], $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'headers' => $http_response_header ?? [],
        ];
    }

    private function post(string $path, array $data, array $headers = [])
    {
        $headers = array_merge([
            'User-Agent: MongoyiaSmokeTest/1.0',
            'Content-Type: application/x-www-form-urlencoded',
        ], $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => http_build_query($data),
            ],
        ]);

        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
        ];
    }

    private function postMultipart(string $path, array $data, array $files, array $headers = [])
    {
        $boundary = '----MongoyiaSmoke' . bin2hex(random_bytes(8));
        $content = '';

        foreach ($data as $name => $value) {
            $content .= "--{$boundary}\r\n";
            $content .= 'Content-Disposition: form-data; name="' . $this->escapeMultipartName((string)$name) . '"' . "\r\n\r\n";
            $content .= (string)$value . "\r\n";
        }

        foreach ($files as $name => $file) {
            $filePath = (string)($file['path'] ?? '');
            if (!is_file($filePath)) {
                throw new \RuntimeException('Multipart file does not exist: ' . $filePath);
            }

            $fileName = (string)($file['name'] ?? basename($filePath));
            $mimeType = (string)($file['type'] ?? 'application/octet-stream');
            $content .= "--{$boundary}\r\n";
            $content .= 'Content-Disposition: form-data; name="' . $this->escapeMultipartName((string)$name) . '"; filename="' . $this->escapeMultipartName($fileName) . '"' . "\r\n";
            $content .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
            $content .= file_get_contents($filePath) . "\r\n";
        }

        $content .= "--{$boundary}--\r\n";
        $headers = array_merge([
            'User-Agent: MongoyiaSmokeTest/1.0',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($content),
        ], $headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $content,
            ],
        ]);

        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
        ];
    }

    private function escapeMultipartName(string $value)
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }

    private function extractCsrfToken(string $html)
    {
        if (preg_match("/_csrf:\\s*'([^']+)'/i", $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function cookieHeaders(array $headers)
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $cookie = explode(';', $cookie, 2)[0] ?? '';
            if ($cookie !== '') {
                $cookies[] = $cookie;
            }
        }

        return $cookies ? ['Cookie: ' . implode('; ', $cookies)] : [];
    }

    private function cartRowCount($productId)
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_cart}}')
            ->where(['product_id' => (int)$productId])
            ->count('*', Yii::$app->db);
    }

    private function checkNoFatalMarkers(string $label, string $path, string $body)
    {
        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($body, $needle) !== false) {
                $this->fail("{$label} contains fatal marker '{$needle}' from {$path}");
                return false;
            }
        }

        return true;
    }

    private function fail(string $message)
    {
        $this->failures[] = $message;
        $this->stderr("FAIL {$message}\n");
    }
}
