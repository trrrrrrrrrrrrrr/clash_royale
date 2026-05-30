<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

// Получение всех заказов пользователя
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

// Получение данных пользователя
$stmtUser = $pdo->prepare("SELECT name, phone, email, login FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет | Клеш Рояль</title>
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #f5f5f5; }
        .profile-container {
            max-width: 1200px;
            margin: 40px auto;
            background: white;
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .user-info {
            background: #E8F5E9;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
        }
        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }
        .orders-table th, .orders-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .orders-table th {
            background: #4CAF50;
            color: white;
        }
        .edit-btn {
            background: #FF9800;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
        }
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
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: 0.3s;
        }
        .modal.active {
            visibility: visible;
            opacity: 1;
        }
        .modal-card {
            background: white;
            border-radius: 28px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            position: relative;
        }
        .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="profile-container">
    <h1>👤 Личный кабинет</h1>
    <div class="user-info">
        <p><strong>Логин:</strong> <?= htmlspecialchars($user['login']) ?></p>
        <p><strong>Имя:</strong> <?= htmlspecialchars($user['name']) ?></p>
        <p><strong>Телефон:</strong> <?= htmlspecialchars($user['phone']) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
        <a href="index.php" class="btn">← Вернуться на главную</a>
        <a href="logout.php" class="btn" style="background:#f44336;">Выйти</a>
    </div>

    <h2>Мои заказы</h2>
    <?php if (empty($orders)): ?>
        <p>У вас пока нет заказов. <a href="index.php#order-form">Сделать первый заказ</a></p>
    <?php else: ?>
        <table class="orders-table">
            <thead>
                <tr><th>ID</th><th>Товар</th><th>Кол-во</th><th>Доставка</th><th>Упаковка</th><th>Био</th><th>Сумма</th><th>Статус</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?= $order['id'] ?></td>
                    <td><?= ucfirst($order['product_type']) ?></td>
                    <td><?= $order['quantity'] ?></td>
                    <td><?= $order['delivery_cost'] ?> ₽</td>
                    <td><?= $order['gift_wrap'] ? '✅' : '❌' ?></td>
                    <td><?= $order['organic_cert'] ? '✅' : '❌' ?></td>
                    <td><?= $order['total_price'] ?> ₽</td>
                    <td><?= $order['status'] ?></td>
                    <td><button class="edit-btn" data-id="<?= $order['id'] ?>">✏️ Редактировать</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Модальное окно редактирования заказа -->
<div id="edit-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-edit">&times;</span>
        <h3>Редактирование заказа</h3>
        <form id="editForm">
            <input type="hidden" id="edit-order-id">
            <div class="form-group"><label>Продукт</label><select id="edit-product"></select></div>
            <div class="form-group"><label>Количество</label><input type="number" id="edit-quantity" min="1"></div>
            <div class="form-group"><label>Доставка</label><select id="edit-delivery"></select></div>
            <div class="form-group"><label><input type="checkbox" id="edit-gift"> Подарочная упаковка (+200 ₽)</label></div>
            <div class="form-group"><label><input type="checkbox" id="edit-organic"> Сертификат "Био" (+150 ₽)</label></div>
            <div class="form-group"><label>Пожелания</label><textarea id="edit-message" rows="3"></textarea></div>
            <button type="submit" class="btn">Сохранить изменения</button>
            <div id="edit-message-result" style="margin-top:10px;"></div>
        </form>
    </div>
</div>

<script>
const editModal = document.getElementById('edit-modal');
const closeEdit = document.getElementById('close-edit');
const editForm = document.getElementById('editForm');

if (closeEdit) closeEdit.onclick = () => editModal.classList.remove('active');
window.onclick = (e) => { if (e.target === editModal) editModal.classList.remove('active'); };

async function loadOrderForEdit(orderId) {
    try {
        const res = await fetch(`index.php?route=order&id=${orderId}`);
        const data = await res.json();
        if (data.id) {
            document.getElementById('edit-order-id').value = data.id;
            document.getElementById('edit-quantity').value = data.quantity;
            document.getElementById('edit-gift').checked = data.gift_wrap == 1;
            document.getElementById('edit-organic').checked = data.organic_cert == 1;
            document.getElementById('edit-message').value = data.message || '';

            // Заполнение select продукта
            const productSelect = document.getElementById('edit-product');
            const products = [
                {value:'vegetables', label:'Овощи', price:150},
                {value:'fruits', label:'Фрукты', price:300},
                {value:'milk', label:'Молочные продукты', price:200},
                {value:'honey', label:'Мёд', price:400},
                {value:'cheese', label:'Сыр', price:500}
            ];
            productSelect.innerHTML = '';
            products.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.value;
                opt.textContent = `${p.label} (${p.price} ₽)`;
                opt.dataset.price = p.price;
                if (p.value === data.product_type) opt.selected = true;
                productSelect.appendChild(opt);
            });

            const deliverySelect = document.getElementById('edit-delivery');
            deliverySelect.innerHTML = `
                <option value="0">Самовывоз (бесплатно)</option>
                <option value="300">По городу (300 ₽)</option>
                <option value="500">За город (500 ₽)</option>
            `;
            deliverySelect.value = data.delivery_cost;

            editModal.classList.add('active');
        }
    } catch(e) { alert('Ошибка загрузки заказа'); }
}

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => loadOrderForEdit(btn.dataset.id));
});

editForm.onsubmit = async (e) => {
    e.preventDefault();
    const id = document.getElementById('edit-order-id').value;
    const product = document.getElementById('edit-product').value;
    const quantity = parseInt(document.getElementById('edit-quantity').value);
    const delivery = parseInt(document.getElementById('edit-delivery').value);
    const gift = document.getElementById('edit-gift').checked;
    const organic = document.getElementById('edit-organic').checked;
    const message = document.getElementById('edit-message').value;

    // Получаем текущие контактные данные (можно добавить поля редактирования, но для простоты используем существующие)
    const name = <?= json_encode($user['name']) ?>;
    const phone = <?= json_encode($user['phone']) ?>;
    const email = <?= json_encode($user['email']) ?>;
    const consent = true; // уже дано при регистрации

    // Пересчёт стоимости
    const priceMap = {vegetables:150, fruits:300, milk:200, honey:400, cheese:500};
    const basePrice = priceMap[product];
    const total = (basePrice * quantity) + delivery + (gift?200:0) + (organic?150:0);

    const data = { name, phone, email, message, consent, product, quantity, delivery, gift, organic, total, _method:'PUT' };
    const res = await fetch(`index.php?route=order&id=${id}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    if (res.ok) {
        document.getElementById('edit-message-result').innerText = '✅ Заказ обновлён!';
        setTimeout(() => location.reload(), 1500);
    } else {
        document.getElementById('edit-message-result').innerText = '❌ Ошибка: ' + (result.error || 'неизвестная');
    }
};
</script>
</body>
</html>