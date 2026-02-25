<?php

declare(strict_types=1);

namespace Tests;

/**
 * Minimal stream wrapper that strips `final` keyword from class declarations,
 * allowing Mockery to create mocks of final classes.
 *
 * Equivalent to dg/bypass-finals but inlined to avoid composer dependency issues.
 */
final class BypassFinals
{
    private const PROTOCOL = 'file';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    private static bool $enabled = false;

    /** @var list<string> */
    private static array $pathWhitelist = [];

    public static function enable(): void
    {
        if (self::$enabled) {
            return;
        }

        self::$enabled = true;
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function setWhitelist(array $paths): void
    {
        self::$pathWhitelist = $paths;
    }

    /** @return resource|false */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath)
    {
        $usePath = (bool) ($options & STREAM_USE_PATH);

        if ($mode === 'rb' && self::shouldIntercept($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $content = $this->nativeFileGetContents($path);
            if ($content !== false) {
                // Strip 'final' keyword and 'readonly' from class declarations only
                $modified = preg_replace('/\bfinal\s+readonly\s+class\b/', 'class', $content);
                $modified = preg_replace('/\bfinal\s+class\b/', 'class', $modified);
                $modified = preg_replace('/\breadonly\s+class\b/', 'class', $modified);
                $this->handle = fopen('php://memory', 'r+b');
                if ($this->handle !== false) {
                    fwrite($this->handle, $modified ?? $content);
                    rewind($this->handle);

                    return true;
                }
            }
        }

        // Fall through to native
        $this->handle = $this->nativeOpen($path, $mode, $usePath);

        return $this->handle !== false;
    }

    private static function shouldIntercept(string $path): bool
    {
        if (empty(self::$pathWhitelist)) {
            // Intercept app/ and SDK vendor files
            $normalized = str_replace('\\', '/', $path);

            return str_contains($normalized, '/app/')
                || str_contains($normalized, '/ospp-sdk-php/');
        }

        foreach (self::$pathWhitelist as $whitePath) {
            if (str_contains($path, $whitePath)) {
                return true;
            }
        }

        return false;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }

    /** @return array<string, mixed>|false */
    public function stream_stat(): array|false
    {
        return $this->handle ? fstat($this->handle) : false;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_lock(int $operation): bool
    {
        if ($operation === 0 || !$this->handle) {
            return true;
        }

        return flock($this->handle, $operation);
    }

    public function stream_truncate(int $newSize): bool
    {
        return $this->handle ? ftruncate($this->handle, $newSize) : false;
    }

    public function stream_flush(): bool
    {
        return $this->handle ? fflush($this->handle) : false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        self::restore();
        try {
            return match ($option) {
                STREAM_META_TOUCH => touch($path, ...(array) $value),
                STREAM_META_OWNER_NAME, STREAM_META_OWNER => chown($path, $value),
                STREAM_META_GROUP_NAME, STREAM_META_GROUP => chgrp($path, $value),
                STREAM_META_ACCESS => chmod($path, $value),
                default => false,
            };
        } finally {
            self::intercept();
        }
    }

    public function unlink(string $path): bool
    {
        self::restore();
        try {
            return unlink($path);
        } finally {
            self::intercept();
        }
    }

    public function rename(string $from, string $to): bool
    {
        self::restore();
        try {
            return rename($from, $to);
        } finally {
            self::intercept();
        }
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::restore();
        try {
            return mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE));
        } finally {
            self::intercept();
        }
    }

    public function rmdir(string $path, int $options): bool
    {
        self::restore();
        try {
            return rmdir($path);
        } finally {
            self::intercept();
        }
    }

    /** @return resource|false */
    public function dir_opendir(string $path, int $options)
    {
        self::restore();
        try {
            $handle = opendir($path);
            if ($handle !== false) {
                $this->handle = $handle;
                return true;
            }
            return false;
        } finally {
            self::intercept();
        }
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->handle);
        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->handle);
        return true;
    }

    /** @return array<string, mixed>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        self::restore();
        try {
            if ($flags & STREAM_URL_STAT_LINK) {
                $result = @lstat($path);
            } else {
                $result = @stat($path);
            }
            return $result ?: false;
        } finally {
            self::intercept();
        }
    }

    private function nativeFileGetContents(string $path): string|false
    {
        self::restore();
        try {
            return file_get_contents($path);
        } finally {
            self::intercept();
        }
    }

    /** @return resource|false */
    private function nativeOpen(string $path, string $mode, bool $usePath)
    {
        self::restore();
        try {
            return fopen($path, $mode, $usePath);
        } finally {
            self::intercept();
        }
    }

    private static function restore(): void
    {
        stream_wrapper_restore(self::PROTOCOL);
    }

    private static function intercept(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }
}
