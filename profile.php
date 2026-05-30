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
        body { background: #f5f5f5; font-family: 'Nunito', sans-serif; }
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
            vertical-align: middle;
        }
        .orders-table th {
            background: #4CAF50;
            color: white;
        }
        .edit-btn, .cancel-btn {
            background: #FF9800;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-right: 5px;
        }
        .cancel-btn {
            background: #f44336;
        }
        .edit-btn:hover {
            background: #F57C00;
        }
        .cancel-btn:hover {
            background: #d32f2f;
        }
        /* Модальное окно */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
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
            max-width: 600px;
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
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4CAF50;
            outline: none;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.2);
        }
        .btn {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .back-link {
            margin-top: 20px;
            text-align: center;
        }
        .logout-btn {
            background: #f44336;
            margin-left: 10px;
        }
        .logout-btn:hover {
            background: #d32f2f;
        }
        .toggle-group {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .toggle-btn {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 8px 20px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: normal;
            transition: 0.2s;
        }
        .toggle-btn.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
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
        <div style="margin-top: 15px;">
            <a href="index.php" class="btn">← Вернуться на главную</a>
            <button id="logout-btn" class="btn logout-btn">Выйти</button>
        </div>
    </div>

    <h2>Мои заказы</h2>
    <?php if (empty($orders)): ?>
        <p>У вас пока нет заказов. <a href="index.php#order-form">Сделать первый заказ</a></p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>ID</th><th>Товар</th><th>Кол-во</th><th>Доставка</th><th>Упаковка</th><th>Био</th><th>Сумма</th><th>Статус</th><th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $productName = match($order['product_type']) {
                            'vegetables' => 'Овощи',
                            'fruits' => 'Фрукты',
                            'milk' => 'Молочные продукты',
                            'honey' => 'Мёд',
                            'cheese' => 'Сыр',
                            default => $order['product_type']
                        };
                        $statusName = match($order['status']) {
                            'new' => 'Новый',
                            'processed' => 'В обработке',
                            'completed' => 'Выполнен',
                            'cancelled' => 'Отменён',
                            default => $order['status']
                        };
                    ?>
                    <tr>
                        <td><?= $order['id'] ?></td>
                        <td><?= $productName ?></td>
                        <td><?= $order['quantity'] ?></td>
                        <td><?= $order['delivery_cost'] ?> ₽</td>
                        <td><?= $order['gift_wrap'] ? '✅' : '❌' ?></td>
                        <td><?= $order['organic_cert'] ? '✅' : '❌' ?></td>
                        <td><?= $order['total_price'] ?> ₽</td>
                        <td><?= $statusName ?></td>
                        <td>
                            <button class="edit-btn" data-id="<?= $order['id'] ?>">✏️ Редактировать</button>
                            <?php if ($order['status'] === 'new'): ?>
                                <button class="cancel-btn" data-id="<?= $order['id'] ?>">❌ Отменить</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно редактирования заказа -->
<div id="edit-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-edit">&times;</span>
        <h3>Редактирование заказа</h3>
        <form id="editForm">
            <input type="hidden" id="edit-order-id">
            <div class="form-group">
                <label>Продукт</label>
                <select id="edit-product"></select>
            </div>
            <div class="form-group">
                <label>Количество</label>
                <input type="number" id="edit-quantity" min="1" max="100" required>
            </div>
            <div class="form-group">
                <label>Доставка</label>
                <select id="edit-delivery"></select>
            </div>
            <div class="form-group">
                <label>Пожелания</label>
                <textarea id="edit-message" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label>Подарочная упаковка (+200 ₽)</label>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn" data-opt="gift" data-value="1">Вкл</button>
                    <button type="button" class="toggle-btn" data-opt="gift" data-value="0">Выкл</button>
                </div>
            </div>
            <div class="form-group">
                <label>Сертификат "Био" (+150 ₽)</label>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn" data-opt="organic" data-value="1">Вкл</button>
                    <button type="button" class="toggle-btn" data-opt="organic" data-value="0">Выкл</button>
                </div>
            </div>
            <button type="submit" class="btn">Сохранить изменения</button>
            <div id="edit-message-result" style="margin-top:10px;"></div>
        </form>
    </div>
</div>

<!-- Модальное окно подтверждения выхода -->
<div id="confirm-logout-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-logout-confirm">&times;</span>
        <h3>Подтверждение выхода</h3>
        <p>Вы уверены, что хотите выйти из системы?</p>
        <div class="confirm-buttons">
            <button id="confirm-logout-yes" class="btn">Да, выйти</button>
            <button id="confirm-logout-no" class="btn" style="background:#999;">Отмена</button>
        </div>
    </div>
</div>

<!-- Модальное окно информации (для отмены) -->
<div id="info-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-info">&times;</span>
        <h3>Информация</h3>
        <p id="info-message-text"></p>
        <button id="info-ok" class="btn">Закрыть</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ------ Выход с подтверждением ------
    const logoutBtn = document.getElementById('logout-btn');
    const confirmModal = document.getElementById('confirm-logout-modal');
    const closeLogoutConfirm = document.getElementById('close-logout-confirm');
    const confirmYes = document.getElementById('confirm-logout-yes');
    const confirmNo = document.getElementById('confirm-logout-no');

    if (logoutBtn) {
        logoutBtn.onclick = () => confirmModal.classList.add('active');
    }
    function closeConfirmModal() { confirmModal.classList.remove('active'); }
    if (closeLogoutConfirm) closeLogoutConfirm.onclick = closeConfirmModal;
    if (confirmNo) confirmNo.onclick = closeConfirmModal;
    if (confirmYes) {
        confirmYes.onclick = () => { window.location.href = 'logout.php'; };
    }
    window.onclick = (e) => { if (e.target === confirmModal) closeConfirmModal(); };

    // ------ Отмена заказа ------
    const infoModal = document.getElementById('info-modal');
    const infoMessage = document.getElementById('info-message-text');
    const closeInfo = document.getElementById('close-info');
    const infoOk = document.getElementById('info-ok');
    function showInfoMessage(msg) {
        infoMessage.innerText = msg;
        infoModal.classList.add('active');
    }
    function closeInfoModal() { infoModal.classList.remove('active'); }
    if (closeInfo) closeInfo.onclick = closeInfoModal;
    if (infoOk) infoOk.onclick = closeInfoModal;
    window.onclick = (e) => { if (e.target === infoModal) closeInfoModal(); };

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const orderId = btn.dataset.id;
            try {
                const res = await fetch(`index.php?route=cancel&id=${orderId}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await res.json();
                if (res.ok && data.status === 'cancelled') {
                    showInfoMessage('Заказ отменён. Страница будет обновлена.');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showInfoMessage(data.error || 'Не удалось отменить заказ');
                }
            } catch(err) {
                showInfoMessage('Ошибка сети');
            }
        });
    });

    // ------ Редактирование заказа ------
    const editModal = document.getElementById('edit-modal');
    const closeEdit = document.getElementById('close-edit');
    const editForm = document.getElementById('editForm');
    const editMessageResult = document.getElementById('edit-message-result');

    if (closeEdit) closeEdit.onclick = () => editModal.classList.remove('active');
    window.onclick = (e) => { if (e.target === editModal) editModal.classList.remove('active'); };

    async function loadOrderForEdit(orderId) {
        try {
            const res = await fetch(`index.php?route=order&id=${orderId}`);
            const data = await res.json();
            if (data.id) {
                document.getElementById('edit-order-id').value = data.id;
                document.getElementById('edit-quantity').value = data.quantity;
                document.getElementById('edit-message').value = data.message || '';

                const productSelect = document.getElementById('edit-product');
                const products = [
                    {value:'vegetables', label:'Овощи (150 ₽/кг)', price:150},
                    {value:'fruits', label:'Фрукты (300 ₽/кг)', price:300},
                    {value:'milk', label:'Молочные продукты (200 ₽/л)', price:200},
                    {value:'honey', label:'Мёд (400 ₽/бут)', price:400},
                    {value:'cheese', label:'Сыр (500 ₽/кг)', price:500}
                ];
                productSelect.innerHTML = '';
                products.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.value;
                    opt.textContent = p.label;
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

                const giftBtns = document.querySelectorAll('[data-opt="gift"]');
                const organicBtns = document.querySelectorAll('[data-opt="organic"]');
                giftBtns.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.value == (data.gift_wrap ? '1' : '0')) btn.classList.add('active');
                });
                organicBtns.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.value == (data.organic_cert ? '1' : '0')) btn.classList.add('active');
                });

                editModal.classList.add('active');
            } else {
                alert('Не удалось загрузить заказ');
            }
        } catch(e) {
            alert('Ошибка загрузки заказа');
            console.error(e);
        }
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => loadOrderForEdit(btn.dataset.id));
    });

    const optionBtns = document.querySelectorAll('[data-opt]');
    optionBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const opt = btn.dataset.opt;
            const group = document.querySelectorAll(`[data-opt="${opt}"]`);
            group.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
    });

    if (editForm) {
        editForm.onsubmit = async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit-order-id').value;
            const product = document.getElementById('edit-product').value;
            const quantity = parseInt(document.getElementById('edit-quantity').value);
            const delivery = parseInt(document.getElementById('edit-delivery').value);
            const message = document.getElementById('edit-message').value;

            const giftActive = document.querySelector('[data-opt="gift"].active');
            const organicActive = document.querySelector('[data-opt="organic"].active');
            const gift = giftActive && giftActive.dataset.value === '1' ? 1 : 0;
            const organic = organicActive && organicActive.dataset.value === '1' ? 1 : 0;

            const name = <?= json_encode($user['name']) ?>;
            const phone = <?= json_encode($user['phone']) ?>;
            const email = <?= json_encode($user['email']) ?>;
            const consent = true;

            const priceMap = {vegetables:150, fruits:300, milk:200, honey:400, cheese:500};
            const basePrice = priceMap[product];
            const total = (basePrice * quantity) + delivery + (gift?200:0) + (organic?150:0);

            const data = { name, phone, email, message, consent, product, quantity, delivery, gift, organic, total, _method:'PUT' };
            try {
                const res = await fetch(`index.php?route=order&id=${id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                if (res.ok && result.status === 'updated') {
                    editMessageResult.innerText = '✅ Заказ обновлён!';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    editMessageResult.innerText = '❌ Ошибка: ' + (result.error || 'неизвестная');
                }
            } catch(err) {
                editMessageResult.innerText = '❌ Ошибка сети';
            }
        };
    }
});
</script>
</body>
</html>