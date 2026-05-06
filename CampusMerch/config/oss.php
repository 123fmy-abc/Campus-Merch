<?php

/**
 * 阿里云 OSS 配置文件
 */
return [
    // AccessKey ID
    'access_key_id' => env('ALIYUN_OSS_ACCESS_KEY_ID', ''),

    // AccessKey Secret
    'access_key_secret' => env('ALIYUN_OSS_ACCESS_KEY_SECRET', ''),

    // Bucket 名称
    'bucket' => env('ALIYUN_OSS_BUCKET', ''),

    // Endpoint（地域节点）
    'endpoint' => env('ALIYUN_OSS_ENDPOINT', ''),

    // 内网 Endpoint（可选，用于服务器在阿里云同地域时）
    'internal_endpoint' => env('ALIYUN_OSS_INTERNAL_ENDPOINT', ''),

    // 地域
    'region' => env('ALIYUN_OSS_REGION', ''),

    // 是否使用 HTTPS
    'use_ssl' => true,

    // 文件访问域名（如果有自定义域名）
    'cdn_domain' => env('ALIYUN_OSS_CDN_DOMAIN', ''),
];
