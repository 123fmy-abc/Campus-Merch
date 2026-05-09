<?php

namespace App\Services;

use OSS\OssClient;
use OSS\Core\OssException;

/**
 * 阿里云 OSS 服务类
 *
 * 用于上传文件到阿里云 OSS
 */
class OssService
{
    /**
     * @var OssClient
     */
    protected $ossClient;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $accessKeyId = config('oss.access_key_id');
        $accessKeySecret = config('oss.access_key_secret');
        $this->endpoint = config('oss.endpoint');
        $this->bucket = config('oss.bucket');

        try {
            $this->ossClient = new OssClient(
                $accessKeyId,
                $accessKeySecret,
                $this->endpoint
            );
        } catch (OssException $e) {
            throw new \Exception('OSS 客户端初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传文件到 OSS
     *
     * @param string $object 对象名称（文件路径）
     * @param string $filePath 本地文件路径
     * @return array 上传结果
     */
    public function uploadFile(string $object, string $filePath): array
    {
        try {
            $result = $this->ossClient->uploadFile($this->bucket, $object, $filePath);

            return [
                'success' => true,
                'url' => $result['info']['url'] ?? $this->getUrl($object),
                'object' => $object,
            ];
        } catch (OssException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 上传文件内容到 OSS
     *
     * @param string $object 对象名称
     * @param string $content 文件内容
     * @return array 上传结果
     */
    public function uploadContent(string $object, string $content): array
    {
        try {
            $result = $this->ossClient->putObject($this->bucket, $object, $content);

            return [
                'success' => true,
                'url' => $result['info']['url'] ?? $this->getUrl($object),
                'object' => $object,
            ];
        } catch (OssException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 删除文件
     *
     * @param string $object 对象名称
     * @return bool
     */
    public function deleteFile(string $object): bool
    {
        try {
            $this->ossClient->deleteObject($this->bucket, $object);
            return true;
        } catch (OssException $e) {
            return false;
        }
    }

    /**
     * 获取文件访问 URL
     *
     * @param string $object 对象名称
     * @return string
     */
    public function getUrl(string $object): string
    {
        return "https://{$this->bucket}.{$this->endpoint}/{$object}";
    }

    /**
     * 检查文件是否存在
     *
     * @param string $object 对象名称
     * @return bool
     */
    public function exists(string $object): bool
    {
        try {
            return $this->ossClient->doesObjectExist($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
    }
}
