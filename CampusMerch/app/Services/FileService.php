<?php
namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileService
{
    /**
     * 上传图片并返回存储路径
     */
    public static function upload($file, $folder = 'images')
    {
        $extension = $file->getClientOriginalExtension();
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'ai', 'psd'];
        if (!in_array(strtolower($extension), $allowed)) {
            throw new \Exception('文件类型不允许');
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            throw new \Exception('文件大小不得超过15MB');
        }
        $newName = Str::random(40) . '.' . $extension;
        $path = $file->storeAs($folder, $newName, 'public');
        return $path;
    }

    public static function delete($path) {
        Storage::disk('public')->delete($path);
    }
}
