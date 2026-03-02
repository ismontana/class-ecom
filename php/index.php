<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);

require_once "./Router.php";
require_once "./Ecommerce.php";
require_once "./TiendaNube.php";
require_once "./Amazon.php";
require_once "./Shopify.php";
require_once "./Database.php";
require_once "./jwt.php";

function getSessionOrFail(): ?array {
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        print json_encode(['status' => false, 'error' => 'Token requerido']);
        return null;
    }

    $auth = str_replace('Bearer ', '', $headers['Authorization']);
    $jwt = new JWT();
    $payload = $jwt->decrypt($auth);

    if (
        !$payload ||
        !isset($payload['mypos_id']) ||
        !isset($payload['client_id'])
    ) {
        http_response_code(401);
        print json_encode(['status' => false, 'error' => 'Token inválido']);
        return null;
    }

    return [
        'mypos_id'  => (string)$payload['mypos_id'],
        'client_id' => (string)$payload['client_id'],
    ];
}

$router = new Router();

# Sincronizar todas las plataformas manual

$router->post('/sync', function () {
    $session = getSessionOrFail();
    if (!$session) return;

    $tn = new TiendaNube($session);
    $sh = new Shopify($session);

    $tnResult = $tn->sync();
    $shResult = $sh->sync();

    print json_encode([
        'status' => 'success',
        'code' => '200',
        'answer' => 'Sincronización de plataformas completada',
        'data' => [
            'tiendanube' => $tnResult,
            'shopify' => $shResult
        ]
    ], JSON_UNESCAPED_UNICODE);
});

# Agregar productos
$router->post('/addItems', function () {
    $session = getSessionOrFail();
    if (!$session) return;

    $input = json_decode(file_get_contents("php://input"), true);

    if (empty($input['plataformas'])) {
        print json_encode(['error' => 'Debe especificar plataformas']);
        return;
    }

    $results = [];

    foreach ($input['plataformas'] as $platform) {

        switch ($platform) {
            case 'tiendanube':
                $instance = new TiendaNube($session);
                break;

            case 'shopify':
                $instance = new Shopify($session);
                break;

            default:
                continue 2;
        }

        $results[$platform] = $instance->addItem($input);
    }

    print json_encode($results, JSON_UNESCAPED_UNICODE);
});

# Editar productos
$router->put('/editItems', function () {
    $session = getSessionOrFail();
    if (!$session) return;

    $input = json_decode(file_get_contents("php://input"), true);

    if (empty($input['plataforma']) || empty($input['id_externo'])) {
        print json_encode(['error' => 'plataforma e id_externo requeridos']);
        return;
    }

    switch ($input['plataforma']) {
        case 'tiendanube':
            $instance = new TiendaNube($session);
            break;

        case 'shopify':
            $instance = new Shopify($session);
            break;

        default:
            print json_encode(['error' => 'Plataforma inválida']);
            return;
    }

    $result = $instance->editItem($input['id_externo'], $input);

    print json_encode($result, JSON_UNESCAPED_UNICODE);
});

# Agregar clientes
$router->post('/addCustomer', function () {

    $session = getSessionOrFail();
    if (!$session) return;

    $input = json_decode(file_get_contents("php://input"), true);

    $results = [];

    foreach ($input['plataformas'] ?? [] as $platform) {

        switch ($platform) {
            case 'tiendanube':
                $instance = new TiendaNube($session);
                break;

            case 'shopify':
                $instance = new Shopify($session);
                break;

            default:
                continue 2;
        }

        $results[$platform] = $instance->addCustomer($input);
    }

    print json_encode($results, JSON_UNESCAPED_UNICODE);
});

# Editar clientes
$router->put('/editCustomer', function () {

    $session = getSessionOrFail();
    if (!$session) return;

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        print json_encode(['error' => 'JSON inválido']);
        return;
    }

    if (empty($input['plataforma']) || empty($input['id_externo'])) {
        print json_encode(['error' => 'plataforma e id_externo requeridos']);
        return;
    }

    switch ($input['plataforma']) {
        case 'tiendanube':
            $instance = new TiendaNube($session);
            break;

        case 'shopify':
            $instance = new Shopify($session);
            break;

        default:
            print json_encode(['error' => 'Plataforma inválida']);
            return;
    }

    $result = $instance->editCustomer($input['id_externo'], $input);

    print json_encode($result, JSON_UNESCAPED_UNICODE);
});

$router->route();
