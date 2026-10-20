<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/** Sends configured SMTP mail and renders subscription notification bodies. */
class Mailer
{
    private PHPMailer $mailer;
    private bool $enabled = false;
    private string $fromEmail = '';
    private string $fromName = 'ERP System';
    private MonologHandler $logger;

    /** Configure PHPMailer from SMTP_* environment values; sending is disabled when required values are absent. */
    public function __construct()
    {
        $this->logger = MonologHandler::getInstance('mailer');

        $host = $_ENV['SMTP_HOST'] ?? '';
        $port = (int)($_ENV['SMTP_PORT'] ?? 0);
        $user = $_ENV['SMTP_USER'] ?? '';
        $pass = $_ENV['SMTP_PASS'] ?? '';
        $secure = $_ENV['SMTP_SECURE'] ?? '';
        $this->fromEmail = $_ENV['SMTP_FROM'] ?? '';
        $this->fromName = $_ENV['SMTP_FROM_NAME'] ?? 'SmartSys';

        $this->enabled = !empty($host) && !empty($port) && !empty($this->fromEmail);

        $mail = new PHPMailer(true);
        if ($this->enabled) {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            if ($user !== '' && $pass !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            }
            if (in_array(strtolower($secure), ['tls','ssl'], true)) {
                $mail->SMTPSecure = strtolower($secure);
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->fromEmail, $this->fromName);
        }
        $this->mailer = $mail;
    }

    /** Return whether SMTP configuration is sufficient to attempt delivery. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Send one HTML message to the non-empty recipients; return false when disabled or delivery fails. */
    public function send(array $toEmails, string $subject, string $htmlBody): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            // Clone per send due to PHPMailer statefulness
            $m = clone $this->mailer;
            foreach ($toEmails as $to) {
                if (!$to) {
                    continue;
                }
                $m->addAddress($to);
            }
            if (empty($m->getToAddresses())) {
                return false;
            }
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = $htmlBody;
            return $m->send();
        } catch (\Throwable $e) {
            $this->logger->error('Mailer send error', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /** Render the reminder body, escaping all interpolated values for HTML. */
    public static function renderReminderTemplate(string $planName, string $endDate, string $upgradeUrl): string
    {
        $end = htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8');
        $plan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>تذكير قرب انتهاء الاشتراك</title></head>
<body style="font-family:Tahoma, Arial; background:#f7fafc; padding:20px;">
  <table align="center" width="100%" style="max-width:600px; background:#ffffff; border-radius:12px; padding:20px; border:1px solid #eee;">
    <tr><td>
      <h2 style="color:#111827; margin-top:0;">تذكير: اقترب انتهاء اشتراكك</h2>
      <p style="color:#374151; line-height:1.8;">خطة الاشتراك الحالية: <b>{$plan}</b><br>تاريخ الانتهاء: <b>{$end}</b></p>
      <p style="color:#374151; line-height:1.8;">يرجى ترقية الاشتراك للاستمرار في استخدام النظام دون انقطاع.</p>
      <p>
        <a href="{$url}" style="display:inline-block; background:#2563eb; color:#fff; text-decoration:none; padding:12px 20px; border-radius:10px;">الترقية الآن</a>
      </p>
      <p style="color:#6b7280; font-size:12px;">إذا كنت قد جدّدت الاشتراك بالفعل، يمكنك تجاهل هذه الرسالة.</p>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /** Render the expiration body, escaping all interpolated values for HTML. */
    public static function renderExpiredTemplate(string $planName, string $endDate, string $upgradeUrl): string
    {
        $end = htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8');
        $plan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>انتهاء الاشتراك</title></head>
<body style="font-family:Tahoma, Arial; background:#f7fafc; padding:20px;">
  <table align="center" width="100%" style="max-width:600px; background:#ffffff; border-radius:12px; padding:20px; border:1px solid #eee;">
    <tr><td>
      <h2 style="color:#111827; margin-top:0;">تم انتهاء الاشتراك</h2>
      <p style="color:#374151; line-height:1.8;">خطة الاشتراك السابقة: <b>{$plan}</b><br>تاريخ الانتهاء: <b>{$end}</b></p>
      <p style="color:#374151; line-height:1.8;">تم إيقاف الوصول إلى النظام. لإعادة التفعيل يرجى ترقية الاشتراك.</p>
      <p>
        <a href="{$url}" style="display:inline-block; background:#2563eb; color:#fff; text-decoration:none; padding:12px 20px; border-radius:10px;">الترقية الآن</a>
      </p>
      <p style="color:#6b7280; font-size:12px;">في حال كانت لديكم أي استفسارات، لا تترددوا بالتواصل مع الدعم.</p>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
