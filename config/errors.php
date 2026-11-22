<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$configuredErrorLog = getenv('APP_ERROR_LOG');
if (is_string($configuredErrorLog) && $configuredErrorLog !== '') {
    ini_set('error_log', $configuredErrorLog);
}

set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        "Unhandled %s: %s in %s:%d\n%s",
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'An unexpected error occurred. Please try again later.';
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array(
        $error['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR],
        true
    )) {
        return;
    }

    error_log(sprintf(
        'Fatal PHP error: %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'An unexpected error occurred. Please try again later.';
    }
});
