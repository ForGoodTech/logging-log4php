<?php
/**
 * Licensed to the Apache Software Foundation (ASF) under one or more
 * contributor license agreements. See the NOTICE file distributed with
 * this work for additional information regarding copyright ownership.
 * The ASF licenses this file to You under the Apache License, Version 2.0.
 */

declare(strict_types=1);

error_reporting(E_ALL);

set_error_handler(static function ($severity, $message, $file, $line) {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$projectRoot = dirname(__DIR__);
$autoload = $projectRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException("Composer autoloader not found at [$autoload].");
}

require_once $autoload;

$tempRoot = sys_get_temp_dir() . '/forgoodtech-log4php-' . bin2hex(random_bytes(8));
$configPath = $tempRoot . '/log4php_daily.xml';
$logPattern = $tempRoot . '/logs/file-%s.txt';
$message = 'forgoodtech-log4php-smoke';

$removeTree = static function ($path) use (&$removeTree) {
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
        return;
    }

    unlink($path);
};

try {
    if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
        throw new RuntimeException("Could not create temporary directory [$tempRoot].");
    }

    $escapedLogPattern = htmlspecialchars($logPattern, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $configuration = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration xmlns="http://logging.apache.org/log4php/">
    <appender name="default" class="LoggerAppenderDailyFile">
        <param name="file" value="$escapedLogPattern" />
        <param name="datePattern" value="Y-m-d" />
        <layout class="LoggerLayoutSimple" />
    </appender>
    <root>
        <appender_ref ref="default" />
    </root>
</configuration>
XML;

    if (file_put_contents($configPath, $configuration) === false) {
        throw new RuntimeException("Could not write temporary configuration [$configPath].");
    }

    $parsedConfiguration = (new LoggerConfigurationAdapterXML())->convert($configPath);
    $parsedFile = $parsedConfiguration['appenders']['default']['params']['file'] ?? null;
    if (!is_string($parsedFile) || $parsedFile !== $logPattern) {
        throw new RuntimeException('XML adapter did not normalize parameter attributes to strings.');
    }

    Logger::configure($configPath);
    Logger::getRootLogger()->info($message);
    Logger::shutdown();

    $logPath = str_replace('%s', date('Y-m-d'), $logPattern);
    if (!is_file($logPath)) {
        throw new RuntimeException("Daily-file appender did not create [$logPath].");
    }

    $contents = file_get_contents($logPath);
    if ($contents !== "INFO - $message" . PHP_EOL) {
        throw new RuntimeException("Unexpected daily-file contents: " . var_export($contents, true));
    }

    $patternTimestamp = 1700000000.5;
    $patternEvent = new LoggerLoggingEvent(
        'ForGoodTechSmokeTest',
        Logger::getRootLogger(),
        LoggerLevel::getLevelInfo(),
        'Pattern timestamp test',
        $patternTimestamp
    );
    $patternLayout = new LoggerLayoutPattern();
    $patternLayout->setConversionPattern('%d{Y-m-d H:i:s.u} %p - %m');
    $patternLayout->activateOptions();
    $expectedPattern = date('Y-m-d H:i:s', (int)$patternTimestamp)
        . '.500 INFO - Pattern timestamp test';

    if ($patternLayout->format($patternEvent) !== $expectedPattern) {
        throw new RuntimeException('Pattern layout did not preserve the event timestamp.');
    }

    $error = new Error('forgoodtech-throwable-smoke');
    $event = new LoggerLoggingEvent(
        'ForGoodTechSmokeTest',
        Logger::getRootLogger(),
        LoggerLevel::getLevelError(),
        'Throwable test',
        null,
        $error
    );
    $throwableInfo = $event->getThrowableInformation();

    if (!($throwableInfo instanceof LoggerThrowableInformation)) {
        throw new RuntimeException('PHP Error was not captured as throwable information.');
    }
    if ($throwableInfo->getThrowable() !== $error) {
        throw new RuntimeException('Captured throwable does not match the original PHP Error.');
    }
    if (strpos(implode("\n", $throwableInfo->getStringRepresentation()), $error->getMessage()) === false) {
        throw new RuntimeException('Rendered throwable does not contain the PHP Error message.');
    }

    fwrite(STDOUT, "log4php smoke tests passed on PHP " . PHP_VERSION . PHP_EOL);
} finally {
    Logger::resetConfiguration();
    $removeTree($tempRoot);
    restore_error_handler();
}
