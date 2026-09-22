<?php
declare(strict_types=1);

function emailResponse(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function emailEnv(string $path): array {
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $result[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $result;
}

function mailPart(string $contentType, string $body, string $name = ''): string {
    $headers = "Content-Type: {$contentType}; charset=UTF-8\r\n";
    if ($name !== '') {
        $headers = "Content-Type: {$contentType}; name=\"{$name}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$name}\"\r\n";
        $body = chunk_split(base64_encode($body));
    } else {
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    }
    return "{$headers}\r\n{$body}\r\n";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        emailResponse(405, ['error' => 'Разрешён только POST-запрос.']);
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $email = filter_var(trim((string) ($payload['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $resultUrl = (string) ($payload['resultUrl'] ?? '');
    if ($email === false) {
        emailResponse(400, ['error' => 'Введите корректный Email.']);
    }
    if (!preg_match('#^/results/([a-zA-Z0-9_-]+\.jpg)$#', $resultUrl, $match)) {
        emailResponse(400, ['error' => 'Ссылка на результат недействительна.']);
    }

    $root = dirname(__DIR__);
    $filePath = "{$root}/public/results/{$match[1]}";
    if (!is_file($filePath)) {
        emailResponse(404, ['error' => 'Файл результата больше недоступен.']);
    }
    $env = emailEnv("{$root}/.env");
    $from = $env['MAIL_FROM'] ?? '';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('В .env необходимо указать MAIL_FROM с адресом домена.');
    }
    $subject = $env['MAIL_SUBJECT'] ?? 'Ваша карьерная открытка';
    $boundary = '=_neon_' . bin2hex(random_bytes(12));
    $filename = 'career-poster.jpg';
    $message = "--{$boundary}\r\n"
        . mailPart('text/plain', "Здравствуйте!\r\n\r\nВо вложении ваша карьерная открытка.\r\n")
        . "--{$boundary}\r\n"
        . mailPart('image/jpeg', (string) file_get_contents($filePath), $filename)
        . "--{$boundary}--\r\n";
    $headers = [
        "From: {$from}",
        "Reply-To: {$from}",
        'MIME-Version: 1.0',
        "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
    ];
    if (!mail((string) $email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $message, implode("\r\n", $headers))) {
        throw new RuntimeException('Почтовый сервер хостинга не принял письмо.');
    }
    emailResponse(200, ['ok' => true]);
} catch (Throwable $error) {
    error_log('[Neon Founder ID Email] ' . $error->getMessage());
    emailResponse(502, ['error' => 'Не удалось отправить письмо. Попробуйте ещё раз или скачайте файл.']);
}
