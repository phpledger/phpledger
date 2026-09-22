<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/www/phpledger/includes/bootstrap.php';
$options=getopt('', ['as-of:', 'limit:', 'company:', 'book:', 'dry-run']);
try {
    if(isset($options['company'])!==isset($options['book'])) { throw new DomainException('Specify company and book together.'); }
    $result=pl_scheduler_run((string)($options['as-of']??gmdate('Y-m-d')),(int)($options['limit']??100),isset($options['company'])?(int)$options['company']:null,isset($options['book'])?(int)$options['book']:null,isset($options['dry-run']));
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
} catch(Throwable $error) { fwrite(STDERR,'Scheduler stopped: '.($error instanceof DomainException?$error->getMessage():'The batch could not complete. Check installation health.')."\n");exit(1); }
