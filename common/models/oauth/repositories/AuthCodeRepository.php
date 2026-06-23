<?php

namespace common\models\oauth\repositories;

use common\models\oauth\entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use Yii;

/**
 * Class AuthCodeRepository
 * @package common\models\oauth\repositories
 * @author funson86 <funson86@gmail.com>
 */
class AuthCodeRepository implements \League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface
{
    public const VERSION = 'MONGOYIA_OAUTH_AUTH_CODE_REPOSITORY_V1';

    /**
     * @inheritDoc
     */
    public function getNewAuthCode()
    {
        return new AuthCodeEntity();
    }

    /**
     * @inheritDoc
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        if (Yii::$app->oauthSystem->authorizationCodeFindByCode($authCodeEntity->getIdentifier())) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        $time = $authCodeEntity->getExpiryDateTime();

        return Yii::$app->oauthSystem->authorizationCodeCreate(
            $authCodeEntity->getClient()->getIdentifier(),
            $authCodeEntity->getIdentifier(),
            $time->getTimestamp(),
            $authCodeEntity->getScopes(),
            $authCodeEntity->getRedirectUri()
        );
    }

    /**
     * @inheritDoc
     */
    public function revokeAuthCode($codeId)
    {
        return Yii::$app->oauthSystem->authorizationCodeDelete($codeId);
    }

    /**
     * @inheritDoc
     */
    public function isAuthCodeRevoked($codeId)
    {
        return empty(Yii::$app->oauthSystem->authorizationCodeFindByCode($codeId));
    }
}
