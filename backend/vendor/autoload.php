<?php

// 临时自动加载器 - 用于解决 vendor 目录缺失问题
// 这个文件提供基本的 PSR-4 自动加载功能

spl_autoload_register(function ($class) {
    // 项目特定的命名空间前缀
    $prefix = 'App\\';

    // 基础目录
    $base_dir = __DIR__ . '/../src/';

    // 检查类是否使用了命名空间前缀
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // 不是我们的命名空间，让其他自动加载器处理
        return;
    }

    // 获取相对类名
    $relative_class = substr($class, $len);

    // 将命名空间前缀替换为基础目录，将命名空间分隔符替换为目录分隔符
    // 并添加 .php 后缀
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // 如果文件存在，加载它
    if (file_exists($file)) {
        require $file;
    }
});

// 如果有 composer 的真实自动加载器，尝试加载它
$composerAutoload = __DIR__ . '/composer/autoload_real.php';
if (file_exists($composerAutoload)) {
    return require $composerAutoload;
}

// 返回一个虚拟的 ClassLoader 实例（如果需要）
return new class {
    public function add($prefix, $paths) {}
    public function addPsr4($prefix, $paths) {}
    public function register($prepend = false) {}
    public function unregister() {}
    public function loadClass($class) {}
    public function findFile($class) { return false; }
};
