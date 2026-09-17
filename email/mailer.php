<?php
declare(strict_types=1);

/**
 * BrainPal SMTP mail helper.
 * Requires: composer require phpmailer/phpmailer
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
  throw new RuntimeException('PHPMailer is not installed. Run: composer install');
}
require_once $autoload;

function sendBrainPalEmail(string $to, string $name, string $subject, string $html): bool {
  $config = require __DIR__ . '/config.php';
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = $config['host'];
  $mail->SMTPAuth = true;
  $mail->Username = $config['username'];
  $mail->Password = $config['password'];
  $mail->Port = (int)$config['port'];
  $mail->SMTPSecure = ($config['encryption'] ?? 'tls') === 'ssl'
    ? PHPMailer::ENCRYPTION_SMTPS
    : PHPMailer::ENCRYPTION_STARTTLS;
  $mail->CharSet = 'UTF-8';
  $mail->setFrom($config['from_email'], $config['from_name']);
  $mail->addAddress($to, $name);
  $mail->isHTML(true);
  $mail->Subject = $subject;
  $mail->Body = $html;
  $mail->AltBody = strip_tags(str_replace(['<br>','</p>','</div>'], ["\n","\n","\n"], $html));
  return $mail->send();
}
