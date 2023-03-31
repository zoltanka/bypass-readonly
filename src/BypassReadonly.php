<?php declare(strict_types=1);

namespace Zfekete\BypassReadonly;

use BadMethodCallException;
use function flock;
use function fnmatch;
use function fopen;
use function fwrite;
use function get_class;
use function is_array;
use function method_exists;
use function pathinfo;
use function sha1;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function stripos;
use function tmpfile;
use function token_get_all;
use const LOCK_EX;
use const LOCK_SH;
use const PATHINFO_EXTENSION;
use const T_READONLY;
use const TOKEN_PARSE;

class BypassReadonly
{
    protected const PROTOCOL = 'file';

    protected const KEYWORDS = [
        'readonly', 'final'
    ];

    /**
     * @var resource|null
     */
    public $context;

    protected ?object $wrapper = null;

    /**
     * @var list<string>
     */
    protected static array $pathWhitelist = ['*'];

    protected static ?string $underlyingWrapperClass = null;

    protected static ?string $cacheDir = null;

    public static function enable(): void
    {
        $wrapper = stream_get_meta_data(fopen(__FILE__, 'r'))['wrapper_data'] ?? null;
        if ($wrapper instanceof self) {
            return;
        }

        self::$underlyingWrapperClass = $wrapper
            ? get_class($wrapper)
            : NativeWrapper::class;

        NativeWrapper::$outerWrapper = self::class;

        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function setWhitelist(array $whitelist): void
    {
        foreach ($whitelist as &$mask) {
            $mask = strtr($mask, '\\', '/');
        }

        self::$pathWhitelist = $whitelist;
    }

    public static function setCacheDirectory(?string $dir): void
    {
        self::$cacheDir = $dir;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->wrapper = $this->createUnderlyingWrapper();
        if (!$this->wrapper->stream_open($path, $mode, $options, $openedPath)) {
            return false;
        }

        if ($mode === 'rb' && pathinfo($path, PATHINFO_EXTENSION) === 'php' && self::isPathInWhiteList($path)) {
            $content = '';
            while (!$this->wrapper->stream_eof()) {
                $content .= $this->wrapper->stream_read(8192);
            }

            $modified = self::cachedRemoveReadonly($content);

            if ($modified === $content) {
                $this->wrapper->stream_seek(0);
            } else {
                $this->wrapper->stream_close();
                $this->wrapper         = new NativeWrapper();
                $this->wrapper->handle = tmpfile();
                $this->wrapper->stream_write($modified);
                $this->wrapper->stream_seek(0);
            }
        }

        return true;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $this->wrapper = $this->createUnderlyingWrapper();

        return $this->wrapper->dir_opendir($path, $options);
    }

    public static function cachedRemoveReadonly(string $code): string
    {
        $found = false;
        foreach (self::KEYWORDS as $keyword) {
            if (stripos($code, $keyword)) {
                $found = true;
                break;
            }
        }

        if ($found === false) {
            return $code;
        }

        if (self::$cacheDir) {
            $wrapper = new NativeWrapper();
            $hash    = sha1($code);
            if (@$wrapper->stream_open(self::$cacheDir . '/' . $hash, 'r')) { // @ may not exist
                flock($wrapper->handle, LOCK_SH);
                if ($res = stream_get_contents($wrapper->handle)) {
                    return $res;
                }
            }
        }

        $code = self::removeReadonly($code);

        if (self::$cacheDir && @$wrapper->stream_open(self::$cacheDir . '/' . $hash, 'x')) { // @ may exist
            flock($wrapper->handle, LOCK_EX);
            fwrite($wrapper->handle, $code);
        }

        return $code;
    }

    public static function removeReadonly(string $code): string
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $code;
        }

        $code = '';
        foreach ($tokens as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_FINAL, T_READONLY], true) ? '' : $token[1])
                : $token;
        }

        return $code;
    }

    private static function isPathInWhiteList(string $path): bool
    {
        $path = strtr($path, '\\', '/');
        foreach (self::$pathWhitelist as $mask) {
            if (fnmatch($mask, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return object */
    private function createUnderlyingWrapper(): object
    {
        if (self::$underlyingWrapperClass === null) {
            throw new BadMethodCallException('Did you forget to call enable()?');
        }

        $wrapper          = new self::$underlyingWrapperClass();
        $wrapper->context = $this->context;

        return $wrapper;
    }

    public function __call(string $method, array $args): mixed
    {
        $wrapper = $this->wrapper ?? $this->createUnderlyingWrapper();

        return method_exists($wrapper, $method)
            ? $wrapper->$method(...$args)
            : false;
    }
}
