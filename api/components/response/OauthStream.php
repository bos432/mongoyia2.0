<?php

namespace api\components\response;
use Yii;
use yii\helpers\Json;
use RuntimeException;

/**
 * Class OAuthStream
 * @package api\components\response
 * @author funson86 <funson86@gmail.com>
 */
class OauthStream implements \Psr\Http\Message\StreamInterface
{
    public const VERSION = 'MONGOYIA_OAUTH_RESPONSE_ADAPTER_V1';

    private $contents = '';
    private $position = 0;
    private $closed = false;

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return $this->contents;
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
        $this->contents = '';
        $this->position = 0;
        $this->closed = true;
    }

    /**
     * @inheritDoc
     */
    public function detach()
    {
        $this->close();
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSize()
    {
        return strlen($this->contents);
    }

    /**
     * @inheritDoc
     */
    public function tell()
    {
        $this->assertOpen();
        return $this->position;
    }

    /**
     * @inheritDoc
     */
    public function eof()
    {
        return $this->position >= $this->getSize();
    }

    /**
     * @inheritDoc
     */
    public function isSeekable()
    {
        return !$this->closed;
    }

    /**
     * @inheritDoc
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        $this->assertOpen();

        if ($whence === SEEK_SET) {
            $position = $offset;
        } elseif ($whence === SEEK_CUR) {
            $position = $this->position + $offset;
        } elseif ($whence === SEEK_END) {
            $position = $this->getSize() + $offset;
        } else {
            throw new RuntimeException('Invalid seek mode.');
        }

        if ($position < 0) {
            throw new RuntimeException('Cannot seek before the beginning of the stream.');
        }

        $this->position = $position;
    }

    /**
     * @inheritDoc
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * @inheritDoc
     */
    public function isWritable()
    {
        return !$this->closed;
    }

    /**
     * @inheritDoc
     */
    public function write($string)
    {
        $this->assertOpen();
        $string = (string)$string;
        $prefix = substr($this->contents, 0, $this->position);
        $suffixPosition = $this->position + strlen($string);
        $suffix = $suffixPosition < $this->getSize() ? substr($this->contents, $suffixPosition) : '';
        $this->contents = $prefix . $string . $suffix;
        $this->position += strlen($string);

        try {
            Yii::$app->response->data = Json::decode($this->contents, true);
        } catch (\Throwable $exception) {
            Yii::$app->response->data = $this->contents;
        }

        return strlen($string);
    }

    /**
     * @inheritDoc
     */
    public function isReadable()
    {
        return !$this->closed;
    }

    /**
     * @inheritDoc
     */
    public function read($length)
    {
        $this->assertOpen();
        $length = max(0, (int)$length);
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @inheritDoc
     */
    public function getContents()
    {
        $this->assertOpen();
        $contents = substr($this->contents, $this->position);
        $this->position = $this->getSize();

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($key = null)
    {
        $metadata = [
            'seekable' => $this->isSeekable(),
            'readable' => $this->isReadable(),
            'writable' => $this->isWritable(),
            'uri' => 'php://memory',
        ];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Stream is closed.');
        }
    }
}
