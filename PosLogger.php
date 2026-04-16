<?php
/**
 * PosLogger.php
 * Simple logger for ECR17 POS driver (PHP 5.5 compatible).
 */

class PosLogger {
    const LEVEL_INFO  = 'INFO';
    const LEVEL_WARN  = 'WARN';
    const LEVEL_ERROR = 'ERROR';

    private $logFile;
    private $maxSize;

    public function __construct($logFile = __DIR__ . '/pos.log', $maxSize = 5242880) { // 5 MB
        $this->logFile = $logFile;
        $this->maxSize = $maxSize;
    }

    private function rotateIfNeeded() {
        if (file_exists($this->logFile) && filesize($this->logFile) >= $this->maxSize) {
            $rotated = $this->logFile . '.' . time();
            @rename($this->logFile, $rotated);
        }
    }

    private function write($level, $message) {
        $this->rotateIfNeeded();
        $date = date('Y-m-d H:i:s');
        $line = sprintf("[%s] %s: %s\n", $date, $level, $message);
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function info($msg)  { $this->write(self::LEVEL_INFO, $msg); }
    public function warn($msg)  { $this->write(self::LEVEL_WARN, $msg); }
    public function error($msg) { $this->write(self::LEVEL_ERROR, $msg); }
}
?>
