<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

function createDbConnection(): \PDO {
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        getenv('DB_CONNECTION') ?: 'mysql',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: 3306,
        getenv('DB_NAME') ?: 'rinha',
    );

    if (getenv('DB_CONNECTION') === 'mysql') {
        $dsn .= sprintf(";charset=%s", getenv('DB_CHARSET') ?: 'utf8mb4');
    }

    $username = getenv('DB_USER') ?: 'rinha';
    $password = getenv('DB_PASSWORD') ?: 'rinha';

    $connection = new \PDO($dsn, $username, $password, [
        \PDO::ATTR_PERSISTENT => true
    ]);
    $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $connection;
}

function getExtrato (Request $request, Response $response, \PDO $dbConnection) {
    $pathParts = explode('/', $request->server['request_uri']);
    $customerId = $pathParts[2];

    $now = new \DateTime();

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        $response->status(404);
        $response->end();

        return $response;
    }

    $customerLastTransactionsQuery = "
        SELECT valor, tipo, descricao, realizada_em
        FROM transacoes
        WHERE cliente_id = :customerId
        ORDER BY id DESC
        LIMIT 10
    ";

    $customerLastTransactionsStatement = $dbConnection->prepare($customerLastTransactionsQuery);
    $customerLastTransactionsStatement->bindParam(':customerId', $customerId);
    $customerLastTransactionsStatement->execute();

    $customerLastTransactionsResult = $customerLastTransactionsStatement->fetchAll(\PDO::FETCH_ASSOC);

    $response->status(200);
    $response->header('Content-Type', 'application/json');
    $response->write(json_encode([
        'saldo' => [
            'total' => intval($customerResult['saldo']),
            'data_extrato' => $now->format(\DateTime::ATOM),
            'limite' => intval($customerResult['limite']),
        ],
        'ultimas_transacoes' => $customerLastTransactionsResult
            ? array_map(function ($row) {
                return [
                    'valor' => intval($row['valor']),
                    'tipo' => $row['tipo'],
                    'descricao' => $row['descricao'],
                    'realizada_em' => (new \DateTime($row['realizada_em']))->format(\DateTime::ATOM),
                ];
            }, $customerLastTransactionsResult)
            : []
    ]));
    $response->end();

    return $response;
};

function createTransaction(Request $request, Response $response, \PDO $dbConnection) {
    $requestData = json_decode((string) $request->rawcontent(), true);
    if (! $requestData) {
        $response->status(404);
        $response->end();

        return $response;
    }

    $amount = $requestData['valor'];
    $type = $requestData['tipo'];
    $description = $requestData['descricao'];

    if (empty($amount) || empty($type) || empty($description)) {
        $response->status(422);
        $response->end();

        return $response;
    }

    if ($amount <= 0 || ! is_int($amount)) {
        $response->status(422);
        $response->end();

        return $response;
    }

    if (!in_array($type, ['c', 'd'])) {
        $response->status(422);
        $response->end();

        return $response;
    }

    $descriptionLength = strlen($description);
    if ($descriptionLength < 0 || $descriptionLength > 10) {
        $response->status(422);
        $response->end();

        return $response;
    }

    $pathParts = explode('/', $request->server['request_uri']);
    $customerId = $pathParts[2];

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        $response->status(404);
        $response->end();

        return $response;
    }

    $createTransactionQuery = "
        CALL create_transaction(:customerId, :amount, :type, :description)
    ";

    $createTransactionStatement = $dbConnection->prepare($createTransactionQuery);
    $createTransactionStatement->bindParam(':customerId', $customerId, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':amount', $amount, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':type', $type, \PDO::PARAM_STR);
    $createTransactionStatement->bindParam(':description', $description, \PDO::PARAM_STR);

    try {
        $createTransactionStatement->execute();
    } catch (\PDOException $e) {
        $response->status(422);
        $response->end();

        return $response;
    }

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);

    $response->status(200);
    $response->header('Content-Type', 'application/json');
    $response->write(json_encode([
        'limite' => intval($customerResult['limite']),
        'saldo' => intval($customerResult['saldo'])
    ]));
    $response->end();

    return $response;
};

$dbConnection = createDbConnection();

$server = new Server('0.0.0.0', 8080);

$server->on('request', static function (Request $swooleRequest, Response $swooleResponse) use ($dbConnection) {
    $method = $swooleRequest->server['request_method'];
    $path = $swooleRequest->server['request_uri'];

    if ($method === 'POST' && preg_match('/\/clientes\/\d+\/transacoes/', $path)) {
        createTransaction($swooleRequest, $swooleResponse, $dbConnection);
    } elseif ($method === 'GET' && preg_match('/\/clientes\/\d+\/extrato/', $path)) {
        getExtrato($swooleRequest, $swooleResponse, $dbConnection);
    } else {
        $swooleResponse->status(404);
        $swooleResponse->end('Not Found');
    }
});

$server->on('start', static function () {
    echo "Server running at 8080" . PHP_EOL;
});

$server->start();
