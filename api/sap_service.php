<?php
/**
 * Cliente SAP Business One Service Layer (cURL)
 * Incluye datos simulados para modo offline.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Datos simulados de almacenes y conteos
 */
function sap_simulated_warehouses(): array
{
    return [
        ['WarehouseCode' => '01', 'WarehouseName' => 'Almacén General'],
        ['WarehouseCode' => '02', 'WarehouseName' => 'Almacén Materias Primas'],
        ['WarehouseCode' => '03', 'WarehouseName' => 'Almacén Producto Terminado'],
    ];
}

function sap_simulated_countings(?string $whs = null): array
{
    $all = [
        [
            'DocEntry' => 1001,
            'DocumentNumber' => 15,
            'CountDate' => '2026-03-15',
            'Remarks' => 'Conteo cíclico marzo - General',
            'DocumentStatus' => 'cdsOpen',
            'InventoryCountingLines' => [
                [
                    'LineNumber' => 0,
                    'ItemCode' => 'A00001',
                    'ItemDescription' => 'Mouse Óptico USB',
                    'WarehouseCode' => '01',
                    'CountedQuantity' => 100,
                    'InWhsQty' => 100,
                    'CountQuantity' => 100,
                    'UoMCode' => 'UN',
                ],
                [
                    'LineNumber' => 1,
                    'ItemCode' => 'A00002',
                    'ItemDescription' => 'Teclado Mecánico',
                    'WarehouseCode' => '01',
                    'CountedQuantity' => 48,
                    'InWhsQty' => 50,
                    'CountQuantity' => 48,
                    'UoMCode' => 'UN',
                ],
                [
                    'LineNumber' => 2,
                    'ItemCode' => 'B00010',
                    'ItemDescription' => 'Cable HDMI 2m',
                    'WarehouseCode' => '01',
                    'CountedQuantity' => 200,
                    'InWhsQty' => 200,
                    'CountQuantity' => 200,
                    'UoMCode' => 'UN',
                ],
            ],
        ],
        [
            'DocEntry' => 1002,
            'DocumentNumber' => 16,
            'CountDate' => '2026-03-18',
            'Remarks' => 'Conteo MP - Lote Q1',
            'DocumentStatus' => 'cdsOpen',
            'InventoryCountingLines' => [
                [
                    'LineNumber' => 0,
                    'ItemCode' => 'MP-001',
                    'ItemDescription' => 'Resina PVC Granulado',
                    'WarehouseCode' => '02',
                    'CountedQuantity' => 495.5,
                    'InWhsQty' => 500,
                    'CountQuantity' => 495.5,
                    'UoMCode' => 'KG',
                ],
                [
                    'LineNumber' => 1,
                    'ItemCode' => 'MP-002',
                    'ItemDescription' => 'Pigmento Azul Industrial',
                    'WarehouseCode' => '02',
                    'CountedQuantity' => 25,
                    'InWhsQty' => 25,
                    'CountQuantity' => 25,
                    'UoMCode' => 'KG',
                ],
            ],
        ],
        [
            'DocEntry' => 1003,
            'DocumentNumber' => 17,
            'CountDate' => '2026-03-20',
            'Remarks' => 'Conteo PT - Zona A',
            'DocumentStatus' => 'cdsOpen',
            'InventoryCountingLines' => [
                [
                    'LineNumber' => 0,
                    'ItemCode' => 'PT-100',
                    'ItemDescription' => 'Tubo PVC 1/2" x 3m',
                    'WarehouseCode' => '03',
                    'CountedQuantity' => 1200,
                    'InWhsQty' => 1180,
                    'CountQuantity' => 1200,
                    'UoMCode' => 'UN',
                ],
                [
                    'LineNumber' => 1,
                    'ItemCode' => 'PT-200',
                    'ItemDescription' => 'Codo PVC 90° 1/2"',
                    'WarehouseCode' => '03',
                    'CountedQuantity' => 850,
                    'InWhsQty' => 850,
                    'CountQuantity' => 850,
                    'UoMCode' => 'UN',
                ],
                [
                    'LineNumber' => 2,
                    'ItemCode' => 'PT-300',
                    'ItemDescription' => 'Tee PVC 1/2"',
                    'WarehouseCode' => '03',
                    'CountedQuantity' => 400,
                    'InWhsQty' => 410,
                    'CountQuantity' => 400,
                    'UoMCode' => 'UN',
                ],
            ],
        ],
    ];

    if ($whs === null || $whs === '') {
        return $all;
    }

    return array_values(array_filter($all, static function ($doc) use ($whs) {
        foreach ($doc['InventoryCountingLines'] as $line) {
            if (($line['WarehouseCode'] ?? '') === $whs) {
                return true;
            }
        }
        return false;
    }));
}

function load_sap_settings(): array
{
    $row = db()->query('SELECT * FROM sap_config ORDER BY id ASC LIMIT 1')->fetch();
    if (!$row) {
        return [
            'sl_url' => '',
            'company_db' => '',
            'sl_user' => '',
            'sl_password' => '',
            'simulation_mode' => 1,
        ];
    }
    return [
        'sl_url' => $row['sl_url'],
        'company_db' => $row['company_db'],
        'sl_user' => $row['sl_user'],
        'sl_password' => decrypt_secret($row['sl_password']),
        'simulation_mode' => (int)$row['simulation_mode'],
    ];
}

function is_simulation_mode(): bool
{
    $cfg = load_sap_settings();
    return (int)$cfg['simulation_mode'] === 1;
}

/**
 * Limpia y valida la URL base del Service Layer.
 */
function sap_normalize_base_url(string $url): string
{
    $url = trim($url);
    // Quitar BOM / caracteres de control / zero-width
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? $url;
    $url = preg_replace('/\x{200B}|\x{200C}|\x{200D}|\x{FEFF}/u', '', $url) ?? $url;
    $url = rtrim($url, "/ \t");

    if ($url === '') {
        return '';
    }

    // Si pegaron solo host:puerto, asumir https
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    return $url;
}

/**
 * Construye URL completa Service Layer con query OData bien codificada.
 * Evita "URL rejected: Malformed input" por espacios o comillas sin encode.
 *
 * @param array<string,string> $query  ej. ['$select'=>'A,B', '$filter'=>"Inactive eq 'tNO'"]
 */
function sap_build_url(string $base, string $resource = '', array $query = []): string
{
    $base = sap_normalize_base_url($base);
    if ($base === '') {
        return '';
    }

    $url = $base;
    $resource = ltrim($resource, '/');
    if ($resource !== '') {
        // Si el resource trae ?query embebido, separar y re-codificar
        $qPos = strpos($resource, '?');
        if ($qPos !== false) {
            $embedded = substr($resource, $qPos + 1);
            $resource = substr($resource, 0, $qPos);
            foreach (explode('&', $embedded) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    continue;
                }
                $k = urldecode(substr($pair, 0, $eq));
                $v = urldecode(substr($pair, $eq + 1));
                $query[$k] = $v;
            }
        }
        $url .= '/' . $resource;
    }

    if ($query) {
        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        $url .= '?' . implode('&', $parts);
    }

    return $url;
}

function sap_validate_url(string $url): ?string
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return 'URL del Service Layer inválida o vacía. Use el formato https://servidor:50000/b1s/v1';
    }
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return 'URL del Service Layer incompleta (faltan esquema o host)';
    }
    return null;
}

/**
 * Login Service Layer
 */
function sap_login(string $url, string $company, string $user, string $pass): array
{
    $loginUrl = sap_build_url($url, 'Login');
    $invalid = sap_validate_url($loginUrl);
    if ($invalid !== null) {
        return ['ok' => false, 'error' => $invalid, 'http_code' => 0];
    }

    $payload = json_encode([
        'CompanyDB' => $company,
        'UserName'  => $user,
        'Password'  => $pass,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $loginUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($errno) {
        return [
            'ok' => false,
            'error' => 'cURL: ' . $error . ' (URL: ' . $loginUrl . ')',
            'http_code' => 0,
        ];
    }

    $headers = substr((string)$response, 0, $headerSize);
    $body    = substr((string)$response, $headerSize);
    $json    = json_decode($body, true);

    if ($http < 200 || $http >= 300) {
        $msg = $json['error']['message']['value'] ?? ($json['error']['message'] ?? $body);
        return ['ok' => false, 'error' => is_string($msg) ? $msg : json_encode($msg), 'http_code' => $http, 'raw' => $json];
    }

    $sessionId = $json['SessionId'] ?? '';
    $routeId = '';
    if (preg_match('/B1SESSION=([^;]+)/i', $headers, $m)) {
        $sessionId = $sessionId ?: $m[1];
    }
    if (preg_match('/ROUTEID=([^;]+)/i', $headers, $m)) {
        $routeId = $m[1];
    }

    return [
        'ok' => true,
        'session_id' => $sessionId,
        'route_id' => $routeId,
        'raw' => $json,
        'http_code' => $http,
    ];
}

function sap_logout(string $url, string $sessionId, string $routeId = ''): void
{
    $cookie = 'B1SESSION=' . $sessionId;
    if ($routeId !== '') {
        $cookie .= '; ROUTEID=' . $routeId;
    }
    $logoutUrl = sap_build_url($url, 'Logout');
    if (sap_validate_url($logoutUrl) !== null) {
        return;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $logoutUrl,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Costo unitario desde un Item del Service Layer.
 * En SL el campo OITM.AvgPrice se expone como AvgStdPrice (AvgPrice no es válido).
 *
 * @param array<string,mixed> $it
 */
function sap_item_unit_cost(array $it): float
{
    foreach (['AvgStdPrice', 'MovingAveragePrice', 'LastPurchasePrice', 'LastPurPrc', 'AvgPrice', 'Price'] as $key) {
        $v = (float)($it[$key] ?? 0);
        if ($v > 0) {
            return $v;
        }
    }
    // Lista de precios del ítem (a menudo trae costo/compra)
    $prices = $it['ItemPrices'] ?? null;
    if (is_array($prices)) {
        foreach ($prices as $p) {
            if (!is_array($p)) {
                continue;
            }
            $v = (float)($p['Price'] ?? 0);
            if ($v > 0) {
                return $v;
            }
        }
    }
    $whLines = $it['ItemWarehouseInfoCollection'] ?? null;
    if (is_array($whLines)) {
        foreach ($whLines as $wl) {
            if (!is_array($wl)) {
                continue;
            }
            foreach (['AvgStockPrice', 'AveragePrice', 'AvgPrice', 'StockValue'] as $key) {
                $v = (float)($wl[$key] ?? 0);
                if ($key === 'StockValue') {
                    $qty = (float)($wl['InStock'] ?? $wl['OnHand'] ?? 0);
                    $v = ($qty > 0) ? ($v / $qty) : 0;
                }
                if ($v > 0) {
                    return $v;
                }
            }
        }
    }
    return 0.0;
}

/**
 * Consulta Items con $select progresivo (cada versión SL expone campos distintos).
 *
 * @param array<string,mixed> $login
 * @return array{ok:bool,data?:mixed,error?:string}
 */
function sap_items_query(array $login, string $filter, int $top = 0): array
{
    $selects = [
        'ItemCode,ItemName,BarCode,QuantityOnStock,AvgStdPrice,LastPurchasePrice',
        'ItemCode,ItemName,BarCode,QuantityOnStock,AvgStdPrice',
        'ItemCode,ItemName,BarCode,QuantityOnStock,LastPurchasePrice',
        'ItemCode,ItemName,BarCode,QuantityOnStock,MovingAveragePrice',
        'ItemCode,ItemName,BarCode,QuantityOnStock',
    ];
    foreach ($selects as $sel) {
        $q = ['$select' => $sel, '$filter' => $filter];
        if ($top > 0) {
            $q['$top'] = (string)$top;
        }
        $res = sap_request('GET', 'Items', null, $login, $q);
        if ($res['ok']) {
            return $res;
        }
    }
    // Sin $select: SAP devuelve el set por defecto (suele incluir costo)
    $q = ['$filter' => $filter];
    if ($top > 0) {
        $q['$top'] = (string)$top;
    }
    return sap_request('GET', 'Items', null, $login, $q);
}

/**
 * Si el lote no trajo costo, pide el ítem completo uno a uno (máx. 40).
 *
 * @param array<string,mixed> $login
 * @param array<string,array|null> $map
 */
function sap_fill_missing_item_costs(array $login, array &$map): void
{
    $n = 0;
    foreach ($map as $code => &$info) {
        if (!is_array($info) || (float)($info['unit_cost'] ?? 0) > 0) {
            continue;
        }
        if ($n >= 40) {
            break;
        }
        $n++;
        $esc = str_replace("'", "''", (string)$code);
        $res = sap_request('GET', "Items('{$esc}')", null, $login, null);
        $it = null;
        if ($res['ok'] && is_array($res['data'] ?? null)) {
            $it = $res['data'];
        } else {
            $res = sap_items_query($login, "ItemCode eq '{$esc}'", 1);
            if ($res['ok']) {
                $it = $res['data']['value'][0] ?? null;
            }
        }
        if (!is_array($it)) {
            continue;
        }
        $cost = sap_item_unit_cost($it);
        if ($cost > 0) {
            $info['unit_cost'] = $cost;
        }
        if (empty($info['item_name']) && !empty($it['ItemName'])) {
            $info['item_name'] = (string)$it['ItemName'];
        }
        if (empty($info['barcode']) && !empty($it['BarCode'])) {
            $info['barcode'] = (string)$it['BarCode'];
        }
    }
    unset($info);
}

/**
 * Petición genérica autenticada al Service Layer
 *
 * @param array<string,string>|null $query Parámetros OData ($select, $filter, …)
 */
function sap_request(string $method, string $path, ?array $body = null, ?array $session = null, ?array $query = null): array
{
    $cfg = load_sap_settings();
    if ((int)$cfg['simulation_mode'] === 1) {
        return ['ok' => false, 'error' => 'Modo simulación activo', 'http_code' => 0];
    }

    if ($session === null) {
        $login = sap_login($cfg['sl_url'], $cfg['company_db'], $cfg['sl_user'], $cfg['sl_password']);
        if (!$login['ok']) {
            return $login;
        }
        $session = $login;
        $autoLogout = true;
    } else {
        $autoLogout = false;
    }

    $cookie = 'B1SESSION=' . $session['session_id'];
    if (!empty($session['route_id'])) {
        $cookie .= '; ROUTEID=' . $session['route_id'];
    }

    $url = sap_build_url($cfg['sl_url'], $path, $query ?? []);
    $invalid = sap_validate_url(strtok($url, '?') ?: $url);
    if ($invalid !== null) {
        return ['ok' => false, 'error' => $invalid, 'http_code' => 0];
    }

    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Cookie: ' . $cookie,
    ];

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 60,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($autoLogout) {
        sap_logout($cfg['sl_url'], $session['session_id'], $session['route_id'] ?? '');
    }

    if ($errno) {
        return ['ok' => false, 'error' => 'cURL: ' . $error . ' (URL: ' . $url . ')', 'http_code' => 0];
    }

    $json = json_decode((string)$response, true);
    if ($http < 200 || $http >= 300) {
        $msg = $json['error']['message']['value'] ?? ($json['error']['message'] ?? $response);
        return [
            'ok' => false,
            'error' => is_string($msg) ? $msg : json_encode($msg),
            'http_code' => $http,
            'raw' => $json,
        ];
    }

    return ['ok' => true, 'data' => $json, 'http_code' => $http, 'session' => $session];
}

/**
 * Stock de un ítem en un almacén concreto (ItemWarehouseInfoCollection).
 * null = no hay línea de ese almacén en la respuesta.
 */
function sap_whs_qty_from_item(array $it, string $whs): ?float
{
    $whs = trim($whs);
    $lines = $it['ItemWarehouseInfoCollection'] ?? null;
    if (!is_array($lines) || $whs === '') {
        return null;
    }
    foreach ($lines as $wl) {
        if (!is_array($wl)) {
            continue;
        }
        if (strcasecmp((string)($wl['WarehouseCode'] ?? ''), $whs) !== 0) {
            continue;
        }
        return (float)($wl['InStock'] ?? $wl['OnHand'] ?? $wl['InWhsQty'] ?? 0);
    }
    return null;
}

/**
 * Consulta Items con expand de almacenes (fallback sin expand).
 *
 * @param array<string,mixed> $login
 * @return array{ok:bool,data?:mixed,error?:string,has_whs?:bool}
 */
function sap_items_query_with_whs(array $login, string $filter, int $top = 0): array
{
    // Algunas SL rechazan $expand en listas; probar también colección en $select
    $attempts = [
        ['$select' => 'ItemCode,ItemName,BarCode,AvgStdPrice,LastPurchasePrice,QuantityOnStock,ItemWarehouseInfoCollection', '$filter' => $filter],
        ['$select' => 'ItemCode,ItemName,BarCode,AvgStdPrice,QuantityOnStock', '$expand' => 'ItemWarehouseInfoCollection', '$filter' => $filter],
        ['$select' => 'ItemCode,ItemName,BarCode,QuantityOnStock', '$expand' => 'ItemWarehouseInfoCollection', '$filter' => $filter],
        ['$expand' => 'ItemWarehouseInfoCollection', '$filter' => $filter],
    ];
    foreach ($attempts as $q) {
        if ($top > 0) {
            $q['$top'] = (string)$top;
        }
        $res = sap_request('GET', 'Items', null, $login, $q);
        if (!$res['ok']) {
            continue;
        }
        $hasWhs = false;
        foreach (($res['data']['value'] ?? []) as $it) {
            if (is_array($it) && !empty($it['ItemWarehouseInfoCollection'])) {
                $hasWhs = true;
                break;
            }
        }
        $res['has_whs'] = $hasWhs;
        return $res;
    }
    $res = sap_items_query($login, $filter, $top);
    $res['has_whs'] = false;
    return $res;
}

/**
 * Trae un ítem completo (como al escanear) para leer stock por almacén.
 *
 * @param array<string,mixed> $login
 */
function sap_fetch_item_full(array $login, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $esc = str_replace("'", "''", $code);
    $attempts = [
        ['$select' => 'ItemCode,ItemName,BarCode,AvgStdPrice,LastPurchasePrice,QuantityOnStock,ItemWarehouseInfoCollection'],
        ['$expand' => 'ItemWarehouseInfoCollection'],
        null, // ítem completo por defecto
    ];
    foreach ($attempts as $q) {
        $res = sap_request('GET', "Items('{$esc}')", null, $login, $q);
        if ($res['ok'] && is_array($res['data'] ?? null) && !isset($res['data']['value'])) {
            return $res['data'];
        }
    }
    $res = sap_items_query_with_whs($login, "ItemCode eq '{$esc}'", 1);
    $row = $res['ok'] ? ($res['data']['value'][0] ?? null) : null;
    return is_array($row) ? $row : null;
}

/**
 * Resuelve stock de almacén para una lista de códigos (GET ítem completo si falta colección).
 *
 * @param array<string,mixed> $login
 * @param list<string> $codes
 * @return array<string,array<string,mixed>|null>
 */
function sap_resolve_warehouse_stock_map(array $login, array $codes, string $whs, int $maxFull = 80): array
{
    $map = [];
    $needFull = [];
    foreach ($codes as $code) {
        $code = trim((string)$code);
        if ($code === '') {
            continue;
        }
        $needFull[] = $code;
    }
    $n = 0;
    foreach ($needFull as $code) {
        if ($n >= $maxFull) {
            if (!array_key_exists($code, $map)) {
                $map[$code] = null;
            }
            continue;
        }
        $n++;
        $it = sap_fetch_item_full($login, $code);
        if (!is_array($it)) {
            $map[$code] = null;
            continue;
        }
        $whsQty = $whs !== '' ? sap_whs_qty_from_item($it, $whs) : null;
        $map[$code] = [
            'system_qty' => $whsQty !== null ? $whsQty : (float)($it['QuantityOnStock'] ?? 0),
            'item_name' => (string)($it['ItemName'] ?? ''),
            'barcode' => (string)($it['BarCode'] ?? ''),
            'unit_cost' => sap_item_unit_cost($it),
            'warehouse' => $whs,
            'stock_scope' => $whsQty !== null ? 'warehouse' : 'company',
        ];
    }
    return $map;
}

/**
 * Busca AbsoluteEntry de un adjunto por nombre de archivo.
 *
 * @param array<string,mixed> $session
 */
function sap_find_attachment_by_filename(array $session, string $fileName): ?int
{
    $fileName = basename($fileName);
    if ($fileName === '') {
        return null;
    }
    $res = sap_request('GET', 'Attachments2', null, $session, [
        '$top' => '100',
        '$orderby' => 'AbsoluteEntry desc',
    ]);
    if (!$res['ok']) {
        return null;
    }
    $rows = $res['data']['value'] ?? [];
    if (!is_array($rows)) {
        return null;
    }
    foreach ($rows as $att) {
        if (!is_array($att)) {
            continue;
        }
        $abs = (int)($att['AbsoluteEntry'] ?? 0);
        $lines = $att['Attachments2_Lines'] ?? [];
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $fn = (string)($line['FileName'] ?? '');
            if ($fn !== '' && strcasecmp($fn, $fileName) === 0) {
                return $abs > 0 ? $abs : null;
            }
            // A veces FileName viene sin extensión en un campo y SourcePath con el nombre
            $src = basename((string)($line['SourcePath'] ?? ''));
            if ($src !== '' && strcasecmp($src, $fileName) === 0) {
                return $abs > 0 ? $abs : null;
            }
        }
    }
    return null;
}

/**
 * Sube PDF como Attachment2. Si el archivo ya existe, reutiliza AbsoluteEntry.
 *
 * @param array<string,mixed> $session
 */
function sap_upload_attachment(string $pdfPath, string $fileName, array $session): array
{
    $cfg = load_sap_settings();
    $cookie = 'B1SESSION=' . $session['session_id'];
    if (!empty($session['route_id'])) {
        $cookie .= '; ROUTEID=' . $session['route_id'];
    }

    $fileName = basename($fileName);
    $url = sap_build_url($cfg['sl_url'], 'Attachments2');
    if (sap_validate_url($url) !== null) {
        return ['ok' => false, 'error' => 'URL inválida para Attachments2'];
    }
    $cfile = new CURLFile($pdfPath, 'application/pdf', $fileName);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['files' => $cfile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => 'cURL attachment: ' . $error];
    }

    $json = json_decode((string)$response, true);
    if ($http >= 200 && $http < 300) {
        $abs = $json['AbsoluteEntry'] ?? ($json['Attachments2_Lines'][0]['AbsoluteEntry'] ?? null);
        return ['ok' => true, 'AbsoluteEntry' => $abs, 'raw' => $json, 'reused' => false];
    }

    $msg = $json['error']['message']['value'] ?? ($response ?: 'Error al subir attachment');
    $msgStr = is_string($msg) ? $msg : json_encode($msg);
    // Reutilizar si SAP dice que el archivo ya existe
    if (stripos($msgStr, 'already exists') !== false || stripos($msgStr, 'ya existe') !== false) {
        $found = sap_find_attachment_by_filename($session, $fileName);
        if ($found) {
            return [
                'ok' => true,
                'AbsoluteEntry' => $found,
                'reused' => true,
                'message' => 'Adjunto reutilizado (archivo ya existía en SAP)',
            ];
        }
        // Subir con nombre único para no chocar
        $unique = pathinfo($fileName, PATHINFO_FILENAME) . '_' . date('YmdHis') . '.pdf';
        $cfile2 = new CURLFile($pdfPath, 'application/pdf', $unique);
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['files' => $cfile2],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $response2 = curl_exec($ch2);
        $http2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        $json2 = json_decode((string)$response2, true);
        if ($http2 >= 200 && $http2 < 300) {
            $abs = $json2['AbsoluteEntry'] ?? ($json2['Attachments2_Lines'][0]['AbsoluteEntry'] ?? null);
            return ['ok' => true, 'AbsoluteEntry' => $abs, 'raw' => $json2, 'reused' => false, 'fileName' => $unique];
        }
    }

    return ['ok' => false, 'error' => $msgStr, 'http_code' => $http, 'raw' => $json];
}

/**
 * PATCH InventoryCountings reutilizando sesión abierta.
 *
 * @param array<string,mixed> $body
 * @param array<string,mixed> $session
 */
function sap_patch_inventory_counting(int $docEntry, array $body, array $session): array
{
    return sap_request('PATCH', 'InventoryCountings(' . $docEntry . ')', $body, $session);
}

/**
 * Arma líneas de conteo para SAP (solo LineNumber + cantidad + Contado).
 * Los UDF (estado/código/auditor) viven en MySQL, no se envían a SAP.
 *
 * @param array<int,array<string,mixed>> $auditItems
 * @param array<int,array<string,mixed>> $sapLines
 * @return array{0:list<array<string,mixed>>,1:list<string>}
 */
function sap_build_counting_line_patch(array $auditItems, array $sapLines, string $warehouseCode): array
{
    $byLine = [];
    $byItem = [];
    foreach ($sapLines as $sl) {
        if (!is_array($sl)) {
            continue;
        }
        $ln = (int)($sl['LineNumber'] ?? $sl['LineNum'] ?? -1);
        $code = strtoupper(trim((string)($sl['ItemCode'] ?? '')));
        if ($ln >= 0) {
            $byLine[$ln] = $sl;
        }
        if ($code !== '') {
            $byItem[$code] = $sl;
        }
    }

    $patchLines = [];
    $warnings = [];
    foreach ($auditItems as $ai) {
        $code = strtoupper(trim((string)($ai['item_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $wantLine = (int)($ai['line_num'] ?? -1);
        $match = null;
        if ($wantLine >= 0 && isset($byLine[$wantLine])) {
            $match = $byLine[$wantLine];
            $matchCode = strtoupper(trim((string)($match['ItemCode'] ?? '')));
            if ($matchCode !== '' && $matchCode !== $code) {
                $match = $byItem[$code] ?? null;
            }
        } else {
            $match = $byItem[$code] ?? null;
        }
        if ($match === null) {
            $warnings[] = "Ítem {$code} no está en el documento de conteo SAP";
            continue;
        }
        $lineNumber = (int)($match['LineNumber'] ?? $match['LineNum'] ?? $wantLine);
        $patchLines[] = [
            'LineNumber' => $lineNumber,
            'CountedQuantity' => (float)($ai['counted_qty'] ?? 0),
            'Counted' => 'tYES',
        ];
    }

    return [$patchLines, $warnings];
}

/**
 * Persiste resultado de envío SAP.
 * - Guarda AbsoluteEntry aunque falle (para reutilizar adjunto)
 * - Solo marca ENVIADO_SAP si ok=true (cantidades + PDF vinculado)
 *
 * @param array<string,mixed> $sapResult
 */
function sap_persist_send_result(PDO $pdo, int $auditId, array $sapResult): string
{
    $ae = $sapResult['AbsoluteEntry'] ?? null;
    $ae = ($ae !== null && $ae !== '' && (int)$ae > 0) ? (int)$ae : null;
    $json = json_encode($sapResult, JSON_UNESCAPED_UNICODE);

    if (!empty($sapResult['ok'])) {
        $pdo->prepare(
            'UPDATE audits SET sap_status = :s, sap_attachment_entry = COALESCE(:ae, sap_attachment_entry),
                    sap_response = :r, sap_sent_at = NOW() WHERE id = :id'
        )->execute([
            ':s' => 'ENVIADO_SAP',
            ':ae' => $ae,
            ':r' => $json,
            ':id' => $auditId,
        ]);
        return 'ENVIADO_SAP';
    }

    if ($ae !== null) {
        $pdo->prepare(
            'UPDATE audits SET sap_status = :s, sap_attachment_entry = :ae, sap_response = :r WHERE id = :id'
        )->execute([
            ':s' => 'PENDIENTE_SAP',
            ':ae' => $ae,
            ':r' => $json,
            ':id' => $auditId,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE audits SET sap_status = :s, sap_response = :r WHERE id = :id'
        )->execute([
            ':s' => 'PENDIENTE_SAP',
            ':r' => $json,
            ':id' => $auditId,
        ]);
    }
    return 'PENDIENTE_SAP';
}

/**
 * Envía resultado final de auditoría a SAP:
 * - Actualiza CountedQuantity / Counted en el Inventory Counting
 * - Adjunta PDF (reutiliza AbsoluteEntry si ya existe; no re-sube en cada retry)
 * - NO envía U_Audit* (quedan en MySQL: verdict, audit_code, auditor_name)
 */
function sap_send_audit_result(array $audit, string $pdfAbsolutePath): array
{
    $localMeta = [
        'audit_code' => $audit['audit_code'] ?? null,
        'verdict' => $audit['verdict'] ?? null,
        'auditor_name' => $audit['auditor_name'] ?? null,
        'warehouse_code' => $audit['warehouse_code'] ?? null,
        'counting_doc_entry' => (int)($audit['counting_doc_entry'] ?? 0),
        'counting_doc_num' => (int)($audit['counting_doc_num'] ?? 0),
        'note' => 'UDF no usados en SAP; metadatos solo en MySQL',
    ];

    if (is_simulation_mode()) {
        return [
            'ok' => true,
            'simulated' => true,
            'AbsoluteEntry' => null,
            'message' => 'Modo simulación: no se envió a SAP',
            'local' => $localMeta,
        ];
    }

    $docEntry = (int)($audit['counting_doc_entry'] ?? 0);
    if ($docEntry <= 0) {
        return [
            'ok' => true,
            'skipped' => true,
            'AbsoluteEntry' => null,
            'message' => 'Sin documento de conteo: no se vincula a InventoryCountings',
            'local' => $localMeta,
        ];
    }

    $auditId = (int)($audit['id'] ?? 0);
    $items = [];
    if ($auditId > 0) {
        $st = db()->prepare(
            'SELECT line_num, item_code, item_name, system_qty, counted_qty, difference_qty, status
             FROM audit_items WHERE audit_id = ? ORDER BY line_num, id'
        );
        $st->execute([$auditId]);
        $items = $st->fetchAll();
    }
    if (!$items) {
        return [
            'ok' => false,
            'error' => 'La auditoría no tiene ítems para enviar a SAP',
            'AbsoluteEntry' => null,
            'local' => $localMeta,
        ];
    }

    $cfg = load_sap_settings();
    $login = sap_login($cfg['sl_url'], $cfg['company_db'], $cfg['sl_user'], $cfg['sl_password']);
    if (!$login['ok']) {
        return $login;
    }

    try {
        $docRes = sap_request('GET', 'InventoryCountings(' . $docEntry . ')', null, $login);
        if (!$docRes['ok']) {
            return [
                'ok' => false,
                'error' => $docRes['error'] ?? 'No se pudo leer InventoryCounting',
                'AbsoluteEntry' => null,
                'local' => $localMeta,
            ];
        }
        $sapLines = $docRes['data']['InventoryCountingLines'] ?? [];
        if (!is_array($sapLines)) {
            $sapLines = [];
        }

        $whs = (string)($audit['warehouse_code'] ?? '');
        [$patchLines, $lineWarnings] = sap_build_counting_line_patch($items, $sapLines, $whs);
        if (!$patchLines) {
            return [
                'ok' => false,
                'error' => 'Ningún ítem de la auditoría coincide con líneas del conteo SAP',
                'warnings' => $lineWarnings,
                'AbsoluteEntry' => null,
                'local' => $localMeta,
            ];
        }

        // 1) Primero solo cantidades (sin adjunto), para no mezclar fallos
        $patchLinesOnly = sap_patch_inventory_counting($docEntry, [
            'InventoryCountingLines' => $patchLines,
        ], $login);
        if (!$patchLinesOnly['ok']) {
            return [
                'ok' => false,
                'error' => $patchLinesOnly['error'] ?? 'Error PATCH cantidades',
                'http_code' => $patchLinesOnly['http_code'] ?? null,
                'AbsoluteEntry' => (int)($audit['sap_attachment_entry'] ?? 0) ?: null,
                'attachment_linked' => false,
                'lines_ok' => false,
                'warnings' => $lineWarnings,
                'local' => $localMeta,
            ];
        }

        // 2) Resolver AbsoluteEntry del PDF (DB → upload/reuso si ya existe)
        $absoluteEntry = null;
        $existingAtt = (int)($audit['sap_attachment_entry'] ?? 0);
        if ($existingAtt > 0) {
            $absoluteEntry = $existingAtt;
        } elseif ($pdfAbsolutePath !== '' && is_file($pdfAbsolutePath)) {
            $preferredName = trim((string)($audit['audit_code'] ?? ''));
            $fileName = $preferredName !== ''
                ? (preg_replace('/[^A-Za-z0-9_-]/', '_', $preferredName) . '.pdf')
                : basename($pdfAbsolutePath);
            $att = sap_upload_attachment($pdfAbsolutePath, $fileName, $login);
            if ($att['ok']) {
                $absoluteEntry = isset($att['AbsoluteEntry']) ? (int)$att['AbsoluteEntry'] : null;
                if (!empty($att['reused'])) {
                    $lineWarnings[] = 'PDF reutilizado en SAP (AbsoluteEntry ' . $absoluteEntry . ')';
                }
            } else {
                // Último intento: buscar el nombre original del archivo local
                $found = sap_find_attachment_by_filename($login, basename($pdfAbsolutePath));
                if ($found) {
                    $absoluteEntry = $found;
                    $lineWarnings[] = 'PDF localizado en SAP por nombre (AbsoluteEntry ' . $found . ')';
                } else {
                    $lineWarnings[] = 'PDF no disponible: ' . ($att['error'] ?? 'error');
                }
            }
        }

        // 3) Vincular adjunto al conteo — obligatorio para ok=true / ENVIADO_SAP
        $attachmentLinked = false;
        if ($absoluteEntry) {
            $docAtt = (int)($docRes['data']['AttachmentEntry'] ?? 0);
            if ($docAtt > 0 && $docAtt === (int)$absoluteEntry) {
                $attachmentLinked = true;
            } else {
                $patchAtt = sap_patch_inventory_counting($docEntry, [
                    'AttachmentEntry' => (int)$absoluteEntry,
                ], $login);
                if ($patchAtt['ok']) {
                    $attachmentLinked = true;
                } else {
                    $lineWarnings[] = 'Adjunto no vinculado al conteo: ' . ($patchAtt['error'] ?? '');
                }
            }
        }

        if (!$attachmentLinked) {
            return [
                'ok' => false,
                'error' => 'Cantidades enviadas, pero el PDF no quedó vinculado al conteo SAP',
                'AbsoluteEntry' => $absoluteEntry,
                'attachment_linked' => false,
                'lines_ok' => true,
                'lines_updated' => count($patchLines),
                'warnings' => $lineWarnings,
                'local' => $localMeta,
                'doc_entry' => $docEntry,
                'doc_num' => (int)($audit['counting_doc_num'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'AbsoluteEntry' => $absoluteEntry,
            'attachment_linked' => true,
            'lines_ok' => true,
            'message' => 'Cantidades y PDF vinculados en SAP B1',
            'lines_updated' => count($patchLines),
            'warnings' => $lineWarnings ?: null,
            'local' => $localMeta,
            'doc_entry' => $docEntry,
            'doc_num' => (int)($audit['counting_doc_num'] ?? 0),
        ];
    } finally {
        sap_logout($cfg['sl_url'], $login['session_id'], $login['route_id'] ?? '');
    }
}

// --- Endpoints públicos de lectura SAP / simulación ---
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'sap_service.php') {
    require_once __DIR__ . '/auth.php';
    handle_preflight();
    require_auth();

    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'warehouses' && $method === 'GET') {
        if (is_simulation_mode()) {
            json_response(['ok' => true, 'simulation' => true, 'data' => sap_simulated_warehouses()]);
        }
        // Query OData codificada (cURL moderno rechaza espacios/comillas crudas)
        $res = sap_request('GET', 'Warehouses', null, null, [
            '$select' => 'WarehouseCode,WarehouseName',
            '$filter' => "Inactive eq 'tNO'",
        ]);
        if (!$res['ok']) {
            // Fallback sin filtro si el campo Inactive no existe en esa versión
            $res = sap_request('GET', 'Warehouses', null, null, [
                '$select' => 'WarehouseCode,WarehouseName',
            ]);
        }
        if (!$res['ok']) {
            json_error($res['error'] ?? 'Error al obtener almacenes', 502, $res);
        }
        $value = $res['data']['value'] ?? $res['data'];
        json_response(['ok' => true, 'simulation' => false, 'data' => $value]);
    }

    if ($action === 'countings' && $method === 'GET') {
        $whs = trim((string)($_GET['warehouse'] ?? ''));
        $freeOption = [
            'DocEntry' => 0,
            'DocumentEntry' => 0,
            'DocumentNumber' => 0,
            'CountDate' => '',
            'Remarks' => 'Conteo libre de bodega',
            'DocumentStatus' => '',
            'label' => 'Sin documento — contar bodega como está',
        ];

        if (is_simulation_mode()) {
            $list = sap_simulated_countings($whs !== '' ? $whs : null);
            $summary = array_map(static function ($d) {
                return [
                    'DocEntry' => $d['DocEntry'],
                    'DocumentNumber' => $d['DocumentNumber'],
                    'CountDate' => $d['CountDate'],
                    'Remarks' => $d['Remarks'],
                    'DocumentStatus' => $d['DocumentStatus'],
                    'WarehouseCode' => $d['InventoryCountingLines'][0]['WarehouseCode'] ?? '',
                    'LineCount' => count($d['InventoryCountingLines']),
                    'label' => $d['DocumentNumber'] . ' — ' . $d['CountDate'] . ' (' . $d['Remarks'] . ')',
                ];
            }, $list);
            array_unshift($summary, $freeOption);
            json_response(['ok' => true, 'simulation' => true, 'data' => $summary]);
        }

        // Inventory Counting: DocumentEntry (no DocEntry) + DocumentStatus = cdsOpen|cdsClosed
        $res = sap_request(
            'GET',
            'InventoryCountings',
            null,
            null,
            [
                '$select' => 'DocumentEntry,DocumentNumber,CountDate,Remarks,DocumentStatus',
                '$filter' => "DocumentStatus eq 'cdsOpen'",
            ]
        );
        if (!$res['ok']) {
            json_error($res['error'] ?? 'Error al obtener conteos', 502, $res);
        }
        $value = $res['data']['value'] ?? [];
        $normalized = array_map(static function ($d) {
            $entry = (int)($d['DocumentEntry'] ?? $d['DocEntry'] ?? 0);
            $num = $d['DocumentNumber'] ?? 0;
            $date = $d['CountDate'] ?? '';
            $remarks = $d['Remarks'] ?? '';
            return [
                'DocEntry' => $entry,
                'DocumentEntry' => $entry,
                'DocumentNumber' => $num,
                'CountDate' => $date,
                'Remarks' => $remarks,
                'DocumentStatus' => $d['DocumentStatus'] ?? '',
                'label' => $num . ' — ' . $date . ' (' . $remarks . ')',
            ];
        }, $value);
        array_unshift($normalized, $freeOption);
        json_response(['ok' => true, 'simulation' => false, 'data' => $normalized]);
    }

    if ($action === 'warehouse_stock' && $method === 'GET') {
        $whs = trim((string)($_GET['warehouse'] ?? ''));
        if ($whs === '') {
            json_error('warehouse es obligatorio');
        }
        if (is_simulation_mode()) {
            $stock = [];
            foreach (sap_simulated_countings($whs) as $doc) {
                foreach ($doc['InventoryCountingLines'] as $i => $l) {
                    if (($l['WarehouseCode'] ?? '') !== $whs) {
                        continue;
                    }
                    $qty = (float)($l['InWhsQty'] ?? 0);
                    $cost = (float)($l['AvgStdPrice'] ?? $l['AvgPrice'] ?? $l['Price'] ?? 10); // demo
                    $stock[] = [
                        'line_num' => $i,
                        'item_code' => $l['ItemCode'],
                        'item_name' => $l['ItemDescription'],
                        'system_qty' => $qty,
                        'counted_qty' => $qty,
                        'difference_qty' => 0,
                        'unit_cost' => $cost,
                        'merma_qty' => 0,
                        'merma_value' => 0,
                        'status' => 'OK',
                    ];
                }
            }
            json_response(['ok' => true, 'simulation' => true, 'data' => $stock]);
        }

        // Stock por almacén: lista + enriquecimiento ítem-a-ítem (como al escanear).
        $cfg = load_sap_settings();
        $login = sap_login($cfg['sl_url'], $cfg['company_db'], $cfg['sl_user'], $cfg['sl_password']);
        if (!$login['ok']) {
            json_error($login['error'] ?? 'No se pudo iniciar sesión en SAP', 502, $login);
        }
        $res = sap_items_query_with_whs($login, "SalesItem eq 'tYES'", 100);
        if (!$res['ok']) {
            $res = sap_items_query_with_whs($login, "QuantityOnStock gt 0", 100);
        }
        if (!$res['ok']) {
            sap_logout($cfg['sl_url'], $login['session_id'], $login['route_id'] ?? '');
            json_error($res['error'] ?? 'No se pudo obtener stock', 502, $res);
        }

        $codes = [];
        foreach (($res['data']['value'] ?? []) as $it) {
            $code = (string)($it['ItemCode'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        // Si la lista no trae colección de almacén, pedir ítem completo (igual que escaneo)
        $map = [];
        if (!empty($res['has_whs'])) {
            foreach (($res['data']['value'] ?? []) as $it) {
                $code = (string)($it['ItemCode'] ?? '');
                if ($code === '') {
                    continue;
                }
                $whsQty = sap_whs_qty_from_item($it, $whs);
                if ($whsQty === null) {
                    continue;
                }
                $map[$code] = [
                    'system_qty' => $whsQty,
                    'item_name' => (string)($it['ItemName'] ?? ''),
                    'barcode' => (string)($it['BarCode'] ?? ''),
                    'unit_cost' => sap_item_unit_cost($it),
                    'warehouse' => $whs,
                    'stock_scope' => 'warehouse',
                ];
            }
        }
        $missing = array_values(array_filter($codes, static fn($c) => !isset($map[$c])));
        if ($missing) {
            $extra = sap_resolve_warehouse_stock_map($login, $missing, $whs, 80);
            foreach ($extra as $code => $info) {
                if (is_array($info) && ($info['stock_scope'] ?? '') === 'warehouse') {
                    $map[$code] = $info;
                } elseif (is_array($info) && !isset($map[$code])) {
                    // Solo incluir company si no hay nada mejor y qty > 0 en ese almacén falló
                    // omitimos company-wide para no volver a mostrar 1090
                }
            }
        }

        $stock = [];
        $i = 0;
        $companyFallback = 0;
        foreach ($map as $code => $info) {
            if (!is_array($info)) {
                continue;
            }
            $qty = (float)($info['system_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            if (($info['stock_scope'] ?? '') !== 'warehouse') {
                $companyFallback++;
                continue; // no mezclar stock empresa en "Cargar stock"
            }
            $stock[] = [
                'line_num' => $i++,
                'item_code' => $code,
                'item_name' => $info['item_name'] ?? '',
                'system_qty' => $qty,
                'counted_qty' => $qty,
                'difference_qty' => 0,
                'unit_cost' => (float)($info['unit_cost'] ?? 0),
                'merma_qty' => 0,
                'merma_value' => 0,
                'status' => 'OK',
                'warehouse' => $whs,
                'stock_scope' => 'warehouse',
            ];
        }
        sap_logout($cfg['sl_url'], $login['session_id'], $login['route_id'] ?? '');
        json_response([
            'ok' => true,
            'simulation' => false,
            'data' => $stock,
            'warehouse' => $whs,
            'warning' => !$stock
                ? 'No se obtuvo stock por almacén desde Service Layer. Use un documento de conteo o escanee ítems.'
                : null,
        ]);
    }

    if ($action === 'counting' && $method === 'GET') {
        $docEntry = (int)($_GET['docEntry'] ?? 0);
        if ($docEntry <= 0) {
            json_error('docEntry es obligatorio');
        }
        if (is_simulation_mode()) {
            foreach (sap_simulated_countings() as $doc) {
                if ((int)$doc['DocEntry'] === $docEntry) {
                    json_response(['ok' => true, 'simulation' => true, 'data' => $doc]);
                }
            }
            json_error('Documento de conteo no encontrado (simulación)', 404);
        }

        $res = sap_request('GET', 'InventoryCountings(' . $docEntry . ')');
        if (!$res['ok']) {
            json_error($res['error'] ?? 'Error al obtener documento', 502, $res);
        }
        $doc = $res['data'];
        // Alias DocEntry para el wizard OpenUI5
        if (isset($doc['DocumentEntry']) && !isset($doc['DocEntry'])) {
            $doc['DocEntry'] = $doc['DocumentEntry'];
        }
        json_response(['ok' => true, 'simulation' => false, 'data' => $doc]);
    }

    if ($action === 'item_by_code' && $method === 'GET') {
        $code = trim((string)($_GET['code'] ?? ''));
        $whs = trim((string)($_GET['warehouse'] ?? ''));
        if ($code === '') {
            json_error('code es obligatorio');
        }
        if (is_simulation_mode()) {
            foreach (sap_simulated_countings($whs !== '' ? $whs : null) as $doc) {
                foreach ($doc['InventoryCountingLines'] as $l) {
                    if (strcasecmp((string)$l['ItemCode'], $code) === 0) {
                        json_response([
                            'ok' => true,
                            'simulation' => true,
                            'data' => [
                                'ItemCode' => $l['ItemCode'],
                                'ItemName' => $l['ItemDescription'],
                                'BarCode' => $l['ItemCode'],
                                'QuantityOnStock' => $l['InWhsQty'] ?? 0,
                                'system_qty' => (float)($l['InWhsQty'] ?? 0),
                                'warehouse' => $l['WarehouseCode'] ?? $whs,
                            ],
                        ]);
                    }
                }
            }
            json_response(['ok' => true, 'simulation' => true, 'data' => null]);
        }

        $escaped = str_replace("'", "''", $code);
        $cfg = load_sap_settings();
        $login = sap_login($cfg['sl_url'], $cfg['company_db'], $cfg['sl_user'], $cfg['sl_password']);
        if (!$login['ok']) {
            json_error($login['error'] ?? 'No se pudo iniciar sesión en SAP', 502, $login);
        }
        $row = null;
        // Preferir expand de almacén
        $res = sap_request('GET', "Items('{$escaped}')", null, $login, [
            '$select' => 'ItemCode,ItemName,BarCode,AvgStdPrice,LastPurchasePrice,QuantityOnStock',
            '$expand' => 'ItemWarehouseInfoCollection',
        ]);
        if ($res['ok'] && is_array($res['data'] ?? null) && !isset($res['data']['value'])) {
            $row = $res['data'];
        }
        if ($row === null) {
            $res = sap_request('GET', "Items('{$escaped}')", null, $login, null);
            if ($res['ok'] && is_array($res['data'] ?? null) && !isset($res['data']['value'])) {
                $row = $res['data'];
            }
        }
        if ($row === null) {
            $res = sap_items_query_with_whs($login, "ItemCode eq '{$escaped}' or BarCode eq '{$escaped}'", 1);
            $row = $res['ok'] ? ($res['data']['value'][0] ?? null) : null;
        }
        sap_logout($cfg['sl_url'], $login['session_id'], $login['route_id'] ?? '');
        if (is_array($row)) {
            $row['unit_cost'] = sap_item_unit_cost($row);
            if ($whs !== '') {
                $whsQty = sap_whs_qty_from_item($row, $whs);
                if ($whsQty !== null) {
                    $row['system_qty'] = $whsQty;
                    $row['QuantityOnStock'] = $whsQty;
                    $row['warehouse'] = $whs;
                    $row['stock_scope'] = 'warehouse';
                } else {
                    $row['system_qty'] = (float)($row['QuantityOnStock'] ?? 0);
                    $row['stock_scope'] = 'company';
                }
            } else {
                $row['system_qty'] = (float)($row['QuantityOnStock'] ?? 0);
            }
        }
        json_response(['ok' => true, 'simulation' => false, 'data' => $row]);
    }

    if ($action === 'refresh_stock' && $method === 'POST') {
        $b = read_json_body();
        $whs = trim((string)($b['warehouse'] ?? ''));
        $codes = $b['item_codes'] ?? [];
        if (!is_array($codes) || count($codes) === 0) {
            json_error('item_codes es obligatorio');
        }
        $codes = array_values(array_unique(array_filter(array_map(static function ($c) {
            return trim((string)$c);
        }, $codes))));
        // Limitar para no saturar SL
        if (count($codes) > 200) {
            $codes = array_slice($codes, 0, 200);
        }

        $map = [];

        if (is_simulation_mode()) {
            foreach (sap_simulated_countings($whs !== '' ? $whs : null) as $doc) {
                foreach ($doc['InventoryCountingLines'] as $l) {
                    $code = (string)($l['ItemCode'] ?? '');
                    if ($code !== '' && in_array($code, $codes, true)) {
                        $map[$code] = [
                            'system_qty' => (float)($l['InWhsQty'] ?? 0),
                            'item_name' => (string)($l['ItemDescription'] ?? ''),
                            'barcode' => (string)($l['ItemCode'] ?? ''),
                            'unit_cost' => (float)($l['AvgStdPrice'] ?? $l['AvgPrice'] ?? $l['Price'] ?? 10),
                        ];
                    }
                }
            }
            foreach ($codes as $c) {
                if (!array_key_exists($c, $map)) {
                    $map[$c] = null;
                }
            }
            json_response(['ok' => true, 'simulation' => true, 'data' => $map]);
        }

        // Una sola sesión SAP + lotes (sin N llamadas por bodega = lento/timeout)
        $cfg = load_sap_settings();
        $login = sap_login($cfg['sl_url'], $cfg['company_db'], $cfg['sl_user'], $cfg['sl_password']);
        if (!$login['ok']) {
            json_error($login['error'] ?? 'No se pudo iniciar sesión en SAP', 502, $login);
        }

        $errors = [];

        foreach (array_chunk($codes, 25) as $chunk) {
            $parts = [];
            foreach ($chunk as $c) {
                $esc = str_replace("'", "''", $c);
                $parts[] = "ItemCode eq '{$esc}'";
            }
            $filter = implode(' or ', $parts);

            // Intento en lote; si no trae colección, se completa abajo ítem a ítem
            $res = sap_items_query_with_whs($login, $filter);
            if (!$res['ok']) {
                $errors[] = $res['error'] ?? 'Error Items';
                continue;
            }
            foreach (($res['data']['value'] ?? []) as $it) {
                $code = (string)($it['ItemCode'] ?? '');
                if ($code === '') {
                    continue;
                }
                $whsQty = $whs !== '' ? sap_whs_qty_from_item($it, $whs) : null;
                if ($whsQty === null) {
                    continue; // completar con GET ítem completo
                }
                $map[$code] = [
                    'system_qty' => $whsQty,
                    'item_name' => (string)($it['ItemName'] ?? ''),
                    'barcode' => (string)($it['BarCode'] ?? ''),
                    'unit_cost' => sap_item_unit_cost($it),
                    'warehouse' => $whs,
                    'stock_scope' => 'warehouse',
                ];
            }
        }

        $missing = [];
        foreach ($codes as $c) {
            if (!isset($map[$c])) {
                $missing[] = $c;
            }
        }
        if ($missing) {
            $extra = sap_resolve_warehouse_stock_map($login, $missing, $whs, 80);
            foreach ($extra as $code => $info) {
                $map[$code] = $info;
            }
        }

        sap_fill_missing_item_costs($login, $map);
        sap_logout($cfg['sl_url'], $login['session_id'], $login['route_id'] ?? '');

        foreach ($codes as $c) {
            if (!array_key_exists($c, $map)) {
                $map[$c] = null;
            }
        }

        if (!count(array_filter($map, static fn($v) => $v !== null)) && $errors) {
            json_error('No se pudo actualizar stock: ' . $errors[0], 502);
        }

        json_response([
            'ok' => true,
            'simulation' => false,
            'data' => $map,
            'updated' => count(array_filter($map, static fn($v) => $v !== null)),
            'warehouse' => $whs,
            'warnings' => $errors ?: null,
        ]);
    }

    if ($action === 'mode' && $method === 'GET') {
        json_response(['ok' => true, 'simulation_mode' => is_simulation_mode() ? 1 : 0]);
    }

    json_error('Acción no válida. Use warehouses|countings|counting|warehouse_stock|item_by_code|refresh_stock|mode', 404);
}
