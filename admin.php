<?php
session_start();
require_once 'db.php';

// HTTP Basic Auth
$auth_login = $_SERVER['PHP_AUTH_USER'] ?? '';
$auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (empty($auth_login) || empty($auth_pass)) {
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1>Доступ запрещён</h1><p>Введите логин и пароль администратора.</p>';
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT password_hash FROM admin WHERE login = ?");
$stmt->execute([$auth_login]);
$admin = $stmt->fetch();
if (!$admin || !password_verify($auth_pass, $admin['password_hash'])) {
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1>Неверный логин или пароль!</h1>';
    exit;
}

// --- Обработка действий ---
$message = '';

// Удаление пользователя (каскадно удалит заказы)
if (isset($_GET['delete_user'])) {
    $userId = (int)$_GET['delete_user'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id = ?)")->execute([$userId]);
        $pdo->prepare("DELETE FROM orders WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        $pdo->commit();
        $message = "<div class='success'>Пользователь #{$userId} удалён.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='error'>Ошибка удаления: {$e->getMessage()}</div>";
    }
}

// Удаление всех отменённых заказов
if (isset($_POST['delete_all_cancelled'])) {
    try {
        $pdo->beginTransaction();
        // Находим ID заказов со статусом 'cancelled'
        $stmt = $pdo->query("SELECT id FROM orders WHERE status = 'cancelled'");
        $cancelledIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($cancelledIds)) {
            $placeholders = implode(',', array_fill(0, count($cancelledIds), '?'));
            $pdo->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)")->execute($cancelledIds);
            $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)")->execute($cancelledIds);
            $message = "<div class='success'>Удалено " . count($cancelledIds) . " отменённых заказов.</div>";
        } else {
            $message = "<div class='info'>Нет отменённых заказов.</div>";
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='error'>Ошибка: {$e->getMessage()}</div>";
    }
}

// Обновление пользователя (имя, телефон, email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $userId = (int)$_POST['user_id'];
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (!empty($name) && !empty($phone) && !empty($email)) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?");
        $stmt->execute([$name, $phone, $email, $userId]);
        $message = "<div class='success'>Пользователь обновлён.</div>";
    } else {
        $message = "<div class='error'>Заполните все поля.</div>";
    }
}

// Обновление заказа (продукт, количество, доставка, опции, статус)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_order'])) {
    $orderId = (int)$_POST['order_id'];
    $product = $_POST['product'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $delivery = (int)($_POST['delivery'] ?? 0);
    $gift = isset($_POST['gift']) ? 1 : 0;
    $organic = isset($_POST['organic']) ? 1 : 0;
    $status = $_POST['status'] ?? 'new';
    $priceMap = ['vegetables'=>150, 'fruits'=>300, 'milk'=>200, 'honey'=>400, 'cheese'=>500];
    $basePrice = $priceMap[$product] ?? 0;
    $total = ($basePrice * $quantity) + $delivery + ($gift?200:0) + ($organic?150:0);
    $stmt = $pdo->prepare("UPDATE orders SET product_type=?, quantity=?, delivery_cost=?, gift_wrap=?, organic_cert=?, total_price=?, status=? WHERE id=?");
    $stmt->execute([$product, $quantity, $delivery, $gift, $organic, $total, $status, $orderId]);
    $message = "<div class='success'>Заказ #{$orderId} обновлён.</div>";
}

// --- Получение данных ---
// Все пользователи
$users = $pdo->query("SELECT id, name, phone, email, login FROM users ORDER BY id DESC")->fetchAll();

// Все заказы (с привязкой к пользователям)
$allOrders = $pdo->query("
    SELECT o.*, u.name as user_name, u.email as user_email 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    ORDER BY o.created_at DESC
")->fetchAll();

// Отменённые заказы
$cancelledOrders = $pdo->query("
    SELECT o.*, u.name as user_name, u.email as user_email 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    WHERE o.status = 'cancelled'
    ORDER BY o.created_at DESC
")->fetchAll();

// Список продуктов для выпадающих списков
$productsList = [
    'vegetables' => 'Овощи',
    'fruits' => 'Фрукты',
    'milk' => 'Молочные продукты',
    'honey' => 'Мёд',
    'cheese' => 'Сыр'
];
$statuses = ['new' => 'Новый', 'processed' => 'В обработке', 'completed' => 'Выполнен', 'cancelled' => 'Отменён'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление | Клеш Рояль</title>
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Nunito', sans-serif; background: #f5f5f5; padding: 30px; }
        .admin-container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 28px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        h1 { color: #4CAF50; margin-bottom: 10px; }
        .tabs { display: flex; gap: 15px; margin: 20px 0; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; }
        .tab-btn { background: none; border: none; padding: 10px 30px; font-size: 1.1rem; cursor: pointer; border-radius: 40px; transition: 0.2s; font-weight: 600; }
        .tab-btn.active { background: #4CAF50; color: white; }
        .tab-content { display: none; margin-top: 20px; }
        .tab-content.active { display: block; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: top; }
        th { background: #4CAF50; color: white; }
        .btn { background: #4CAF50; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; margin: 2px; }
        .btn-danger { background: #f44336; }
        .btn-warning { background: #ff9800; }
        .edit-form { background: #f9f9f9; padding: 15px; margin-top: 15px; border-radius: 16px; }
        .form-group { margin-bottom: 10px; }
        .form-group label { display: inline-block; width: 100px; font-weight: 600; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="admin-container">
    <h1>Управление</h1>
    <p>Авторизован как <strong><?= htmlspecialchars($auth_login) ?></strong> | <a href="index.php">На сайт</a></p>
    <?= $message ?>

    <div class="tabs">
        <button class="tab-btn active" data-tab="users">👥 Пользователи</button>
        <button class="tab-btn" data-tab="all-orders">📦 Все заказы</button>
        <button class="tab-btn" data-tab="cancelled">🗑️ Отменённые заказы</button>
    </div>

    <!-- Вкладка: Пользователи -->
    <div id="tab-users" class="tab-content active">
        <h2>Редактирование пользователей</h2>
        <table>
            <thead><tr><th>ID</th><th>Логин</th><th>Имя</th><th>Телефон</th><th>Email</th><th>Действия</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td><?= htmlspecialchars($user['name']) ?></td>
                    <td><?= htmlspecialchars($user['phone']) ?></td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td>
                        <button class="btn edit-order-btn" 
                        data-id="<?= $order['id'] ?>"
                        data-product="<?= $order['product_type'] ?>"
                        data-quantity="<?= $order['quantity'] ?>"
                        data-delivery="<?= $order['delivery_cost'] ?>"
                        data-gift="<?= $order['gift_wrap'] ?>"
                        data-organic="<?= $order['organic_cert'] ?>"
                        data-status="<?= $order['status'] ?>"> Редактировать</button>
                        <a href="?delete_user=<?= $user['id'] ?>" class="btn btn-danger" onclick="return confirm('Удалить пользователя №<?= $user['id'] ?> и все его заказы?')"> Удалить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="edit-user-form" class="edit-form" style="display:none;">
            <h3>Редактирование пользователя</h3>
            <form method="post">
                <input type="hidden" name="user_id" id="edit-user-id">
                <div class="form-group"><label>Имя</label><input type="text" name="name" id="edit-user-name" required></div>
                <div class="form-group"><label>Телефон</label><input type="text" name="phone" id="edit-user-phone" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-user-email" required></div>
                <button type="submit" name="edit_user" class="btn">Сохранить</button>
                <button type="button" id="cancel-user-edit" class="btn">Отмена</button>
            </form>
        </div>
    </div>

    <!-- Вкладка: Все заказы -->
    <div id="tab-all-orders" class="tab-content">
        <h2>Все заказы</h2>
        <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Пользователь</th><th>Товар</th><th>Кол-во</th><th>Доставка</th><th>Упак.</th><th>Био</th><th>Сумма</th><th>Статус</th><th>Действия</th></tr></thead>
                <tbody>
                <?php foreach ($allOrders as $order):
                    $productName = $productsList[$order['product_type']] ?? $order['product_type'];
                ?>
                <tr>
                    <td><?= $order['id'] ?></td>
                    <td><?= htmlspecialchars($order['user_name']) ?></td>
                    <td><?= $productName ?></td>
                    <td><?= $order['quantity'] ?></td>
                    <td><?= $order['delivery_cost'] ?> ₽</td>
                    <td><?= $order['gift_wrap'] ? '✅' : '❌' ?></td>
                    <td><?= $order['organic_cert'] ? '✅' : '❌' ?></td>
                    <td><?= $order['total_price'] ?> ₽</td>
                    <td><?= $statuses[$order['status']] ?></td>
                    <td><button class="btn edit-order-btn" data-id="<?= $order['id'] ?>">✏️ Ред.</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="edit-order-form" class="edit-form" style="display:none;">
            <h3>Редактирование заказа</h3>
            <form method="post">
                <input type="hidden" name="order_id" id="edit-order-id">
                <div class="form-group"><label>Продукт</label><select name="product" id="edit-order-product"></select></div>
                <div class="form-group"><label>Количество</label><input type="number" name="quantity" id="edit-order-quantity" min="1"></div>
                <div class="form-group"><label>Доставка</label><select name="delivery" id="edit-order-delivery"><option value="0">0</option><option value="300">300</option><option value="500">500</option></select></div>
                <div class="form-group"><label>Упаковка</label><input type="checkbox" name="gift" id="edit-order-gift"></div>
                <div class="form-group"><label>Био</label><input type="checkbox" name="organic" id="edit-order-organic"></div>
                <div class="form-group"><label>Статус</label><select name="status" id="edit-order-status"></select></div>
                <button type="submit" name="edit_order" class="btn">Сохранить</button>
                <button type="button" id="cancel-order-edit" class="btn">Отмена</button>
            </form>
        </div>
    </div>

    <!-- Вкладка: Отменённые заказы -->
    <div id="tab-cancelled" class="tab-content">
        <h2>Отменённые заказы</h2>
        <?php if (count($cancelledOrders) > 0): ?>
            <form method="post" onsubmit="return confirm('Удалить ВСЕ отменённые заказы? Это действие нельзя отменить.');">
                <button type="submit" name="delete_all_cancelled" class="btn btn-danger">🗑️ Удалить все отменённые заказы</button>
            </form>
            <div style="overflow-x: auto; margin-top: 20px;">
                <table>
                    <thead><tr><th>ID</th><th>Пользователь</th><th>Товар</th><th>Кол-во</th><th>Сумма</th><th>Статус</th></tr></thead>
                    <tbody>
                    <?php foreach ($cancelledOrders as $order):
                        $productName = $productsList[$order['product_type']] ?? $order['product_type'];
                    ?>
                    <tr>
                        <td><?= $order['id'] ?></td>
                        <td><?= htmlspecialchars($order['user_name']) ?></td>
                        <td><?= $productName ?></td>
                        <td><?= $order['quantity'] ?></td>
                        <td><?= $order['total_price'] ?> ₽</td>
                        <td>Отменён</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Нет отменённых заказов.</p>
        <?php endif; ?>
    </div>
</div>

<script>
    // Переключение вкладок
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');
    tabs.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;
            contents.forEach(content => content.classList.remove('active'));
            tabs.forEach(b => b.classList.remove('active'));
            document.getElementById(`tab-${tabId}`).classList.add('active');
            btn.classList.add('active');
        });
    });

    // Редактирование пользователя
    const editUserBtns = document.querySelectorAll('.edit-user-btn');
    const editUserForm = document.getElementById('edit-user-form');
    const cancelUserEdit = document.getElementById('cancel-user-edit');
    editUserBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('edit-user-id').value = btn.dataset.id;
            document.getElementById('edit-user-name').value = btn.dataset.name;
            document.getElementById('edit-user-phone').value = btn.dataset.phone;
            document.getElementById('edit-user-email').value = btn.dataset.email;
            editUserForm.style.display = 'block';
        });
    });
    if (cancelUserEdit) cancelUserEdit.addEventListener('click', () => editUserForm.style.display = 'none');

    // Редактирование заказа (динамическое заполнение)
    const editOrderBtns = document.querySelectorAll('.edit-order-btn');
    const editOrderForm = document.getElementById('edit-order-form');
    const cancelOrderEdit = document.getElementById('cancel-order-edit');
    const orderProductSelect = document.getElementById('edit-order-product');
    const orderStatusSelect = document.getElementById('edit-order-status');

    // Заполнение select продуктами и статусами
    const products = <?= json_encode($productsList) ?>;
    const statuses = <?= json_encode($statuses) ?>;
    for (const [val, label] of Object.entries(products)) {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = label;
        orderProductSelect.appendChild(opt);
    }
    for (const [val, label] of Object.entries(statuses)) {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = label;
        orderStatusSelect.appendChild(opt);
    }

    editOrderBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('edit-order-id').value = btn.dataset.id;
        orderProductSelect.value = btn.dataset.product;
        document.getElementById('edit-order-quantity').value = btn.dataset.quantity;
        document.getElementById('edit-order-delivery').value = btn.dataset.delivery;
        document.getElementById('edit-order-gift').checked = btn.dataset.gift == '1';
        document.getElementById('edit-order-organic').checked = btn.dataset.organic == '1';
        orderStatusSelect.value = btn.dataset.status;
        editOrderForm.style.display = 'block';
    });
});
    if (cancelOrderEdit) cancelOrderEdit.addEventListener('click', () => editOrderForm.style.display = 'none');
</script>
</body>
</html>