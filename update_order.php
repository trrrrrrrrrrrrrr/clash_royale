<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) {
    die('Ошибка: не указан ID заказа');
}

// Проверяем, что заказ принадлежит пользователю и имеет статус 'new'
$stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch();
if (!$order || $order['status'] !== 'new') {
    die('Этот заказ нельзя редактировать (не найден или уже обработан)');
}

// Получаем данные из формы
$product = $_POST['product'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 0);
$delivery = (int)($_POST['delivery'] ?? 0);
$gift = isset($_POST['gift']) ? 1 : 0;
$organic = isset($_POST['organic']) ? 1 : 0;
$message = trim($_POST['message'] ?? '');

// Валидация
$errors = [];
if (empty($product)) $errors[] = 'Выберите продукт';
if ($quantity < 1) $errors[] = 'Количество должно быть не менее 1';
if (!empty($errors)) {
    die(implode(', ', $errors));
}

// Пересчёт стоимости
$priceMap = [
    'vegetables' => 150,
    'fruits' => 300,
    'milk' => 200,
    'honey' => 400,
    'cheese' => 500
];
$basePrice = $priceMap[$product] ?? 0;
$total = ($basePrice * $quantity) + $delivery + ($gift ? 200 : 0) + ($organic ? 150 : 0);

// Обновляем заказ
$stmt = $pdo->prepare("
    UPDATE orders 
    SET product_type = ?, quantity = ?, delivery_cost = ?, gift_wrap = ?, 
        organic_cert = ?, total_price = ?, message = ?
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$product, $quantity, $delivery, $gift, $organic, $total, $message, $orderId, $userId]);

if ($stmt->rowCount()) {
    header('Location: profile.php?updated=1');
} else {
    die('Ошибка обновления: заказ не изменён');
}
?>