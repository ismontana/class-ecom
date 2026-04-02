<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 1);

require_once "./config/EnvLoader.php";
EnvLoader::load(dirname(__DIR__) . '/.env');

require_once "./jwt.php";
require_once "./Database.php";
require_once "./Ecommerce.php";
require_once "./Shopify.php";
require_once "./TiendaNube.php";
require_once "./Claroshop.php";
require_once "./MercadoLibre.php";
require_once "./Utils.php";

# función para la sesión
function getSessionOrFail()
{
    $headers = getallheaders(); # obtenemos las cabeceras de la petición

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        print json_encode(['status' => false, 'error' => 'Token requerido']);
        return null;
    }

    $auth = str_replace('Bearer ', '', $headers['Authorization']); # obtenemos el token de la cabecera
    $jwt = new JWT(); # creamos una instancia de la clase JWT
    $payload = $jwt->decrypt($auth); # desencriptamos el token

    # validamos el payload del token
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
        'mypos_id' => (string)$payload['mypos_id'],
        'client_id' => (string)$payload['client_id']
    ];
}

#   <---    Router    --->>>

$session = getSessionOrFail(); # valida el token

# decodifca el playload JSON enviado por post
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    http_response_code(404);
    print json_encode(['status' => false, 'error' => 'Acción no especificada']);
    exit;
}

# inicializamos Ecommerce con las tiendas
$ecommerce = new Ecommerce([
    new Shopify(),
    new TiendaNube(),
    new MercadoLibre(),
    new Claroshop()
]);

$action    = $input['action'];
$data      = $input['data']      ?? [];
$platforms = $input['platforms'] ?? [];

# router dinamico
switch ($action) {

    # acciones de productos
    case 'getApiProducts':
        $result = $ecommerce->getApiProducts($platforms, $session);
        break;
    case 'getProducts':
        $result = $ecommerce->getProductsFromDb($session);
        break;
    case 'createProduct':
        $result = $ecommerce->createProduct($data, $session, $platforms);
        break;
    case 'updateProduct':
        // $result = $ecommerce->updateProduct($data, $session);
        break;
    case 'deleteProduct':
        // $result = $ecommerce->deleteProduct($data, $session);
        break;

    # acciones de pedidos
    case 'getApiOrders':
        $result = $ecommerce->getApiOrders($platforms, $session);
        break;
    case 'getOrders':
        $result = $ecommerce->getOrdersFromDb($session);
        break;
    case 'getOrderById':
        $id     = (int)($input['id'] ?? 0);
        $result = $ecommerce->getOrderById($id, $session);
        break;
    case 'linkOrder':
        $result = $ecommerce->linkOrder($data, $session, $platforms);
        break;
    case 'createOrder':
        $result = $ecommerce->createOrder($data, $session, $platforms);
        break;
    case 'updateOrder':
        $result = $ecommerce->updateOrder($data, $session);
        break;
    case 'cancelOrder':
        $result = $ecommerce->cancelOrder($data, $session);
        break;
    case 'pushOrders':
        $result = $ecommerce->pushOrders($session, $platforms);
        break;

    # acciones de clientes
    case 'getApiCustomers':
        $result = $ecommerce->getApiCustomers($platforms, $session);
        break;
    case 'getCustomers':
        $result = $ecommerce->getCustomersFromDb($session);
        break;
    case 'getCustomerById':
        $id     = (int)($input['id'] ?? 0);
        $result = $ecommerce->getCustomerById($id, $session);
        break;
    case 'createCustomer':
        $result = $ecommerce->createCustomer($data, $session, $platforms);
        break;
    case 'linkCustomer':
        $result = $ecommerce->linkCustomer($data, $session, $platforms);
        break;
    case 'updateCustomer':
        $result = $ecommerce->updateCustomer($data, $session);
        break;
    case 'pushCustomers':
        $result = $ecommerce->pushCustomers($session, $platforms);
        break;

    # acciones de direcciones
    case 'createAddress':
        $result = $ecommerce->createAddress($data, $session);
        break;
    case 'updateAddress':
        $result = $ecommerce->updateAddress($data, $session);
        break;

    # acciones de categorias
    case 'getCategories':
        // $result = $ecommerce->getCategories($session);
        break;
    case 'createCategory':
        // $result = $ecommerce->createCategory($data, $session);
        break;
    case 'updateCategory':
        // $result = $ecommerce->updateCategory($data, $session);
        break;
    case 'deleteCategory':
        // $result = $ecommerce->deleteCategory($data, $session);
        break;

    default:
        http_response_code(404);
        print json_encode(['status' => false, 'error' => 'Acción no encontrada']);
        exit;
}

print json_encode($result);