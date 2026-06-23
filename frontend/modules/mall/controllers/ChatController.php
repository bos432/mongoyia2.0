<?php

namespace frontend\modules\mall\controllers;

use common\models\mall\Product;
use common\services\mall\CustomerServiceRatingService;
use common\services\mall\CustomerServiceTranslationService;
use common\services\mall\CustomerServiceMediaService;
use Yii;
use yii\helpers\FileHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Class AddressController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class ChatController extends BaseController
{
    public const CHAT_POST_GUARD_VERSION = 'MONGOYIA_CUSTOMER_SERVICE_CHAT_POST_GUARD_V1';

    public function beforeAction($action)
    {
        if (in_array($action->id, ['upload', 'media-upload', 'token', 'translate', 'rating-submit'], true)) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex()
    {
        $gid = (int)Yii::$app->request->get('gid', 0);
        if ($gid <= 0) {
            throw new NotFoundHttpException(Yii::t('app', 'Invalid id'));
        }

        $product = Product::find()
            ->alias('p')
            ->select(['p.id', 'p.store_id', 's.user_id'])
            ->leftJoin(['s' => '{{%store}}'], 's.id = p.store_id')
            ->where(['p.id' => $gid])
            ->asArray()
            ->one();

        if (!$product || empty($product['user_id'])) {
            throw new NotFoundHttpException(Yii::t('app', 'Invalid id'));
        }

        $translationService = new CustomerServiceTranslationService();

        return $this->render($this->action->id, [
            'suid' => (int)$product['user_id'],
            'productId' => $gid,
            'storeId' => (int)$product['store_id'],
            'customerUserId' => Yii::$app->user->isGuest ? 0 : (int)Yii::$app->user->id,
            'customerUuid' => Yii::$app->user->isGuest ? ('guest-' . substr(md5(Yii::$app->session->id), 0, 16)) : ('user-' . (int)Yii::$app->user->id),
            'ratingLabels' => (new CustomerServiceRatingService())->labels(),
            'staffWorkLanguage' => $translationService->defaultStaffWorkLanguage(),
        ]);
    }

    public function actionTranslate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            Yii::$app->response->statusCode = 405;
            return ['code' => 405, 'msg' => $this->chatMessage('invalidRequestMethod')];
        }

        $request = Yii::$app->request;
        $service = new CustomerServiceTranslationService();
        $targetLanguage = (string)$request->post('target_language', '');
        if ($targetLanguage === '' && (string)$request->post('direction', '') === 'user_to_staff') {
            $targetLanguage = $service->defaultStaffWorkLanguage();
        }

        $translation = $service->translate(
            (string)$request->post('content', ''),
            (string)$request->post('source_language', ''),
            $targetLanguage !== '' ? $targetLanguage : 'en'
        );

        return [
            'code' => 200,
            'msg' => 'ok',
            'data' => $translation + [
                'metadata' => $service->messageMetadata($translation),
            ],
        ];
    }

    public function actionRatingSubmit()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            return $this->chatRequiresPost();
        }

        try {
            $result = (new CustomerServiceRatingService())->submit([
                'store_id' => (int)Yii::$app->request->post('store_id', 0),
                'product_id' => (int)Yii::$app->request->post('product_id', 0),
                'order_id' => (int)Yii::$app->request->post('order_id', 0),
                'ticket_id' => (int)Yii::$app->request->post('ticket_id', 0),
                'customer_user_id' => Yii::$app->user->isGuest ? 0 : (int)Yii::$app->user->id,
                'customer_uuid' => (string)Yii::$app->request->post('customer_uuid', ''),
                'chat_uuid' => (string)Yii::$app->request->post('chat_uuid', ''),
                'rating' => (string)Yii::$app->request->post('rating', ''),
                'reason' => (string)Yii::$app->request->post('reason', ''),
                'remark' => (string)Yii::$app->request->post('remark', ''),
            ]);

            return ['code' => 200, 'msg' => 'ok', 'data' => $result];
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 400;
            return ['code' => 400, 'msg' => $e->getMessage()];
        }
    }

    public function actionUpload()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            return $this->chatRequiresPost();
        }

        $file = UploadedFile::getInstanceByName('file');
        if (!$file) {
            return ['code' => 400, 'msg' => $this->chatMessage('uploadEmpty')];
        }

        if ($file->getHasError()) {
            return ['code' => 400, 'msg' => $this->chatMessage('uploadCheckFailed')];
        }

        if ($file->size > 5 * 1024 * 1024) {
            return ['code' => 413, 'msg' => $this->chatMessage('imageTooLarge')];
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'], true)) {
            return ['code' => 415, 'msg' => $this->chatMessage('imageTypeDenied')];
        }

        $imageInfo = @getimagesize($file->tempName);
        if ($imageInfo === false) {
            return ['code' => 415, 'msg' => $this->chatMessage('invalidImageFile')];
        }

        $relativeDir = 'chat/' . date('Y/m/d');
        $absoluteDir = Yii::getAlias('@attachment') . '/' . $relativeDir;
        FileHelper::createDirectory($absoluteDir);

        $prefix = Yii::$app->request->post('smoke') === '1' ? 'chat_smoke_' : 'chat_';
        $fileName = $prefix . date('ymd_His') . '_' . Yii::$app->security->generateRandomString(12) . '.' . $ext;
        $absolutePath = $absoluteDir . '/' . $fileName;
        if (!$file->saveAs($absolutePath)) {
            return ['code' => 500, 'msg' => $this->chatMessage('fileWriteFailed')];
        }

        $url = rtrim(Yii::getAlias('@attachmentUrl'), '/') . '/' . $relativeDir . '/' . $fileName;
        return [
            'code' => 200,
            'msg' => 'ok',
            'url' => $url,
            'data' => [
                'url' => $url,
                'size' => (int)$file->size,
                'width' => (int)($imageInfo[0] ?? 0),
                'height' => (int)($imageInfo[1] ?? 0),
            ],
        ];
    }

    public function actionMediaUpload()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            return $this->chatRequiresPost();
        }

        try {
            $media = (string)Yii::$app->request->post('media', '');
            $file = UploadedFile::getInstanceByName('file');
            if (!$file) {
                return ['code' => 400, 'msg' => $this->chatMessage('uploadEmpty')];
            }

            $stored = (new CustomerServiceMediaService())->upload($file, $media, [
                'duration' => (int)Yii::$app->request->post('duration', 0),
                'smoke' => Yii::$app->request->post('smoke') === '1',
            ]);
            return [
                'code' => 200,
                'msg' => 'ok',
                'data' => (new CustomerServiceMediaService())->responseData($stored),
            ];
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 400;
            return ['code' => 400, 'msg' => $e->getMessage()];
        }
    }

    public function actionMediaView($media_id, $token)
    {
        try {
            $file = (new CustomerServiceMediaService())->viewFile((string)$media_id, (string)$token);
        } catch (\Throwable $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return Yii::$app->response->sendFile($file['path'], $file['name'], [
            'mimeType' => $file['mime'],
            'inline' => in_array($file['media'], ['video', 'voice'], true),
        ]);
    }

    public function actionToken()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            return $this->chatRequiresPost();
        }

        $gid = (int)Yii::$app->request->post('gid', 0);
        $uuid = trim((string)Yii::$app->request->post('user_id', ''));
        if ($gid <= 0 || $uuid === '' || strlen($uuid) > 128) {
            return ['code' => 400, 'msg' => $this->chatMessage('invalidIdentity')];
        }

        $product = Product::find()
            ->alias('p')
            ->select(['p.id', 'p.store_id', 's.user_id'])
            ->leftJoin(['s' => '{{%store}}'], 's.id = p.store_id')
            ->where(['p.id' => $gid])
            ->asArray()
            ->one();

        if (!$product || empty($product['user_id'])) {
            return ['code' => 404, 'msg' => $this->chatMessage('productSupportMissing')];
        }

        return [
            'code' => 200,
            'msg' => 'ok',
            'data' => [
                'token' => $this->createImAuthToken([
                    'type' => 'user',
                    'user_id' => $uuid,
                    'uid' => (int)$product['user_id'],
                    'product_id' => $gid,
                    'store_id' => (int)$product['store_id'],
                ]),
                'uid' => (int)$product['user_id'],
                'product_id' => $gid,
                'store_id' => (int)$product['store_id'],
            ],
        ];
    }

    private function chatRequiresPost(): array
    {
        Yii::$app->response->statusCode = 405;
        return ['code' => 405, 'msg' => $this->chatMessage('invalidRequestMethod')];
    }

    private function createImAuthToken(array $payload)
    {
        $secret = (string)(Yii::$app->params['imAuthSecret'] ?? '');
        if ($secret === '') {
            return '';
        }

        $payload['exp'] = time() + (int)(Yii::$app->params['imAuthTokenTtl'] ?? 3600);
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $secret);

        return $encodedPayload . '.' . $signature;
    }

    private function chatMessage(string $key)
    {
        $messages = [
            'zh-CN' => [
                'uploadEmpty' => '上传文件为空',
                'uploadCheckFailed' => '上传失败，请检查文件',
                'imageTooLarge' => '图片大小不能超过5MB',
                'imageTypeDenied' => '图片类型不允许',
                'invalidImageFile' => '上传文件不是有效图片',
                'fileWriteFailed' => '文件写入失败',
                'mediaTransportDisabled' => '文件、视频、语音上传暂未开放',
                'invalidIdentity' => '客服身份参数无效',
                'productSupportMissing' => '商品客服不存在',
                'invalidRequestMethod' => '请求方式无效',
            ],
            'en' => [
                'uploadEmpty' => 'No file was uploaded',
                'uploadCheckFailed' => 'Upload failed. Please check the file.',
                'imageTooLarge' => 'Image size cannot exceed 5 MB',
                'imageTypeDenied' => 'This image type is not allowed',
                'invalidImageFile' => 'The uploaded file is not a valid image',
                'fileWriteFailed' => 'Could not save the uploaded file',
                'mediaTransportDisabled' => 'File, video, and voice uploads are not enabled yet',
                'invalidIdentity' => 'Invalid customer-service identity parameters',
                'productSupportMissing' => 'Product customer service was not found',
                'invalidRequestMethod' => 'Invalid request method',
            ],
            'mn' => [
                'uploadEmpty' => 'Байршуулах файл алга',
                'uploadCheckFailed' => 'Байршуулалт амжилтгүй боллоо. Файлыг шалгана уу.',
                'imageTooLarge' => 'Зургийн хэмжээ 5 MB-аас хэтрэхгүй байх ёстой',
                'imageTypeDenied' => 'Энэ зургийн төрөл зөвшөөрөгдөөгүй',
                'invalidImageFile' => 'Байршуулсан файл хүчинтэй зураг биш байна',
                'fileWriteFailed' => 'Байршуулсан файлыг хадгалж чадсангүй',
                'mediaTransportDisabled' => 'Файл, видео, дуу хоолой байршуулах боломж одоогоор нээгдээгүй байна',
                'invalidIdentity' => 'Хэрэглэгчийн үйлчилгээний таних параметр буруу байна',
                'productSupportMissing' => 'Бүтээгдэхүүний хэрэглэгчийн үйлчилгээ олдсонгүй',
                'invalidRequestMethod' => 'Хүсэлтийн арга буруу байна',
            ],
        ];

        $locale = $this->chatLocale();
        return $messages[$locale][$key] ?? $messages['zh-CN'][$key] ?? $key;
    }

    private function chatLocale()
    {
        $language = (string)Yii::$app->request->get('lang', Yii::$app->request->post('lang', Yii::$app->language ?: 'zh-CN'));
        $language = strtolower(str_replace('_', '-', $language));
        if (str_starts_with($language, 'mn')) {
            return 'mn';
        }
        if (str_starts_with($language, 'en')) {
            return 'en';
        }

        return 'zh-CN';
    }

}
