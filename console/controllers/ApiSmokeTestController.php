<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class ApiSmokeTestController extends Controller
{
    public $baseUrl = '';
    public $timeout = 15;

    private $failures = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'timeout',
        ]);
    }

    public function actionRun()
    {
        if ($this->baseUrl === '') {
            $this->baseUrl = (string)(Yii::$app->params['webBaseUrl'] ?? '');
        }
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->stdout("API smoke test against {$this->baseUrl}\n");

        foreach ($this->publicCases() as $case) {
            $this->checkPublicEndpoint($case['label'], $case['path'], $case['dataNeedle']);
        }

        $this->checkProtectedEndpoint('protected profile', '/api/site/profile');

        if ($this->failures) {
            $this->stderr("\nFailed API smoke checks:\n");
            foreach ($this->failures as $failure) {
                $this->stderr("- {$failure}\n");
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nAll API smoke checks passed.\n");
        return ExitCode::OK;
    }

    private function publicCases()
    {
        return [
            ['label' => 'api root', 'path' => '/api', 'dataNeedle' => 'Mongoyia API'],
            ['label' => 'api site index', 'path' => '/api/site/index', 'dataNeedle' => 'Mongoyia API'],
            ['label' => 'api v1 default', 'path' => '/api/v1/default/index', 'dataNeedle' => 'v1'],
        ];
    }

    private function checkPublicEndpoint(string $label, string $path, string $dataNeedle)
    {
        $response = $this->get($path);
        if ($response['status'] !== 200) {
            $this->fail("{$label} expected HTTP 200, got {$response['status']} from {$path}");
            return;
        }

        if (!$this->checkNoFatalMarkers($label, $path, $response['body'])) {
            return;
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            $this->fail("{$label} expected JSON response from {$path}");
            return;
        }

        if ((int)($json['code'] ?? 0) !== 200) {
            $this->fail("{$label} expected JSON code 200, got " . (string)($json['code'] ?? 'missing') . " from {$path}");
            return;
        }

        if (stripos($response['body'], $dataNeedle) === false) {
            $this->fail("{$label} missing expected data '{$dataNeedle}' from {$path}");
            return;
        }

        $this->stdout("PASS {$label}: HTTP {$response['status']} {$path}\n");
    }

    private function checkProtectedEndpoint(string $label, string $path)
    {
        $response = $this->get($path);
        if (!in_array($response['status'], [401, 403], true)) {
            $this->fail("{$label} expected HTTP 401/403, got {$response['status']} from {$path}");
            return;
        }

        if (!$this->checkNoFatalMarkers($label, $path, $response['body'])) {
            return;
        }

        $this->stdout("PASS {$label}: HTTP {$response['status']} {$path}\n");
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

    private function get(string $path)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => "User-Agent: MongoyiaApiSmokeTest/1.0\r\nAccept: application/json\r\n",
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

    private function fail(string $message)
    {
        $this->failures[] = $message;
        $this->stderr("FAIL {$message}\n");
    }
}
