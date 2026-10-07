<?php

namespace Plugin\FriendInvite\Services;

use Illuminate\Support\Facades\File;

final class Assets
{
    public static function inspect(): array
    {
        $result = [];
        foreach (['console.html', 'console.css', 'console.js'] as $name) {
            $path = public_path('plugins/'.Settings::CODE.'/'.$name);
            $result[$name] = is_file($path) && hash_file('sha256', $path) === hash_file('sha256', dirname(__DIR__).'/resources/assets/'.$name);
        }
        return $result;
    }
    public static function publish(bool $force = false): void
    {
        $destination = public_path('plugins/'.Settings::CODE);
        $marker = storage_path('app/friend-invite/assets-pending');
        if (!$force && is_dir($destination) && !is_file($marker)) {
            return;
        }
        try {
            File::ensureDirectoryExists(dirname($marker), 0700);
            if (file_put_contents($marker, 'pending', LOCK_EX) === false) {
                throw new \RuntimeException('Cannot mark asset publication');
            }
            File::ensureDirectoryExists($destination);
            if (!File::copyDirectory(dirname(__DIR__).'/resources/assets', $destination) || in_array(false, self::inspect(), true)) {
                throw new \RuntimeException('Asset publish failed');
            }
            @unlink($marker);
        } catch (\Throwable $e) {
            Diagnostics::failure('ASSET_LIFECYCLE_FAILED', $e);
            if ($force) {
                throw new Failure('控制台资源发布失败，请检查目录权限', 500);
            }
        }
    }
    public static function remove(): void
    {
        File::deleteDirectory(public_path('plugins/'.Settings::CODE));
    }
}
