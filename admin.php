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

// Обработка параметров после редактирования пользователя
if (isset($_GET['user_updated'])) {
    $message = "<div class='success'>Пользователь обновлён.</div>";
}
if (isset($_GET['edit_user_errors'])) {
    $errors = explode('|', $_GET['errors'] ?? '');
    $errorHtml = '<ul>';
    foreach ($errors as $err) {
        $errorHtml .= '<li>' . htmlspecialchars($err) . '</li>';
    }
    $errorHtml .= '</ul>';
    $message = "<div class='error'>$errorHtml</div>";
    // Сохраняем данные для повторного заполнения формы (через сессию или скрытые поля)
    $_SESSION['edit_user_data'] = [
        'id' => $_GET['user_id'] ?? 0,
        'name' => $_GET['name'] ?? '',
        'phone' => $_GET['phone'] ?? '',
        'email' => $_GET['email'] ?? ''
    ];
}

// Удаление пользователя
if (isset($_GET['delete_user'])) {
    $userId = (int)$_GET['delete_user'];
    try {
        $pdo->beginTransaction();
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
        $stmt = $pdo->prepare("DELETE FROM orders WHERE status = 'cancelled'");
        $stmt->execute();
        $count = $stmt->rowCount();
        $pdo->commit();
        $message = "<div class='success'>Удалено {$count} отменённых заказов.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='error'>Ошибка: {$e->getMessage()}</div>";
    }
}

// Обновление пользователя с валидацией
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $userId = (int)$_POST['user_id'];
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    $errors = [];
    if (empty($name)) {
        $errors[] = 'Имя обязательно.';
    } elseif (!preg_match('/^[а-яА-Яa-zA-Z\s]+$/u', $name)) {
        $errors[] = 'Имя должно содержать только буквы и пробелы.';
    } elseif (strlen($name) > 150) {
        $errors[] = 'Имя не должно превышать 150 символов.';
    }
    
    if (empty($phone)) {
        $errors[] = 'Телефон обязателен.';
    } else {
        $digits = preg_replace('/\D/', '', $phone);
        $digitCount = strlen($digits);
        if ($digitCount < 10 || $digitCount > 12) {
            $errors[] = 'Номер телефона должен содержать от 10 до 12 цифр.';
        }
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный email.';
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?");
        $stmt->execute([$name, $phone, $email, $userId]);
        header('Location: admin.php?user_updated=1');
        exit;
    } else {
        $error_string = implode('|', $errors);
        header("Location: admin.php?edit_user_errors=1&user_id=$userId&name=".urlencode($name)."&phone=".urlencode($phone)."&email=".urlencode($email)."&errors=$error_string");
        exit;
    }
}

// Обновление заказа (без изменений)
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
$users = $pdo->query("SELECT id, name, phone, email, login FROM users ORDER BY id DESC")->fetchAll();
$allOrders = $pdo->query("
    SELECT o.*, u.name as user_name, u.email as user_email 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    ORDER BY o.created_at DESC
")->fetchAll();
$cancelledOrders = $pdo->query("
    SELECT o.*, u.name as user_name, u.email as user_email 
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    WHERE o.status = 'cancelled'
    ORDER BY o.created_at DESC
")->fetchAll();

$productsList = [
    'vegetables' => 'Овощи',
    'fruits' => 'Фрукты',
    'milk' => 'Молочные продукты',
    'honey' => 'Мёд',
    'cheese' => 'Сыр'
];
$statuses = ['new' => 'Новый', 'processed' => 'В обработке', 'completed' => 'Выполнен', 'cancelled' => 'Отменён'];

// Если есть сохранённые ошибки редактирования, передаём данные в модалку
$editUserData = isset($_SESSION['edit_user_data']) ? $_SESSION['edit_user_data'] : null;
unset($_SESSION['edit_user_data']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление | Клеш Рояль</title>
        <link rel="icon" href="https://img.icons8.com/color/96/000000/crab.png" type="image/x-icon">
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
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 10px; margin-bottom: 15px; }
        
        /* Модальные окна */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .modal.active {
            visibility: visible;
            opacity: 1;
        }
        .modal-card {
            background: white;
            border-radius: 28px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            position: relative;
            text-align: left;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        .modal-card .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
            color: #888;
        }
        .modal-card .close:hover {
            color: #f44336;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: inline-block;
            width: 100px;
            font-weight: 600;
        }
        .form-group input, .form-group select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            width: calc(100% - 110px);
        }
        .form-group.checkbox label {
            width: auto;
        }
        .form-group.checkbox input {
            width: auto;
            margin-left: 10px;
        }
        .error-message {
            color: #f44336;
            font-size: 0.85rem;
            margin-top: 5px;
            display: inline-block;
            margin-left: 100px;
        }
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
                        <button class="btn edit-user-btn" 
                            data-id="<?= $user['id'] ?>"
                            data-name="<?= htmlspecialchars($user['name']) ?>"
                            data-phone="<?= htmlspecialchars($user['phone']) ?>"
                            data-email="<?= htmlspecialchars($user['email']) ?>"> Редактировать</button>
                        <a href="?delete_user=<?= $user['id'] ?>" class="btn btn-danger" onclick="return confirm('Удалить пользователя №<?= $user['id'] ?> и все его заказы?')"> Удалить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                    <td>
                        <button class="btn edit-order-btn" 
                            data-id="<?= $order['id'] ?>"
                            data-product="<?= $order['product_type'] ?>"
                            data-quantity="<?= $order['quantity'] ?>"
                            data-delivery="<?= $order['delivery_cost'] ?>"
                            data-gift="<?= $order['gift_wrap'] ?>"
                            data-organic="<?= $order['organic_cert'] ?>"
                            data-status="<?= $order['status'] ?>"> Редактировать</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Вкладка: Отменённые заказы -->
    <div id="tab-cancelled" class="tab-content">
        <h2>Отменённые заказы</h2>
        <?php if (count($cancelledOrders) > 0): ?>
            <form method="post" onsubmit="return confirm('Удалить ВСЕ отменённые заказы? Это действие нельзя отменить.');">
                <button type="submit" name="delete_all_cancelled" class="btn btn-danger"> Удалить все отменённые заказы</button>
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

<!-- Модальное окно редактирования пользователя -->
<div id="edit-user-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-user-modal">&times;</span>
        <h3>Редактирование пользователя</h3>
        <form method="post">
            <input type="hidden" name="user_id" id="user-id" value="<?= $editUserData['id'] ?? '' ?>">
            <div class="form-group">
                <label>Имя</label>
                <input type="text" name="name" id="user-name" value="<?= htmlspecialchars($editUserData['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Телефон</label>
                <input type="text" name="phone" id="user-phone" value="<?= htmlspecialchars($editUserData['phone'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" id="user-email" value="<?= htmlspecialchars($editUserData['email'] ?? '') ?>" required>
            </div>
            <button type="submit" name="edit_user" class="btn">Сохранить</button>
            <button type="button" id="cancel-user-modal" class="btn">Отмена</button>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования заказа -->
<div id="edit-order-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-order-modal">&times;</span>
        <h3>Редактирование заказа</h3>
        <form method="post">
            <input type="hidden" name="order_id" id="order-id">
            <div class="form-group"><label>Продукт</label><select name="product" id="order-product"></select></div>
            <div class="form-group"><label>Количество</label><input type="number" name="quantity" id="order-quantity" min="1"></div>
            <div class="form-group"><label>Доставка</label><select name="delivery" id="order-delivery"><option value="0">0</option><option value="300">300</option><option value="500">500</option></select></div>
            <div class="form-group checkbox"><label>Упаковка</label><input type="checkbox" name="gift" id="order-gift"></div>
            <div class="form-group checkbox"><label>Био</label><input type="checkbox" name="organic" id="order-organic"></div>
            <div class="form-group"><label>Статус</label><select name="status" id="order-status"></select></div>
            <button type="submit" name="edit_order" class="btn">Сохранить</button>
            <button type="button" id="cancel-order-modal" class="btn">Отмена</button>
        </form>
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

    // Модальное окно пользователя
    const userModal = document.getElementById('edit-user-modal');
    const closeUserModal = document.getElementById('close-user-modal');
    const cancelUserModal = document.getElementById('cancel-user-modal');
    function openUserModal(id, name, phone, email) {
        document.getElementById('user-id').value = id;
        document.getElementById('user-name').value = name;
        document.getElementById('user-phone').value = phone;
        document.getElementById('user-email').value = email;
        userModal.classList.add('active');
    }
    function closeUserModalFunc() { userModal.classList.remove('active'); }
    if (closeUserModal) closeUserModal.onclick = closeUserModalFunc;
    if (cancelUserModal) cancelUserModal.onclick = closeUserModalFunc;
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            openUserModal(btn.dataset.id, btn.dataset.name, btn.dataset.phone, btn.dataset.email);
        });
    });
    // Если были ошибки редактирования, автоматически открываем модалку
    <?php if ($editUserData && isset($_GET['edit_user_errors'])): ?>
        window.addEventListener('load', () => {
            openUserModal(<?= json_encode($editUserData['id']) ?>, <?= json_encode($editUserData['name']) ?>, <?= json_encode($editUserData['phone']) ?>, <?= json_encode($editUserData['email']) ?>);
        });
    <?php endif; ?>

    // Модальное окно заказа
    const orderModal = document.getElementById('edit-order-modal');
    const closeOrderModal = document.getElementById('close-order-modal');
    const cancelOrderModal = document.getElementById('cancel-order-modal');
    function closeOrderModalFunc() { orderModal.classList.remove('active'); }
    if (closeOrderModal) closeOrderModal.onclick = closeOrderModalFunc;
    if (cancelOrderModal) cancelOrderModal.onclick = closeOrderModalFunc;

    // Заполнение select продуктами и статусами
    const orderProductSelect = document.getElementById('order-product');
    const orderStatusSelect = document.getElementById('order-status');
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

    document.querySelectorAll('.edit-order-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('order-id').value = btn.dataset.id;
            orderProductSelect.value = btn.dataset.product;
            document.getElementById('order-quantity').value = btn.dataset.quantity;
            document.getElementById('order-delivery').value = btn.dataset.delivery;
            document.getElementById('order-gift').checked = btn.dataset.gift == '1';
            document.getElementById('order-organic').checked = btn.dataset.organic == '1';
            orderStatusSelect.value = btn.dataset.status;
            orderModal.classList.add('active');
        });
    });
</script>
</body>
</html>