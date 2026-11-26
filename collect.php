<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

function requiredEnvironmentVariable(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException('La variable ' . $name . ' doit etre configuree.');
    }

    return $value;
}

function fetchInverterResponse(string $url): array
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new RuntimeException('INVERTER_JSON_URL doit utiliser HTTP ou HTTPS.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    set_error_handler(static function (int $severity, string $message): void {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $body = file_get_contents($url, false, $context);
    } catch (ErrorException $exception) {
        throw new RuntimeException('Echec de la requete vers l ondulateur: ' . $exception->getMessage(), 0, $exception);
    } finally {
        restore_error_handler();
    }

    if ($body === false) {
        throw new RuntimeException('Impossible de lire la reponse de l ondulateur.');
    }

    $statusCode = null;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }
    }

    if ($statusCode === null || $statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Le serveur de l ondulateur a repondu avec le code HTTP ' . ($statusCode ?? 'inconnu') . '.');
    }

    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('La reponse de l ondulateur n est pas un JSON valide.', 0, $exception);
    }

    if (!is_array($payload)) {
        throw new RuntimeException('La reponse JSON de l ondulateur doit etre un objet.');
    }

    if (isset($payload['success']) && $payload['success'] !== true) {
        throw new RuntimeException('L ondulateur a signale un echec.');
    }

    if (!isset($payload['dataList']) || !is_array($payload['dataList'])) {
        throw new RuntimeException('La reponse JSON ne contient pas de liste dataList valide.');
    }
    if ($payload['dataList'] === []) {
        throw new RuntimeException('La reponse JSON ne contient aucune mesure.');
    }

    return ['body' => $body, 'payload' => $payload];
}

function optionalScalar(array $source, string $key): ?string
{
    if (!array_key_exists($key, $source) || $source[$key] === null) {
        return null;
    }

    if (!is_scalar($source[$key])) {
        throw new RuntimeException('Le champ ' . $key . ' doit etre une valeur simple.');
    }

    return (string) $source[$key];
}

try {
    $url = requiredEnvironmentVariable('INVERTER_JSON_URL');
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('INVERTER_JSON_URL doit etre une URL HTTP ou HTTPS valide.');
    }

    $response = fetchInverterResponse($url);
    $payload = $response['payload'];
    $deviceSn = optionalScalar($payload, 'deviceSn');

    if ($deviceSn === null || $deviceSn === '') {
        throw new RuntimeException('La reponse JSON ne contient pas de numero de serie valide.');
    }

    $measurements = [];
    foreach ($payload['dataList'] as $item) {
        if (!is_array($item) || !isset($item['key']) || !is_string($item['key']) || $item['key'] === '') {
            throw new RuntimeException('Une mesure JSON ne contient pas de cle valide.');
        }
        if (strlen($item['key']) > 100) {
            throw new RuntimeException('Une cle de mesure depasse la longueur maximale de 100 caracteres.');
        }

        $measurements[] = [
            'key' => $item['key'],
            'value' => optionalScalar($item, 'value'),
            'unit' => optionalScalar($item, 'unit'),
            'name' => optionalScalar($item, 'name'),
        ];
    }

    $deviceId = optionalScalar($payload, 'deviceId');
    if ($deviceId !== null && filter_var($deviceId, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Le champ deviceId doit etre un entier.');
    }

    $collectionTime = optionalScalar($payload, 'collectionTime');
    if ($collectionTime !== null && filter_var($collectionTime, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Le champ collectionTime doit etre un entier.');
    }

    $pdo = createDatabaseConnection();
    $pdo->beginTransaction();

    try {
        $readingQuery = $pdo->prepare(
            'INSERT INTO inverter_readings
                (device_sn, device_id, device_type, device_state, request_id, collection_time, collected_at, raw_json)
             VALUES
                (:device_sn, :device_id, :device_type, :device_state, :request_id, :collection_time, :collected_at, :raw_json)'
        );
        $readingQuery->execute([
            ':device_sn' => $deviceSn,
            ':device_id' => $deviceId,
            ':device_type' => optionalScalar($payload, 'deviceType'),
            ':device_state' => optionalScalar($payload, 'deviceState'),
            ':request_id' => optionalScalar($payload, 'requestId'),
            ':collection_time' => $collectionTime,
            ':collected_at' => gmdate('Y-m-d H:i:s'),
            ':raw_json' => $response['body'],
        ]);
        $readingId = (int) $pdo->lastInsertId();

        $measurementQuery = $pdo->prepare(
            'INSERT INTO inverter_measurements
                (reading_id, measurement_key, measurement_value, unit, measurement_name)
             VALUES
                (:reading_id, :measurement_key, :measurement_value, :unit, :measurement_name)'
        );

        foreach ($measurements as $measurement) {
            $measurementQuery->execute([
                ':reading_id' => $readingId,
                ':measurement_key' => $measurement['key'],
                ':measurement_value' => $measurement['value'],
                ':unit' => $measurement['unit'],
                ':measurement_name' => $measurement['name'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    fwrite(STDOUT, sprintf(
        "[%s] Releve #%d enregistre (%d mesures, onduleur %s).\n",
        gmdate('Y-m-d H:i:s') . ' UTC',
        $readingId,
        count($measurements),
        $deviceSn
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERREUR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
