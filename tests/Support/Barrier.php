<?php
declare(strict_types=1);

namespace tests\Support;

/**
 * 并发测试屏障（基于 flock 文件锁）
 *
 * 用法：
 *   Parent: $barrier = new Barrier($file); $barrier->hold();
 *   Worker: $barrier->wait(); // 阻塞直到 parent 释放
 *   Parent: $barrier->release(); // 释放后所有 worker 同时开始
 *
 * 原理：
 *   Parent 持有 LOCK_EX，Worker 调用 wait() 时尝试获取 LOCK_EX 会阻塞。
 *   Parent release() 后，Worker 依次获取锁并立即释放，实现"同时开始"的信号。
 */
class Barrier
{
    private string $file;
    /** @var resource|null */
    private $fp = null;

    public function __construct(string $file)
    {
        $this->file = $file;
        if (!file_exists($file)) {
            touch($file);
        }
    }

    /**
     * Parent 调用：持有屏障锁（阻塞所有 wait() 的 worker）
     */
    public function hold(): void
    {
        $this->fp = fopen($this->file, 'r+');
        if ($this->fp === false) {
            throw new \RuntimeException("Cannot open barrier file: {$this->file}");
        }
        flock($this->fp, LOCK_EX);
    }

    /**
     * Worker 调用：等待屏障释放（阻塞直到 parent 调用 release()）
     */
    public function wait(): void
    {
        $fp = fopen($this->file, 'r+');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open barrier file: {$this->file}");
        }
        // 阻塞等待 parent 释放 LOCK_EX
        flock($fp, LOCK_EX);
        // 获取到锁说明屏障已释放，立即释放让其他 worker 通过
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Parent 调用：释放屏障，所有 worker 同时开始
     */
    public function release(): void
    {
        if ($this->fp !== null) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
    }

    /**
     * 清理屏障文件
     */
    public function cleanup(): void
    {
        $this->release();
        if (file_exists($this->file)) {
            @unlink($this->file);
        }
    }

    public function getFile(): string
    {
        return $this->file;
    }
}
