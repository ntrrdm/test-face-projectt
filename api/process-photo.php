<?php
declare(strict_types=1);

/*
 * Apache/PHP implementation of the photo-processing endpoint.
 * It is intentionally independent from Node.js: the browser calls
 * POST /api/process-photo, Apache rewrites it here via the root .htaccess.
 */

const MAX_UPLOAD_BYTES = 12582912; // 12 MiB
const POLL_INTERVAL_SECONDS = 2;
const MAX_POLL_ATTEMPTS = 60; // about two minutes
const RESULT_WIDTH = 720;
const RESULT_HEIGHT = 960;

function respond(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readEnv(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('Файл .env не найден.');
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $values;
}

function apiRequest(string $url, string $apiKey, ?array $payload = null, bool $json = true): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На хостинге не включено расширение PHP cURL.');
    }

    $curl = curl_init($url);
    $headers = [];
    if ($apiKey !== '') {
        $headers[] = "Authorization: Bearer {$apiKey}";
    }
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException("Ошибка соединения с Kie.ai: {$error}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Kie.ai вернул HTTP {$status}: " . mb_substr((string) $body, 0, 500));
    }

    if (!$json) {
        return ['raw' => $body];
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Kie.ai вернул некорректный JSON.');
    }
    return $data;
}

function uploadToKie(string $bytes, string $extension, array $env): string {
    $mime = $extension === 'jpg' ? 'image/jpeg' : 'image/png';
    $payload = [
        'base64Data' => "data:{$mime};base64," . base64_encode($bytes),
        'uploadPath' => 'neon-founder-id',
        'fileName' => bin2hex(random_bytes(16)) . ".{$extension}",
    ];
    $baseUrl = rtrim($env['KIE_UPLOAD_BASE_URL'] ?? 'https://kieai.redpandaai.co', '/');
    $response = apiRequest("{$baseUrl}/api/file-base64-upload", $env['KIE_API_KEY'], $payload);
    $url = $response['data']['fileUrl'] ?? $response['data']['downloadUrl'] ?? null;
    if (!is_string($url) || $url === '') {
        throw new RuntimeException('Kie.ai не вернул ссылку на загруженный файл.');
    }
    return $url;
}

function resultUrlFromRecord(array $record): ?string {
    $data = $record['data'] ?? [];
    $result = $data['result'] ?? $data;
    if (isset($data['resultJson'])) {
        $result = is_string($data['resultJson']) ? json_decode($data['resultJson'], true) : $data['resultJson'];
    }
    if (!is_array($result)) {
        return null;
    }
    foreach (['resultUrls', 'imageUrls', 'images', 'output'] as $key) {
        if (isset($result[$key][0]) && is_string($result[$key][0])) {
            return $result[$key][0];
        }
    }
    return null;
}

function resizeResult(string $image): array {
    if (!function_exists('imagecreatefromstring')) {
        return [$image, 'png'];
    }
    $source = @imagecreatefromstring($image);
    if ($source === false) {
        return [$image, 'png'];
    }
    $canvas = imagecreatetruecolor(RESULT_WIDTH, RESULT_HEIGHT);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled(
        $canvas,
        $source,
        0,
        0,
        0,
        0,
        RESULT_WIDTH,
        RESULT_HEIGHT,
        imagesx($source),
        imagesy($source)
    );
    ob_start();
    imagejpeg($canvas, null, 85);
    $jpeg = (string) ob_get_clean();
    imagedestroy($source);
    imagedestroy($canvas);
    return [$jpeg, 'jpg'];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'Разрешён только POST-запрос.']);
    }
    if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        respond(400, ['error' => 'Фотография не получена.']);
    }
    if ((int) $_FILES['photo']['size'] > MAX_UPLOAD_BYTES) {
        respond(413, ['error' => 'Размер фотографии не должен превышать 12 МБ.']);
    }

    $root = dirname(__DIR__);
    $env = readEnv("{$root}/.env");
    if (empty($env['KIE_API_KEY'])) {
        throw new RuntimeException('В .env не задан KIE_API_KEY.');
    }

    $profile = (string) ($_POST['profile'] ?? 'hybrid');
    $audience = (string) ($_POST['audience'] ?? 'students');
    $templates = [
        'students' => [
            'architect' => ['file' => 'architect.png', 'head' => ['x' => 1790, 'y' => 1250, 'width' => 980, 'height' => 1450]],
            'innovator' => ['file' => 'innovator.png', 'head' => ['x' => 1710, 'y' => 1030, 'width' => 1070, 'height' => 1510]],
            'owner' => ['file' => 'owner.png', 'head' => ['x' => 1795, 'y' => 1240, 'width' => 960, 'height' => 1420]],
            'hybrid' => ['file' => 'hybrid.jpg', 'head' => ['x' => 1790, 'y' => 1240, 'width' => 1000, 'height' => 1470]],
        ],
        'pros' => [
            'architect' => ['file' => 'pro-architect.png', 'head' => ['x' => 1710, 'y' => 760, 'width' => 1010, 'height' => 1630]],
            'innovator' => ['file' => 'pro-innovator.png', 'head' => ['x' => 1700, 'y' => 900, 'width' => 1040, 'height' => 1580]],
            'owner' => ['file' => 'pro-owner.png', 'head' => ['x' => 1740, 'y' => 980, 'width' => 970, 'height' => 1510]],
            'hybrid' => ['file' => 'pro-hybrid.png', 'head' => ['x' => 1740, 'y' => 750, 'width' => 1000, 'height' => 1640]],
        ],
    ];
    $audience = $audience === 'pros' ? 'pros' : 'students';
    $config = $templates[$audience][$profile] ?? $templates[$audience]['hybrid'];
    $templatePath = "{$root}/public/templates/{$config['file']}";
    if (!is_file($templatePath)) {
        throw new RuntimeException("Не найден шаблон {$config['file']}.");
    }

    $portrait = file_get_contents($_FILES['photo']['tmp_name']);
    $template = file_get_contents($templatePath);
    if ($portrait === false || $template === false) {
        throw new RuntimeException('Не удалось прочитать исходное изображение.');
    }

    $head = $config['head'];
    $prompt = implode(' ', [
        'Create a single photorealistic, print-ready career poster using Image 1 as the locked composition and Image 2 only as the identity reference for the participant.',
        "At x={$head['x']}, y={$head['y']}, width={$head['width']}, height={$head['height']}, replace the empty black head area with a complete, naturally integrated human head and neck of the person in Image 2.",
        'The person must look like they were photographed in this scene: correct head size and three-quarter pose, natural hair silhouette, ears, jawline, neck, hoodie or collar occlusion, realistic skin texture, matching light, shadows, perspective, colour grade and depth of field.',
        'No cutout, no oval crop, no pasted portrait, no hard edge, no visible mask, no floating head, no duplicated face.',
        'Preserve the poster canvas, framing, body pose, hands, background, logo, QR code, Russian typography and existing text exactly. Do not add or alter text, logos, watermarks, objects or people. Return only the finished 3:4 poster.',
    ]);

    $templateUrl = uploadToKie($template, 'png', $env);
    $portraitUrl = uploadToKie($portrait, 'jpg', $env);
    $apiBase = rtrim($env['KIE_BASE_URL'] ?? 'https://api.kie.ai', '/');
    $model = $env['KIE_IMAGE_MODEL'] ?? 'nano-banana-pro';
    $created = apiRequest("{$apiBase}/api/v1/jobs/createTask", $env['KIE_API_KEY'], [
        'model' => $model,
        'input' => [
            'prompt' => $prompt,
            'image_input' => [$templateUrl, $portraitUrl],
            'aspect_ratio' => '3:4',
            'resolution' => '1K',
            'output_format' => 'png',
        ],
    ]);
    $taskId = $created['data']['taskId'] ?? $created['taskId'] ?? null;
    if (!is_string($taskId) || $taskId === '') {
        throw new RuntimeException('Kie.ai не вернул идентификатор задачи.');
    }

    $finalUrl = null;
    for ($attempt = 0; $attempt < MAX_POLL_ATTEMPTS; $attempt++) {
        sleep(POLL_INTERVAL_SECONDS);
        $record = apiRequest("{$apiBase}/api/v1/jobs/recordInfo?taskId=" . rawurlencode($taskId), $env['KIE_API_KEY']);
        $finalUrl = resultUrlFromRecord($record);
        if ($finalUrl !== null) {
            break;
        }
        $data = $record['data'] ?? [];
        $state = strtolower((string) ($data['state'] ?? $data['status'] ?? ''));
        if (in_array($state, ['fail', 'failed', 'error'], true)) {
            throw new RuntimeException('Kie.ai не смог обработать фото: ' . ($data['failMsg'] ?? $data['errorMessage'] ?? 'неизвестная ошибка'));
        }
    }
    if ($finalUrl === null) {
        throw new RuntimeException('Время ожидания Kie.ai превысило 2 минуты.');
    }

    $generated = apiRequest($finalUrl, '', null, false)['raw'];
    [$result, $extension] = resizeResult($generated);
    $resultDir = "{$root}/public/results";
    if (!is_dir($resultDir) && !mkdir($resultDir, 0775, true) && !is_dir($resultDir)) {
        throw new RuntimeException('Не удалось создать папку результатов.');
    }
    $filename = time() . '-' . bin2hex(random_bytes(3)) . ".{$extension}";
    if (file_put_contents("{$resultDir}/{$filename}", $result) === false) {
        throw new RuntimeException('Не удалось сохранить готовое изображение.');
    }
    respond(200, ['resultUrl' => "/results/{$filename}", 'aiUsed' => true, 'aiProvider' => 'kie']);
} catch (Throwable $error) {
    error_log('[Neon Founder ID] ' . $error->getMessage());
    respond(502, ['error' => 'Нейросеть не смогла обработать фото. Попробуйте ещё раз.']);
}
